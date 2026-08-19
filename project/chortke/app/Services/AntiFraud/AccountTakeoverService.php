<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\Session;

/**
 * @phpstan-type Detection array{is_takeover: bool, risk_score: int, signals: list<string>, action: string}
 * @phpstan-type SignalCheck array{suspicious: bool, signal: string}
 * @phpstan-type NewSignalCheck array{is_new: bool}
 */
class AccountTakeoverService
{
    private VelocityAndScoreModel $model;
    private SessionAnomalyService $sessionAnomaly;
    private IPQualityService $ipQuality;
    private RiskPolicyService $policy;
    private BrowserFingerprintService $fingerprintService;
    private Session $session;
    private GeoIPService $geoIPService;
    private ?\App\Models\IpAndDeviceModel $ipAndDeviceModel;
    private \App\Contracts\LoggerInterface $logger;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model,
        SessionAnomalyService $sessionAnomaly,
        IPQualityService $ipQuality,
        RiskPolicyService $policy,
        BrowserFingerprintService $fingerprintService,
        Session $session,
        GeoIPService $geoIPService,
        \App\Models\IpAndDeviceModel $ipAndDeviceModel
    ) {        $this->logger = $logger;

                $this->model = $model;
        $this->sessionAnomaly = $sessionAnomaly;
        $this->ipQuality = $ipQuality;
        $this->policy = $policy;
        $this->fingerprintService = $fingerprintService;
        $this->session = $session;
        $this->geoIPService = $geoIPService;
        $this->ipAndDeviceModel = $ipAndDeviceModel;
    }

    /** @return Detection */
    public function detect(int $userId, string $ip, string $userAgent, ?string $fingerprint = null): array
    {
        // MED-07: اعتبارسنجی IP بر اساس فرمت معتبر
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->logger->warning('takeover.invalid_ip', [
                'raw_ip' => $ip,
                'user_id' => $userId,
                'user_agent' => $userAgent
            ]);
            $ip = '0.0.0.0'; // مقدار خنثی
        }

        $this->logger->info('takeover.detect.started', [
            'user_id' => $userId,
            'ip' => $ip
        ]);
        
        $riskScore = 0;
        $signals = [];

        $passwordCheck = $this->checkRecentPasswordChange($userId);
        if ($passwordCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.password_change_points', 40);
            $signals[] = $passwordCheck['signal'];
        }

        $emailCheck = $this->checkRecentEmailChange($userId);
        if ($emailCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.email_change_points', 35);
            $signals[] = $emailCheck['signal'];
        }

        $ipCheck = $this->checkNewIP($userId, $ip);
        if ($ipCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_ip_points', 20);
            $signals[] = 'ورود از IP جدید';

            $ipQuality = $this->ipQuality->check($ip);
            if ($ipQuality['is_suspicious']) {
                $riskScore += $this->policy->getInt('fraud', 'takeover.suspicious_ip_bonus_points', 30);
                $signals[] = 'IP مشکوک: ' . implode(', ', is_array($ipQuality['reasons'] ?? null) ? array_map(static fn($v) => str_value($v), $ipQuality['reasons']) : []);
            }
        }

        $deviceCheck = $this->checkNewDevice($userId, $fingerprint, $userAgent);
        if ($deviceCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_device_points', 15);
            $signals[] = 'ورود از دستگاه جدید';
        }

        // 🧠 Activity Hour Behavioral Baseline Correlator
        $timezone = $this->model->getUserTimezone($userId);
        $userDateTime = new \DateTime('now', new \DateTimeZone($timezone));
        $hour = (int)$userDateTime->format('H');
        
        // Static odd hour warning
        if ($hour >= 2 && $hour <= 6) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.odd_hour_points', 10);
            $signals[] = 'ورود در ساعت غیرمعمول (۲ تا ۶ صبح)';
        }

        // Dynamic baseline comparison: Compare current login hour with user's 30-day transaction log.
        try {
            $historicHours = $this->model->getHourlyActivity($userId, 30);
            if (!empty($historicHours) && !isset($historicHours[$hour])) {
                // The user has zero historic transactions in this specific hour bucket
                $riskScore += $this->policy->getInt('fraud', 'takeover.hourly_drift_points', 15);
                $signals[] = 'انحراف زمانی: ورود در ساعت مغایر با الگوی رفتاری تاریخی';
            }
        } catch (\Throwable $e) {
            $this->logger->warning('takeover.baseline_drift_check_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.accountTakeover.baselineDriftCheck']);
        }

        // 🛡️ Session Behavioral & Impossible Travel Correlator
        // M34 Fix: استفاده از شی سشن تزریق‌شده بجای تابع کمکی مستقیم سراسری
        $sessionId = $this->session->getId();
        if ($sessionId) {
            try {
                $sessionRes = $this->sessionAnomaly->analyze($userId, $sessionId);
                if (!empty($sessionRes['anomalies'])) {
                    // Increment by half of session risk score as a blended metric
                    $riskScore += (int)($sessionRes['score'] * 0.5);
                    $anomaliesList = is_array($sessionRes['anomalies']) ? $sessionRes['anomalies'] : [];
                    foreach ($anomaliesList as $anomaly) {
                        $signals[] = "ناهنجاری رفتاری نشست: {$anomaly}";
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('takeover.session_correlator_failed', ['error' => $e->getMessage()]);
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.accountTakeover.sessionCorrelator']);
            }
        }

        // 🚀 Add Impossible Travel Detection
        try {
            $travelCheck = $this->checkImpossibleTravel($userId, $ip);
            if ($travelCheck['suspicious']) {
                $riskScore += $this->policy->getInt('fraud', 'takeover.impossible_travel_points', 90);
                $signals[] = $travelCheck['signal'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('takeover.impossible_travel_check_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.accountTakeover.impossibleTravel']);
        }

        $failedAttempts = $this->model->getRecentFailedAttempts($userId);
        $failedThreshold = $this->policy->getInt('fraud', 'takeover.failed_attempts_threshold', 3);
        if ($failedAttempts > $failedThreshold) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.failed_attempts_points', 25);
            $signals[] = "{$failedAttempts} تلاش ناموفق قبلی";
        }

        $riskScore = min($riskScore, 100);
        $isTakeover = $riskScore >= 70;
        $action = $this->determineAction($riskScore);

        // لاگ بر اساس سطح خطر
        if ($riskScore >= 90) {
            $this->logger->critical('takeover.detected.critical', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals,
                'action' => $action
            ]);
        } elseif ($isTakeover) {
            $this->logger->error('takeover.detected.high', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals,
                'action' => $action
            ]);
        } elseif ($riskScore >= 50) {
            $this->logger->warning('takeover.detected.medium', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals
            ]);
        } else {
            $this->logger->info('takeover.check.clean', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore
            ]);
        }

        return [
            'is_takeover' => $isTakeover,
            'risk_score' => $riskScore,
            'signals' => $signals,
            'action' => $action,
        ];
    }

    /** @return SignalCheck */
    private function checkRecentPasswordChange(int $userId): array
    {
        $lastChange = $this->model->getLastPasswordChange($userId);
        if ($lastChange && (time() - strtotime($lastChange)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر رمز عبور در 1 ساعت اخیر'];
        }

        return ['suspicious' => false, 'signal' => ''];
    }

    /** @return SignalCheck */
    private function checkRecentEmailChange(int $userId): array
    {
        $lastChange = $this->model->getLastEmailChange($userId);
        if ($lastChange && (time() - strtotime($lastChange)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر ایمیل در 1 ساعت اخیر'];
        }

        return ['suspicious' => false, 'signal' => ''];
    }

    private function isLocalEnvironment(?string $ip = null): bool
    {
        if (str_value(config('app.env', env('APP_ENV', 'production'))) === 'local') {
            return true;
        }
        $checkIp = $ip ?? str_value(get_client_ip());
        return in_array($checkIp, ['127.0.0.1', '::1'], true);
    }

    /** @return NewSignalCheck */
    private function checkNewIP(int $userId, string $ip): array
    {
        if ($this->isLocalEnvironment($ip)) {
            return ['is_new' => false];
        }
        $count = $this->model->getIPUsageCount($userId, $ip);
        return ['is_new' => $count === 0];
    }

    /** @return NewSignalCheck */
    private function checkNewDevice(int $userId, ?string $fingerprint, string $userAgent): array
    {
        if ($this->isLocalEnvironment()) {
            return ['is_new' => false];
        }

        if ($fingerprint) {
            $existing = $this->fingerprintService->getUserFingerprints($userId, 50);
            foreach ($existing as $record) {
                if ($record->fingerprint === $fingerprint) {
                    return ['is_new' => false];
                }
            }
            return ['is_new' => true];
        }
        
        // Fallback to user agent if fingerprint is not available
        $count = $this->model->getDeviceUsageCount($userId, $userAgent);
        return ['is_new' => $count === 0];
    }

    private function determineAction(int $riskScore): string
    {
        if ($riskScore >= 90) {
            return 'block';
        }
        if ($riskScore >= 70) {
            return 'challenge';
        }
        if ($riskScore >= 50) {
            return 'notify';
        }
        return 'allow';
    }

    /** @param Detection $detection */
    public function logDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        if (!$detection['is_takeover']) {
            return;
        }

        $this->model->logTakeoverDetection($userId, $ip, $userAgent, $detection);
    }

    /** @return SignalCheck */
    private function checkImpossibleTravel(int $userId, string $ip): array
    {
        $current = $this->geoIPService->lookup($ip);
        $model = $this->ipAndDeviceModel;
        $last = $model?->getLastLoginLocation($userId);
        
        if (!$last) {
            return ['suspicious' => false, 'signal' => ''];
        }
        
        if (!isset($current['latitude'], $current['longitude'])) {
            return ['suspicious' => false, 'signal' => ''];
        }
        
        // در رکوردهای جدید latitude/longitude ذخیره نمی‌شود، از مختصات پیش‌فرض یا city استفاده می‌کنیم
        $lastLat = $last->latitude ?? 35.6892;
        $lastLon = $last->longitude ?? 51.3890;
        
        $distance = $this->geoIPService->calculateDistance(
            [
                'latitude' => (float)$lastLat,
                'longitude' => (float)$lastLon
            ],
            [
                'latitude' => float_value($current['latitude']),
                'longitude' => float_value($current['longitude'])
            ]
        );
        
        $lastValues = get_object_vars($last);
        $lastLoginAt = is_string($lastValues['login_at'] ?? null) ? $lastValues['login_at'] : '';
        $lastTimestamp = $lastLoginAt === '' ? false : strtotime($lastLoginAt);
        if ($lastTimestamp === false) return ['suspicious' => false, 'signal' => ''];
        $timeDiff = time() - $lastTimestamp;
        
        if ($timeDiff < 60) {
            return ['suspicious' => false, 'signal' => '']; // کمتر از 1 دقیقه
        }
        
        $speedKmH = $distance / ($timeDiff / 3600);
        
        // غیرممکن بودن سرعت حرکت (مثلا بیش از ۱۰۰۰ کیلومتر بر ساعت)
        if ($speedKmH > 1000) {
            return [
                'suspicious' => true,
                'signal' => sprintf(
                    'Impossible travel: %d km in %d minutes (%.0f km/h) from %s to %s',
                    int_value($distance),
                    (int)($timeDiff / 60),
                    float_value($speedKmH),
                    str_value($last->city ?? 'Unknown'),
                    str_value($current['city'] ?? 'Unknown')
                )
            ];
        }
        
        return ['suspicious' => false, 'signal' => ''];
    }
}
