<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Container;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\CurrencyServiceInterface;
use App\Contracts\CircuitBreakerInterface;

/**
 * AppServiceProvider - ثبت تمام سرویس‌ها در یک مکان
 * 
 * مسئولیت‌ها:
 * ✅ تمام Container bindings (34+) در یک فایل
 * ✅ حذف duplicate code از bootstrap/app.php
 * ✅ استفاده از callable resolver برای PaymentGatewayFactory
 * 
 * مزایا:
 * - تست‌پذیری: می‌توان provider را mock کرد
 * - نگهداری: یک فایل به جای 343 خط در bootstrap
 * - سادگی: بدون over-engineering
 */
class AppServiceProvider
{
    public static function register(Container $container): void
    {
        // ── Core Infrastructure ─────────────────────────────
        $container->singleton(\Core\Container::class, fn() => $container);
        $container->singleton(\Core\Session::class, fn() => \Core\Session::getInstance());
        $container->singleton(
            \Core\Database::class,
            static fn(): \Core\Database => \Core\Database::getInstance()
        );
        $container->singleton(\Core\Redis::class);
        $container->singleton(
            \Core\PathResolver::class,
            static fn(): \Core\PathResolver => new \Core\PathResolver(BASE_PATH)
        );
        $container->singleton(\Core\UrlGenerator::class, static function (): \Core\UrlGenerator {
            $applicationUrl = config('app.url');
            $assetUrl = config('app.asset_url');
            $basePath = config('app.base_path');
            $environment = config('app.env', 'production');

            if (!is_string($applicationUrl)) {
                throw new \RuntimeException('Application URL configuration must be a string.');
            }
            if ($assetUrl !== null && !is_string($assetUrl)) {
                throw new \RuntimeException('Asset URL configuration must be a string or null.');
            }
            if ($basePath !== null && !is_string($basePath)) {
                throw new \RuntimeException('Application base path configuration must be a string or null.');
            }
            if (!is_string($environment)) {
                throw new \RuntimeException('Application environment configuration must be a string.');
            }

            return new \Core\UrlGenerator($applicationUrl, $assetUrl, $basePath, $environment);
        });

        // Canonical path/origin configuration is immutable runtime state. Resolve
        // eagerly so malformed config fails during composition and later mutable
        // config overlays cannot change generated links mid-process.
        $container->make(\Core\PathResolver::class);
        $container->make(\Core\UrlGenerator::class);
        $container->singleton(\Core\Cache::class, static function (\Core\Container $container): \Core\Cache {
            return new \Core\Cache(null, null, $container->make(\Core\PathResolver::class));
        });

        $container->singleton(\Core\EventDispatcher::class);
        $container->singleton(\Core\CircuitBreaker::class);

        // ── Logger ──────────────────────────────────────────
        $container->singleton(\App\Services\LogService::class);
        $container->singleton(LoggerInterface::class, function($c) {
            $logger = new \Core\Logger($c->make(\App\Services\LogService::class));
            if (app()->request->header('x-request-id') && method_exists($logger, 'setExtraContext')) {
                $logger->setExtraContext(['trace_id' => app()->request->header('x-request-id')]);
            }
            return $logger;
        });

        // ── Cache ───────────────────────────────────────────
        $container->singleton(\App\Contracts\CacheInterface::class, function() {
            return new \App\Services\Cache\CacheManager(
                \Core\Cache::getInstance()
            );
        });

        // ── Contracts ───────────────────────────────────────
        $container->singleton(CircuitBreakerInterface::class, \Core\CircuitBreaker::class);
        $container->singleton(\App\Contracts\ValidatorFactoryInterface::class, \App\Services\ValidatorFactory::class);
        $container->singleton(\App\Contracts\AntiFraud\FraudGuardInterface::class, \App\Services\AntiFraud\FraudGuardService::class);

        // 🔒 Anti-Fraud Analytics Engines (graph analysis + ML scoring)
        $container->singleton(\App\Services\AntiFraud\GraphAnalysisService::class);
        $container->singleton(\App\Services\AntiFraud\MLFraudDetectionService::class);
        $container->singleton(\App\Models\VelocityAndScoreModel::class);

        // 🔧 Database analysis tooling
        $container->singleton(\App\Services\DatabaseAnalyzerService::class);
        $container->singleton(WalletServiceInterface::class, \App\Services\Wallet\WalletService::class);
        $container->singleton(CurrencyServiceInterface::class, \App\Services\CurrencyService::class);
        $container->singleton(NotificationServiceInterface::class, \App\Services\Notification\NotificationService::class);
        $container->singleton(\App\Contracts\EmailServiceInterface::class, \App\Services\EmailService::class);
        $container->singleton(\App\Contracts\UploadServiceInterface::class, \App\Services\UploadService::class);
        $container->singleton(\App\Contracts\OutboxServiceInterface::class, \App\Services\OutboxService::class);
        $container->singleton(\App\Contracts\MetricsCollectorInterface::class, \App\Services\Metrics\MetricsCollector::class);

        // ── Adapters ────────────────────────────────────────
        $container->singleton(\App\Adapters\VandarInquiryAdapter::class);
        $container->singleton(\App\Adapters\JibitInquiryAdapter::class);
        
        // تگ کردن آداپتورها برای مدیریت بهتر
        $container->tag([
            \App\Adapters\VandarInquiryAdapter::class,
            \App\Adapters\JibitInquiryAdapter::class
        ], 'bank_inquiry_adapters');

        $container->singleton(\App\Adapters\BankInquiryManager::class, function($c) {
            return new \App\Adapters\BankInquiryManager(
                $c->make(LoggerInterface::class),
                $c->tagged('bank_inquiry_adapters')
            );
        });
        $container->singleton(\App\Adapters\BankInquiryAdapter::class, \App\Adapters\BankInquiryManager::class);
        $container->singleton(\App\Adapters\CryptoVerificationAdapter::class, \App\Adapters\CryptoApiAdapter::class);
        $container->singleton(\App\Adapters\KycFaceVerificationAdapter::class, \App\Adapters\DeepFaceKycAdapter::class);

        // ── Idempotency ─────────────────────────────────────
        $container->singleton(\App\Services\Shared\IdempotencyService::class, function ($c) {
            return new \App\Services\Shared\IdempotencyService(
                $c->make(\Core\IdempotencyKey::class),
                $c->make(\Core\TransactionWrapper::class),
                $c->make(LoggerInterface::class),
                $c->make(\App\Services\DistributedLockService::class)
            );
        });

        // ── Payment Gateway Factory (با Resolver Callback) ──
        $container->singleton(\App\Services\Payment\PaymentGatewayFactory::class, function ($c) {
            return new \App\Services\Payment\PaymentGatewayFactory(
                logger: $c->make(LoggerInterface::class),
                gatewayResolver: fn(string $gateway): \App\Contracts\PaymentGatewayInterface => match ($gateway) {
                    'zarinpal' => $c->make(\App\Services\Payment\ZarinPalGateway::class),
                    'nextpay'  => $c->make(\App\Services\Payment\NextPayGateway::class),
                    'idpay'    => $c->make(\App\Services\Payment\IDPayGateway::class),
                    'dgpay'    => $c->make(\App\Services\Payment\DgPayGateway::class),
                    'mock'     => new \App\Services\Payment\MockPaymentGateway(),
                    default    => throw new \App\Exceptions\PaymentGatewayException("درگاه پرداخت پشتیبانی نمی‌شود: {$gateway}"),
                }
            );
        });

        // ── Payment Services ────────────────────────────────
        $container->singleton(\App\Services\Payment\PaymentCommandService::class, function ($c) {
            return new \App\Services\Payment\PaymentCommandService(
                $c->make(LoggerInterface::class),
                $c->make(\App\Models\PaymentLog::class),
                $c->make(\App\Services\Payment\PaymentGatewayFactory::class),
                $c->make(\App\Services\Shared\IdempotencyService::class),
                $c->make(\Core\Database::class),
                $c->make(WalletServiceInterface::class),
                $c->make(\App\Services\SagaOrchestrator::class),
                $c->make(CircuitBreakerInterface::class),
                $c->make(\Core\RateLimiter::class),
                $c->make(\App\Services\OutboxService::class)
            );
        });

        $container->singleton(\App\Services\Payment\PaymentDepositService::class, function ($c) {
            return new \App\Services\Payment\PaymentDepositService(
                $c->make(LoggerInterface::class),
                $c->make(\Core\Database::class),
                $c->make(WalletServiceInterface::class),
                $c->make(\App\Services\SagaOrchestrator::class)
            );
        });

        $container->singleton(\App\Services\Payment\PaymentAdminService::class);
        $container->singleton(\App\Services\Payment\PaymentService::class);

        // ── Ad System ───────────────────────────────────────
        $container->singleton(\App\Contracts\AdsRepositoryInterface::class, \App\Models\Ads::class);
        $container->singleton(\App\Services\AdSystemManager::class, function($c) {
            $adapters = [];
            foreach ([
                \App\Adapters\AdTubeAdapter::class,
                \App\Adapters\AdSocialAdapter::class,
                \App\Adapters\CustomTaskAdapter::class,
                \App\Adapters\SeoAdAdapter::class,
                \App\Adapters\BannerAdapter::class,
                \App\Adapters\NotificationAdAdapter::class,
            ] as $adapterClass) {
                try {
                    $adapter = $c->make($adapterClass);
                    if ($adapter instanceof \App\Contracts\AdSystemContract) {
                        $adapters[$adapter->getType()] = $adapter;
                    }
                } catch (\Throwable $e) {
                    // Optional ad adapter unavailable; keep boot resilient.
                }
            }

            return new \App\Services\AdSystemManager(
                $c->make(\Core\Database::class),
                $c->make(LoggerInterface::class),
                $adapters,
                $c->make(\App\Contracts\AdsRepositoryInterface::class),
                $c->make(\App\Services\EscrowService::class),
                $c->make(\App\Services\SagaOrchestrator::class),
                $c->make(WalletServiceInterface::class),
                $c->make(\App\Services\Ads\AdsBudgetSettlementService::class)
            );
        });

        // ── Search Service ──────────────────────────────────
        $container->singleton(\App\Contracts\SearchServiceInterface::class, \App\Services\Search\SearchOrchestrator::class);
        $registerSearchProvider = function ($orchestrator, $c) {
            if ($orchestrator instanceof \App\Services\Search\SearchOrchestrator) {
                try {
                    $orchestrator->registerProvider($c->make(\App\Services\Search\AdminSearchProvider::class));
                } catch (\Throwable $e) {
                    // Optional provider; boot must remain resilient.
                }
            }
            return $orchestrator;
        };
        $container->extend(\App\Contracts\SearchServiceInterface::class, $registerSearchProvider);
        $container->extend(\App\Services\Search\SearchOrchestrator::class, $registerSearchProvider);

        // ── Event Listeners ─────────────────────────────────
        $container->singleton(\App\Listeners\ContentEventListeners::class);
        $container->singleton(\App\Listeners\CreateUserWalletListener::class);
        $container->singleton(\App\Listeners\ReferralCommissionListener::class);
        $container->singleton(\App\Listeners\InfluencerEventListeners::class);
        $container->singleton(\App\Listeners\InvestmentEventListeners::class);
        $container->singleton(\App\Listeners\VitrineEventListeners::class);
        $container->singleton(\App\Listeners\WithdrawalListener::class);
        $container->singleton(\App\Listeners\ScoreUpdateListener::class);
        $container->singleton(\App\Listeners\ScoreProjectionListener::class);
        $container->singleton(\App\Listeners\EscrowListener::class);
        $container->singleton(\App\Listeners\HandlePaymentCompleted::class);
        $container->singleton(\App\Listeners\HandleLevelUpgraded::class);
        $container->singleton(\App\Listeners\ClearSettingsCache::class);
        // 🛡️ FIX-ALERT-LISTENER: ثبت AlertRequestListener به‌عنوان singleton
        // (EventDispatcher فقط اگر Container->has() true باشد، با وابستگی‌ها می‌سازد؛
        //  در غیر این صورت new مستقیم می‌کند و وابستگی‌ها از دست می‌روند.)
        $container->singleton(\App\Listeners\AlertRequestListener::class);
        $contentEvents = $container->make(\Core\EventDispatcher::class);
            $contentEvents->listen('content.revenue.payment_recorded', [\App\Listeners\ContentEventListeners::class, 'handleContentRevenuePaymentRecorded']);
            $contentEvents->listen('content.revenue_paid', [\App\Listeners\ContentEventListeners::class, 'handleContentRevenuePaid']);
                        $contentEvents->listen('content.approved', [\App\Listeners\ContentEventListeners::class, 'handleContentApproved']);
                        $contentEvents->listen('influencer_order.completed', [\App\Listeners\InfluencerEventListeners::class, 'handleInfluencerOrderCompleted']);
                        $contentEvents->listen('influencer_order.refunded', [\App\Listeners\InfluencerEventListeners::class, 'handleInfluencerOrderRefunded']);
                        $contentEvents->listen('investment.profit_applied', [\App\Listeners\InvestmentEventListeners::class, 'handleInvestmentProfitApplied']);
                        $contentEvents->listen(\App\Events\WithdrawalCreatedEvent::class, [\App\Listeners\WithdrawalListener::class, 'handleWithdrawalCreated']);
                        $contentEvents->listen(\App\Events\WithdrawalApprovedEvent::class, [\App\Listeners\WithdrawalListener::class, 'handleWithdrawalApproved']);
                        $contentEvents->listen('score.updated', [\App\Listeners\ScoreUpdateListener::class, 'handle']);
                        $contentEvents->listen('score.delta_appended', [\App\Listeners\ScoreProjectionListener::class, 'handle']);
                        $contentEvents->listen('escrow.released', [\App\Listeners\EscrowListener::class, 'handle']);
                        $contentEvents->listen('payment.completed', [\App\Listeners\HandlePaymentCompleted::class, 'handle']);
                        // L-29 Fix: اتصال listener ارتقای سطح تا اعلان/بج/audit پس از dispatch اجرا شود.
                        $contentEvents->listen(\App\Events\LevelUpgradedEvent::class, [\App\Listeners\HandleLevelUpgraded::class, 'handle']);
                        $contentEvents->listen(\App\Events\SettingsUpdated::class, [\App\Listeners\ClearSettingsCache::class, 'handle']);

        // ── Auth & Others ───────────────────────────────────
        $container->singleton(\App\Services\Auth\AuthService::class);

        // 🛡️ FIX-SENTRY-INIT: راه‌اندازی SentryExceptionHandler شخصی
        // ─────────────────────────────────────────────────────────────────────
        // کشف‌شده: کلاس SentryExceptionHandler هرگز ساخته یا setInstance نمی‌شد،
        // در نتیجه تمام گزارش‌های خطا/کوئری (در Database، BackupService،
        // QueueWorker، SystemMonitoringService و غیره) ساکتاً رها می‌شدند.
        //
        // این بلوک:
        //   ۱) زنجیره‌ی وابستگی را دستی می‌سازد تا از circular dependency
        //      (SentryModel ↔ AlertDispatcher) جلوگیری شود.
        //   ۲) تحت try/catch fail-safe قرار دارد: هرگز boot را خراب نمی‌کند.
        //      اگر Sentry به دیتابیس دسترسی نداشت، فقط warning لاگ می‌شود
        //      و برنامه معمولی راه‌اندازی می‌شود.
        //   ۳) فقط در محیط غیر-test فعال می‌شود (PHPUnit خودش handler می‌سازد).
        try {
            if (!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')) {
                // مرتبه‌ی ثبت: ابتدا SentryModel (نیازمند AppSettings، Core\Queue، Logger، Database)
                $sentryModel = new \App\Models\SentryModel(
                    $container->make(\Core\Database::class),
                    $container->make(LoggerInterface::class),
                    $container->make(\App\Services\Settings\AppSettings::class),
                    $container->make(\Core\Queue::class),
                );

                // سپس AlertDispatcher (همان model را پاس می‌دهیم تا circular حل شود)
                $alertDispatcher = new \App\Services\Sentry\Alerting\AlertDispatcher(
                    $sentryModel,
                    $container->make(LoggerInterface::class),
                    $container->make(\Core\EventDispatcher::class),
                    $container->make(\App\Services\Settings\AppSettings::class),
                );

                // دو مانیتور اصلی
                $errorMonitor = new \App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor(
                    $sentryModel,
                    $alertDispatcher,
                    $container->make(\App\Contracts\CacheInterface::class),
                );
                $performanceMonitor = new \App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor(
                    $sentryModel,
                    $container->make(\Core\Logger::class),
                    $alertDispatcher,
                );

                // Handler نهایی — CacheInterface برای Circuit Breaker چندپردازشی (Redis/APCu)
                $sentryHandler = new \App\Services\Sentry\SentryExceptionHandler(
                    $errorMonitor,
                    $performanceMonitor,
                    $container->make(\Core\Logger::class),
                    $container->make(\Core\Session::class),
                    $container->make(\App\Contracts\CacheInterface::class),
                );

                // ثبت static instance (تا صدا‌زننده‌های static کار کنند)
                \App\Services\Sentry\SentryExceptionHandler::setInstance($sentryHandler);

                // ثبت در Container برای واکشی بعدی
                $container->instance(\App\Services\Sentry\SentryExceptionHandler::class, $sentryHandler);

                // فعال‌سازی error/exception/shutdown handlers
                $sentryHandler->register();

                // 🛡️ FIX-ALERT-LISTENER: ثبت AlertRequestListener روی 'alert.requested'
                // ─────────────────────────────────────────────────────────────────
                // کشف‌شده: AlertRequestListener وجود دارد ولی هیچ‌جا subscribe نشده بود.
                // در نتیجه AlertDispatcher::dispatch() همیشه به alert.no_listeners
                // می‌رسید و اعلان بحرانی‌ها (Telegram/Email/Slack) ساکتاً رها می‌شد.
                //
                // این ثبت داخل همون try/catch fail-safe والد است؛ اگر AlertDispatcher
                // در دسترس نباشد، فقط warning لاگ می‌شود و boot ادامه می‌یابد.
                try {
                    $dispatcher = $container->make(\Core\EventDispatcher::class);
                    $dispatcher->listen(
                        'alert.requested',
                        [\App\Listeners\AlertRequestListener::class, 'handle']
                    );
                    // همچنین برای AlertRequestedEvent کلاس‌محور (اگر کسی با کلاس dispatch کند)
                    $dispatcher->listen(
                        \App\Events\AlertRequestedEvent::class,
                        [\App\Listeners\AlertRequestListener::class, 'handle']
                    );
                } catch (\Throwable $listenerEx) {
                    // best-effort: alerting غیربحرانی است
                    @error_log('[Chortke] AlertRequestListener registration failed: ' . $listenerEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe: اگر Sentry به‌هر دلیل initialize نشد، boot ادامه می‌یابد.
            // تمام صدا‌زننده‌های static (`captureException`, `trackQuery` و غیره)
            // با try/catch داخلی خود محافظت شده‌اند و فقط ساکت رها می‌شوند.
            try {
                if (function_exists('error_log')) {
                    error_log('[Chortke] SentryExceptionHandler initialization failed: ' . $e->getMessage());
                }
            } catch (\Throwable) { /* intentional: listener registration is best-effort */ }
        }

        // ── Validator Factory (Constructor Injection for Database) ──
        \App\Validators\BaseFormRequest::setValidatorFactory(
            $container->make(\App\Contracts\ValidatorFactoryInterface::class)
        );
    }
}
