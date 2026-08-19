<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Exceptions\ValidationException;
use App\Services\Settings\AppSettings;

/**
 * AdapterBase - پایه مشترک برای همه آداپترهای خارجی
 *
 * وظایف:
 * - تزریق LoggerInterface
 * - تزریق SettingService
 * - استانداردسازی response shape
 * - wrapper برای validation
 * - logging امن و یکپارچه
 */
abstract class AdapterBase
{
    use \App\Traits\ExternalCallTrait;

    protected LoggerInterface $logger;
    protected AppSettings $appSettings;
    protected ?ValidatorFactoryInterface $validatorFactory;
    protected ?\App\Contracts\AntiFraud\FraudGuardInterface $fraudGuard;

    public function __construct(
        LoggerInterface $logger,
        AppSettings $appSettings,
        ?ValidatorFactoryInterface $validatorFactory = null,
        ?\App\Contracts\AntiFraud\FraudGuardInterface $fraudGuard = null
    ) {
        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->validatorFactory = $validatorFactory;
        $this->fraudGuard = $fraudGuard;
    }

    /**
     * Wrapper استاندارد برای validation
     * اکنون می‌تواند هم فلگ bool (برود به validate فرزند) و هم یک آرایه مستقیم rules بگیرد.
     * @param array<string, mixed> $data
     * @param bool|array<string, mixed> $rulesOrUpdate
     */
    protected function validateData(array $data, mixed $rulesOrUpdate = false): void
    {
        // 1. اگر مستقیماً آرایه‌ای از Rules ارسال شده، خودکار با Validator سیستمی ولیدیت کن
        if (is_array($rulesOrUpdate)) {
            $validator = $this->validatorFactory
                ? $this->validatorFactory->make($data, $rulesOrUpdate)
                : new \Core\Validator($data, $rulesOrUpdate);
            if (!$validator->passes()) {
                $validationErrors = $validator->errors();
                throw new ValidationException(is_array($validationErrors) ? $validationErrors : []);
            }
            return;
        }

        // 2. وگرنه از سیستم کلاس فرزند استفاده کن
        $result = $this->validate($data, (bool)$rulesOrUpdate);
        if (!$result['valid']) {
            $errors = $result['errors'] ?? ['دیتا نامعتبر است.'];
            throw new ValidationException(is_array($errors) ? $errors : ['دیتا نامعتبر است.']);
        }
    }

    /**
     * Response shape استاندارد برای موفقیت
     * @param array<string, mixed> $data
     * @return array{success: true, message: string, data: array<string, mixed>}
     */
    protected function successResponse(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * Response shape استاندارد برای خطا
     * @return array{success: false, message: string, errors: array<int|string, mixed>}
     */
    protected function errorResponse(string $message, mixed $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => is_array($errors) ? $errors : [$errors],
        ];
    }

    /**
     * Logging استاندارد برای عملیات موفق
     * @param array<string, mixed> $context
     */
    protected function logSuccess(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.success", $context);
    }

    /**
     * Logging استاندارد اطلاعات عمومی (مورد نیاز فرزندان)
     * @param array<string, mixed> $context
     */
    protected function logInfo(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.info", $context);
    }

    /**
     * Logging استاندارد برای خطا
     * @param array<string, mixed> $context
     */
    protected function logError(string $operation, string $error, array $context = []): void
    {
        $this->logger->error("adapter.{$this->getType()}.{$operation}.failed", array_merge($context, ['error' => $error]));
    }

    /**
     * Logging استاندارد برای عملیات شروع
     * @param array<string, mixed> $context
     */
    protected function logStart(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.started", $context);
    }

    /**
     * متد abstract برای نوع آداپتر
     */
    abstract public function getType(): string;

    /**
     * متد abstract برای validation
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    abstract public function validate(array $data, bool $isUpdate = false): array;

    /**
     * 🛡️ Fraud Policy Guard — بررسی ضدتقلب قبل از عملیات مالی
     *
     * طبق معماری مورد توافق: Application Layer مسئول اجرای Policy هست.
     * Wallet فقط Financial Engine خالص خواهد بود.
     *
     * @throws \App\Exceptions\BusinessException اگه fraud blocked بشه
     * @param array<string, mixed> $context
     */
    protected function assertFraudAllowed(int $userId, string $action, array $context = []): void
    {
        try {
            if ($this->fraudGuard === null) {
                $this->logger->warning("adapter.{$this->getType()}.fraud_check_unavailable", [
                    'user_id' => $userId,
                    'reason' => 'fraud_guard_not_injected',
                ]);
                return;
            }
            $risk = $this->fraudGuard->checkAction($userId, $action, $context);

            if (empty($risk['allowed'])) {
                $reason = $risk['reason'] ?? 'security_block';
                $this->logger->warning("adapter.{$this->getType()}.fraud_blocked", [
                    'user_id' => $userId,
                    'action' => $action,
                    'reason' => $reason,
                ]);
                throw new \App\Exceptions\BusinessException(
                    'عملیات مالی به دلیل محدودیت‌های امنیتی مجاز نیست.'
                );
            }
        } catch (\App\Exceptions\BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Fail-open: اگه FraudGuard در دسترس نبود، اجازه بده (safety net در Wallet هنوز هست)
            $this->logger->warning("adapter.{$this->getType()}.fraud_check_unavailable", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

