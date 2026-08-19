<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Services\Settings\AppSettings;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\ValueObjects\Money;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * قرارداد پایه آداپتورهای تبلیغات ویدیویی جایزه‌دار (Rewarded Video Interface)
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface AdVideoRewardAdapterInterface
{
    public function getNetworkName(): string;
    public function getCurrency(): string;
    public function getBaseRate(): float;
    public function getUserSharePercent(): float;
    public function calculateUserPayout(): Money;
    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool;
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 1️⃣ آداپتور تبلیغات بومی تپسل (Tapsell) — واحد پولی: تومان (IRT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TapsellVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'tapsell'; }
    public function getCurrency(): string { return 'IRT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('tapsell_base_rate_irt', 150.0); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('tapsell_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 4);
        $payoutClean = bcdiv($payoutValue, '1', 0);
        return Money::fromString($payoutClean, 'IRT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.tapsell.webhook_secret', $this->settings->get('tapsell_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('tapsell.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('tapsell.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('tapsell.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 2️⃣ آداپتور تبلیغات بومی ادیوری (Adivery) — واحد پولی: تومان (IRT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class AdiveryVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'adivery'; }
    public function getCurrency(): string { return 'IRT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('adivery_base_rate_irt', 140.0); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('adivery_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 4);
        $payoutClean = bcdiv($payoutValue, '1', 0);
        return Money::fromString($payoutClean, 'IRT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.adivery.webhook_secret', $this->settings->get('adivery_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('adivery.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('adivery.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('adivery.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 3️⃣ آداپتور تبلیغات بومی صباویژن (SabaVision) — واحد پولی: تومان (IRT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SabaVisionVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'sabavision'; }
    public function getCurrency(): string { return 'IRT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('sabavision_base_rate_irt', 160.0); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('sabavision_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 4);
        $payoutClean = bcdiv($payoutValue, '1', 0);
        return Money::fromString($payoutClean, 'IRT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.sabavision.webhook_secret', $this->settings->get('sabavision_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('sabavision.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('sabavision.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('sabavision.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 4️⃣ آداپتور تبلیغات ارزی ادموب (AdMob) — واحد پولی: تتر (USDT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class AdMobVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'admob'; }
    public function getCurrency(): string { return 'USDT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('admob_base_rate_usdt', 0.02); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('admob_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 8);
        $payoutClean = bcdiv($payoutValue, '1', 4);
        return Money::fromString($payoutClean, 'USDT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.admob.webhook_secret', $this->settings->get('admob_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('admob.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('admob.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('admob.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 5️⃣ آداپتور تبلیغات ارزی یونیتی (Unity Ads) — واحد پولی: تتر (USDT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class UnityVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'unity'; }
    public function getCurrency(): string { return 'USDT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('unity_base_rate_usdt', 0.025); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('unity_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 8);
        $payoutClean = bcdiv($payoutValue, '1', 4);
        return Money::fromString($payoutClean, 'USDT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.unity.webhook_secret', $this->settings->get('unity_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('unity.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('unity.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('unity.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 6️⃣ آداپتور تبلیغات ارزی اپ‌لاوین (AppLovin MAX) — واحد پولی: تتر (USDT)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class AppLovinVideoRewardAdapter implements AdVideoRewardAdapterInterface
{
    private AppSettings $settings;
    private LoggerInterface $logger;

    public function __construct(AppSettings $settings, LoggerInterface $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getNetworkName(): string { return 'applovin'; }
    public function getCurrency(): string { return 'USDT'; }
    public function getBaseRate(): float { return $this->settings->getFloat('applovin_base_rate_usdt', 0.018); }
    public function getUserSharePercent(): float { return $this->settings->getFloat('applovin_user_share', 70.0); }

    public function calculateUserPayout(): Money
    {
        $base = $this->getBaseRate();
        $share = $this->getUserSharePercent();
        $payoutValue = bcmul((string)$base, (string)($share / 100), 8);
        $payoutClean = bcdiv($payoutValue, '1', 4);
        return Money::fromString($payoutClean, 'USDT');
    }

    /** @param array<string, mixed> $payload */
    public function verifyS2SHmac(array $payload, string $receivedSignature): bool
    {
        $secret = config('services.applovin.webhook_secret', $this->settings->get('applovin_webhook_secret', ''));
        if (!is_string($secret) || trim($secret) === '' || trim($receivedSignature) === '') {
            $this->logger->warning('applovin.webhook.hmac_configuration_invalid');
            return false;
        }
        $dataString = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($dataString)) {
            $this->logger->warning('applovin.webhook.payload_encoding_failed');
            return false;
        }
        $calculated = hash_hmac('sha256', $dataString, $secret);
        $match = hash_equals($calculated, $receivedSignature);
        if (!$match) $this->logger->warning('applovin.webhook.hmac_mismatch');
        return $match;
    }
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * هسته مرکزی مدیریت آداپتورها (AdVideoRewardManager)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class AdVideoRewardManager
{
    /** @var array<string, AdVideoRewardAdapterInterface> */
    private array $adapters = [];
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, AppSettings $settings) {
        $this->logger = $logger;
        // ثبت خودکار آداپتورهای بومی (تومانی) و بین‌المللی (تتری)
        $this->adapters['tapsell']    = new TapsellVideoRewardAdapter($settings, $logger);
        $this->adapters['adivery']    = new AdiveryVideoRewardAdapter($settings, $logger);
        $this->adapters['sabavision'] = new SabaVisionVideoRewardAdapter($settings, $logger);
        $this->adapters['admob']      = new AdMobVideoRewardAdapter($settings, $logger);
        $this->adapters['unity']      = new UnityVideoRewardAdapter($settings, $logger);
        $this->adapters['applovin']   = new AppLovinVideoRewardAdapter($settings, $logger);
    }

    public function getAdapter(string $network): AdVideoRewardAdapterInterface
    {
        $network = strtolower(trim((string)$network));
        if (!isset($this->adapters[$network])) {
            throw new \InvalidArgumentException("آداپتور تبلیغاتی برای شبکه '{$network}' یافت نشد.");
        }
        return $this->adapters[$network];
    }

    /**
     * دریافت لیست پاداش‌های در دسترس جهت نمایش در هاب کاربری
     */
    /** @return array<string, mixed> */
    public function getAvailableRewardAds(): array
    {
        $ads = [];
        $meta = [
            'tapsell'    => ['title' => 'کمپین تبلیغاتی تپسل (Tapsell)', 'desc' => 'تماشای ویدیوهای تبلیغاتی کوتاه بومی و دریافت آنی پاداش تومانی'],
            'adivery'    => ['title' => 'شبکه هوشمند ادیوری (Adivery)', 'desc' => 'پخش هوشمند سودآورترین ویدیوهای تبلیغاتی بومی با تسویه تومانی'],
            'sabavision' => ['title' => 'شبکه تبلیغاتی صباویژن (SabaVision)', 'desc' => 'تماشای تیزرهای ویدیویی اختصاصی برندهای برتر ایران و کسب درآمد تومانی'],
            'admob'      => ['title' => 'تبلیغات بین‌المللی ادموب (AdMob)', 'desc' => 'تماشای ویدیوهای تبلیغاتی خارجی و دریافت پاداش ارزی بر پایه تتر (USDT)'],
            'unity'      => ['title' => 'شبکه دلاری یونیتی (Unity Ads)', 'desc' => 'پخش تیزر بازی‌های ویدیویی بین‌المللی و کسب درآمد تتری با کیفیت HD'],
            'applovin'   => ['title' => 'شبکه ارزی اپ‌لاوین (AppLovin MAX)', 'desc' => 'تماشای تبلیغات ثانیه‌ای پربازده بین‌المللی با تسویه سریع تتری'],
        ];

        foreach ($this->adapters as $network => $adapter) {
            try {
                $ads[$network] = [
                    'network'            => $network,
                    'currency'           => $adapter->getCurrency(),
                    'base_rate'          => $adapter->getBaseRate(),
                    'user_share_percent' => $adapter->getUserSharePercent(),
                    'payout_amount'      => $adapter->calculateUserPayout()->getAmount(),
                    'title'              => $meta[$network]['title'],
                    'description'        => $meta[$network]['desc'],
                    'duration_seconds'   => 15,
                    'is_active'          => true
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('video_reward.manager.calculate_payout_failed', ['network' => $network, 'error' => $e->getMessage()]);
            }
        }
        return $ads;
    }
}
