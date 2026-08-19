<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\SeoExecution;
use App\Models\IpAndDeviceModel;
use App\Contracts\LoggerInterface;
/**
 * SeoFraudDetector — تشخیص تقلب در تعاملات SEO
 */
class SeoFraudDetector
{
    private SeoExecution $executionModel;
    private IpAndDeviceModel $model;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        SeoExecution $executionModel,
        IpAndDeviceModel $model
    ) {        $this->logger = $logger;

        $this->executionModel = $executionModel;
        $this->model = $model;
    }

    /**
     * بررسی جامع تقلب
     */
    /**
     * @param array<string, mixed> $engagementData
     * @return array{is_fraud: bool, flags: list<string>, risk_score: int, details: array<string, mixed>}
     */
    public function detect(int $userId, int $adId, array $engagementData): array
    {
        // 🛡️ M-32 FIX: previously each sub-check silently returned a CLEAN pass on error
        // (checkFingerprint swallowed exceptions; checkIP/checkRepetition/checkVelocity had no
        // try/catch at all, so a single DB hiccup either zeroed the signal or blew up the whole
        // detector inconsistently). A fraud detector that fails silently open lets ad-engagement
        // fraud through undetected. Now every check is wrapped uniformly and a check that could
        // NOT be evaluated contributes a bounded "indeterminate" risk (+10, below any single real
        // flag so one transient error alone will not auto-cross the 50 threshold) plus an explicit
        // degraded-detection flag, making outages visible instead of invisible.
        $flags = [];
        $riskScore = 0;
        $degraded = 0;

        $fingerprintCheck = $this->checkFingerprint($userId);
        if (isset($fingerprintCheck['error'])) {
            $degraded++;
            $flags[] = 'fingerprint_check_degraded';
        } elseif ($fingerprintCheck['suspicious']) {
            $flags[] = $fingerprintCheck['reason'];
            $riskScore += 25;
        }

        $ipCheck = $this->checkIP($userId);
        if (isset($ipCheck['error'])) {
            $degraded++;
            $flags[] = 'ip_check_degraded';
        } elseif ($ipCheck['suspicious']) {
            $flags[] = $ipCheck['reason'];
            $riskScore += 20;
        }

        $behaviorCheck = $this->checkBehaviorPattern($engagementData);
        if ($behaviorCheck['suspicious'] && is_array($behaviorCheck['reasons'] ?? null)) {
            $flags = array_merge($flags, $behaviorCheck['reasons']);
            $riskScore += $behaviorCheck['risk_score'];
        }

        $repetitionCheck = $this->checkRepetition($userId, $adId);
        if (isset($repetitionCheck['error'])) {
            $degraded++;
            $flags[] = 'repetition_check_degraded';
        } elseif ($repetitionCheck['suspicious']) {
            $flags[] = $repetitionCheck['reason'];
            $riskScore += 15;
        }

        $velocityCheck = $this->checkVelocity($userId);
        if (isset($velocityCheck['error'])) {
            $degraded++;
            $flags[] = 'velocity_check_degraded';
        } elseif ($velocityCheck['suspicious']) {
            $flags[] = $velocityCheck['reason'];
            $riskScore += 20;
        }

        // Each degraded check adds a bounded indeterminate signal (capped at 30 total).
        if ($degraded > 0) {
            $riskScore += min(30, $degraded * 10);
        }

        $isFraud = $riskScore >= 50;

        return [
            'is_fraud' => $isFraud,
            'flags' => $flags,
            'risk_score' => min(100, $riskScore),
            'details' => [
                'fingerprint' => $fingerprintCheck,
                'ip' => $ipCheck,
                'behavior' => $behaviorCheck,
                'repetition' => $repetitionCheck,
                'velocity' => $velocityCheck,
            ]
        ];
    }

    /** @return array<string, mixed> */
    private function checkFingerprint(int $userId): array
    {
        try {
            $deviceCount = $this->model->getDeviceCountLast7Days($userId);
            
            if ($deviceCount > 5) {
                return [
                    'suspicious' => true,
                    'reason' => 'استفاده از دستگاه‌های متعدد',
                    'device_count' => $deviceCount,
                ];
            }

            return ['suspicious' => false];
        } catch (\Exception $e) {
            $this->logger->error('seo_fraud.fingerprint_check_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.seoFraud.checkFingerprint']);
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkIP(int $userId): array
    {
        try {
            $ip = get_client_ip();

            if ($this->isVpnOrProxy($ip)) {
                return [
                    'suspicious' => true,
                    'reason' => 'استفاده از VPN/Proxy',
                    'ip' => $ip,
                ];
            }

            $ipCount = $this->model->getIPCountLast24Hours($userId);

            if ($ipCount > 3) {
                return [
                    'suspicious' => true,
                    'reason' => 'IP های متعدد در 24 ساعت',
                    'ip_count' => $ipCount,
                ];
            }

            return ['suspicious' => false];
        } catch (\Throwable $e) {
            $this->logger->error('seo_fraud.ip_check_failed', ['error' => $e->getMessage()]);
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function checkBehaviorPattern(array $data): array
    {
        $suspicious = false;
        $reasons = [];
        $riskScore = 0;

        $duration = float_value($data['duration'] ?? 0);
        $score = float_value($data['final_score'] ?? 0);
        
        // ۱. سرعت عمل غیرطبیعی
        if ($duration < 30 && $score > 80) {
            $suspicious = true;
            $reasons[] = 'امتیاز بالا در زمان خیلی کوتاه';
            $riskScore += 30;
        }

        $interactions = int_value($data['interactions'] ?? 0);
        if ($interactions === 0 && $duration > 60) {
            $suspicious = true;
            $reasons[] = 'عدم تعامل با حضور طولانی';
            $riskScore += 25;
        }

        // ۲. محاسبه انحراف معیار و آنتروپی زمانی کلیک‌ها و رفتارها
        $behaviorValue = $data['behavior'] ?? [];
        if (!is_array($behaviorValue)) {
            throw new \InvalidArgumentException('SEO behavior must be an array.');
        }
        $behavior = $behaviorValue;
        $clickTimings = $this->numericList($behavior['click_timings'] ?? [], 'click_timings');
        if (!empty($clickTimings) && count($clickTimings) >= 3) {
            $intervals = [];
            for ($i = 1; $i < count($clickTimings); $i++) {
                $intervals[] = $clickTimings[$i] - $clickTimings[$i - 1];
            }
            // محاسبه میانگین و انحراف معیار فواصل زمانی
            $mean = array_sum($intervals) / count($intervals);
            $variance = 0.0;
            foreach ($intervals as $val) {
                $variance += pow($val - $mean, 2);
            }
            $stdDev = sqrt($variance / count($intervals));

            // اگر انحراف معیار به شکل ربات‌گونه‌ای بسیار کوچک باشد (زیر ۵ میلی‌ثانیه یعنی فواصل تکراری بی‌نقص)
            if ($stdDev < 0.005) {
                $suspicious = true;
                $reasons[] = 'فواصل زمانی کلیک‌ها غیرطبیعی و کاملاً منظم (ربات)';
                $riskScore += 35;
            }
        }

        // ۳. سرعت و شتاب حرکت موس
        $mouseSpeeds = $this->numericList($behavior['mouse_speeds'] ?? [], 'mouse_speeds');
        if (!empty($mouseSpeeds) && count($mouseSpeeds) >= 4) {
            $meanSpeed = array_sum($mouseSpeeds) / count($mouseSpeeds);
            $varSpeed = 0.0;
            foreach ($mouseSpeeds as $s) {
                $varSpeed += pow($s - $meanSpeed, 2);
            }
            $stdDevSpeed = sqrt($varSpeed / count($mouseSpeeds));

            // نوسان سرعت حرکت انسان همیشه بالاست؛ نوسان ثابت یعنی حرکت خطی ربات
            if ($stdDevSpeed < 1.0) {
                $suspicious = true;
                $reasons[] = 'الگوی سرعت حرکت موس خطی و بدون شتاب طبیعی';
                $riskScore += 30;
            }
        }

        // ۴. بررسی اسکرول خطی (Linear Scrolling Momentum)
        $scrollPattern = $behavior['scroll_pattern'] ?? 'natural';
        if (!is_string($scrollPattern)) throw new \InvalidArgumentException('scroll_pattern must be a string.');
        if ($scrollPattern === 'linear') {
            $suspicious = true;
            $reasons[] = 'اسکرول خطی و بدون فیزیک حرکتی طبیعی';
            $riskScore += 20;
        }

        // ۵. نسبت رویدادهای کیبورد به موس
        $mouseEvents = int_value($behavior['mouse_events_count'] ?? 0);
        $keyEvents = int_value($behavior['key_events_count'] ?? 0);
        if ($keyEvents > 0 && $mouseEvents === 0) {
            $suspicious = true;
            $reasons[] = 'تعامل صرفاً کیبوردی بدون هیچ رویداد موس';
            $riskScore += 15;
        }

        return [
            'suspicious' => $suspicious || ($riskScore >= 40),
            'reasons' => $reasons,
            'risk_score' => min($riskScore, 100),
        ];
    }

    /** @return list<float> */
    private function numericList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item) && !is_float($item) && !(is_string($item) && is_numeric($item))) {
                throw new \InvalidArgumentException("{$field} must contain only numeric values.");
            }
            $result[] = float_value($item);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function checkRepetition(int $userId, int $adId): array
    {
        try {
            if ($this->executionModel->existsByAdAndUserToday($adId, $userId)) {
                return [
                    'suspicious' => true,
                    'reason' => 'تلاش برای اجرای مجدد در یک روز',
                ];
            }

            return ['suspicious' => false];
        } catch (\Throwable $e) {
            $this->logger->error('seo_fraud.repetition_check_failed', ['error' => $e->getMessage()]);
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkVelocity(int $userId): array
    {
        try {
            $hourlyCount = $this->executionModel->countByUserLastHour($userId);

            if ($hourlyCount > 10) {
                return [
                    'suspicious' => true,
                    'reason' => 'تعداد درخواست بیش از حد در ساعت',
                    'hourly_count' => $hourlyCount,
                ];
            }

            return ['suspicious' => false];
        } catch (\Throwable $e) {
            $this->logger->error('seo_fraud.velocity_check_failed', ['error' => $e->getMessage()]);
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    private function isVpnOrProxy(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        
        // ۱) Blocklist قابل‌مدیریت ادمین از رنج‌های دیتاسنتر/VPN (CIDR، جداشده با کاما).
        $rawList = str_value(setting('seo_fraud_vpn_cidr_blocklist', ''));
        if ($rawList !== '') {
            foreach (explode(',', $rawList) as $cidr) {
                $cidr = trim($cidr);
                if ($cidr !== '' && $this->ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
        }

        // ۲) هدرهای افشاگر پروکسیِ رو‌به‌جلو. پیش‌فرض خاموش تا روی CDN خود پلتفرم false-positive نشود.
        if (filter_var(setting('seo_fraud_proxy_header_detection', false), FILTER_VALIDATE_BOOLEAN)) {
            foreach (['HTTP_VIA', 'HTTP_FORWARDED', 'HTTP_PROXY_CONNECTION', 'HTTP_X_PROXY_ID', 'HTTP_X_FORWARDED'] as $header) {
                if (!empty($_SERVER[$header])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * بررسی قرارگیری IP در یک رنج CIDR (پشتیبانی IPv4 و IPv6).
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            $ipPacked = @inet_pton($ip);
            $cidrPacked = @inet_pton($cidr);
            return $ipPacked !== false && $cidrPacked !== false && $ipPacked === $cidrPacked;
        }

        [$subnet, $maskStr] = explode('/', $cidr, 2);
        if (!ctype_digit($maskStr)) {
            return false;
        }
        $mask = (int)$maskStr;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // نسخهٔ IP (v4/v6) ناهمخوان
        }

        $maxBits = strlen($ipBin) * 8;
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($mask, 8);
        $remainderBits = $mask % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $maskByte = ~(0xff >> $remainderBits) & 0xff;
        return (ord($ipBin[$fullBytes]) & $maskByte) === (ord($subnetBin[$fullBytes]) & $maskByte);
    }

    public function addToBlacklist(int $userId, string $reason): bool
    {
        return $this->model->addToBlacklist($userId, $reason);
    }

    public function isBlacklisted(int $userId): bool
    {
        return $this->model->isUserBlacklisted($userId);
    }

    /** @param list<float> $history */
    public function smoothScore(float $score, array $history): float
    {
        if (count($history) < 3) {
            return $score * 0.8;
        }

        $avgScore = array_sum(array_column($history, 'final_score')) / count($history);
        
        if ($score > $avgScore + 30) {
            return min($score, $avgScore + 20);
        }

        return $score;
    }
}

