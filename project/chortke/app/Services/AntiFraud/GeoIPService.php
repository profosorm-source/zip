<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Cache;
use App\Models\IpAndDeviceModel;
use App\Services\AntiFraud\RiskPolicyService;

use App\Contracts\LoggerInterface;
/**
 * GeoIPService - سرویس تشخیص موقعیت جغرافیایی
 * 
 * این سرویس برای تشخیص موقعیت جغرافیایی IP استفاده می‌شود
 * از MaxMind GeoIP2 یا دیتابیس محلی استفاده می‌کند
 */
class GeoIPService
{
    private IpAndDeviceModel $model;
    private RiskPolicyService $policy;
    private ?string $maxmindLicenseKey;
    private string $databasePath;
    private bool $useMaxMind = false;
    /** @var \GeoIp2\Database\Reader|null when library is installed */
    private ?\GeoIp2\Database\Reader $reader = null;
    private Cache $cache;
    private LoggerInterface $logger;

    
    public function __construct(
        Cache $cache,
        IpAndDeviceModel $model,
        RiskPolicyService $policy,
        LoggerInterface $logger,
        \Core\PathResolver $paths
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->model = $model;
        $this->policy = $policy;
        $this->maxmindLicenseKey = str_value(config('services.geoip.maxmind_license_key', ''));
        $this->databasePath = rtrim($paths->storage('geoip'), '/\\') . DIRECTORY_SEPARATOR;
        
        // بررسی وجود MaxMind
        $this->checkMaxMindAvailability();
    }

    /**
     * بررسی اینکه آیا IP بلاک شده است یا خیر
     */
    public function isIPBlacklisted(string $ip): bool
    {
        return $this->model->isIpBlacklisted($ip);
    }

    
    /**
     * دریافت اطلاعات جغرافیایی IP
     */
    /** @return array<string, mixed> */
    public function lookup(string $ip): array
    {
        // بررسی IP خصوصی
        if ($this->isPrivateIP($ip)) {
            return $this->getDefaultLocation();
        }
        
        // بررسی کش
        $cached = $this->getCachedLocation($ip);
        if ($cached) {
            return $cached;
        }
        
        // تلاش برای استفاده از MaxMind
        if ($this->useMaxMind) {
            $result = $this->lookupMaxMind($ip);
            if ($result) {
                $this->cacheLocation($ip, $result);
                return $result;
            }
        }
        
        // Fallback به دیتابیس محلی
        $result = $this->lookupLocalDatabase($ip);
        
        if ($result) {
            $this->cacheLocation($ip, $result);
            return $result;
        }
        
        // H19 Fix: ممانعت از انتساب نامشخص‌ها به عنوان ایران (جلوگیری از تشخیص غلط آی‌پی‌های خارجی و IPv6)
        return $this->getUnknownLocation($ip);
    }
    
    /**
     * بررسی وجود MaxMind GeoIP2
     */
    private function checkMaxMindAvailability(): void
    {
        // بررسی وجود کتابخانه MaxMind
        if (!class_exists('\GeoIp2\Database\Reader')) {
            return;
        }
        
        // بررسی وجود فایل دیتابیس
        $dbFile = $this->databasePath . 'GeoLite2-City.mmdb';
        
        if (file_exists($dbFile)) {
            try {
                $this->reader = new \GeoIp2\Database\Reader($dbFile);
                $this->useMaxMind = true;
            } catch (\Throwable $e) {
                $this->logger->error("geoip.maxmind_init_failed", ["error" => $e->getMessage()]);
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'geoip.maxmind.init']);
            }
        }
    }
    
    /**
     * Lookup با MaxMind
     */
    /** @return array<string, mixed>|null */
    private function lookupMaxMind(string $ip): ?array
    {
        if (!$this->reader) {
            return null;
        }
        
        try {
            $record = $this->reader->city($ip);
            
            return [
                'ip' => $ip,
                'country_code' => $record->country->isoCode ?? 'IR',
                'country_name' => $record->country->name ?? 'Iran',
                'city' => $record->city->name ?? 'Tehran',
                'latitude' => $record->location->latitude ?? 35.6892,
                'longitude' => $record->location->longitude ?? 51.3890,
                'timezone' => $record->location->timeZone ?? 'Asia/Tehran',
                'postal_code' => $record->postal->code ?? null,
                'accuracy_radius' => $record->location->accuracyRadius ?? null,
                'source' => 'maxmind',
            ];
        } catch (\Throwable $e) {
            $this->logger->error("geoip.maxmind_lookup_failed", ["ip" => $ip, "error" => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'geoip.maxmind.lookup', 'ip' => $ip]);
            return null;
        }
    }
    
    /**
     * Lookup از دیتابیس محلی (IP ranges)
     */
    /**
     * Lookup از دیتابیس محلی (IP ranges)
     */
    /** @return array<string, mixed>|null */
    private function lookupLocalDatabase(string $ip): ?array
    {
        try {
            // دیتابیس ip_locations بر پایه IPv4 range است
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return null;
            }

            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return null;
            }

            // Decoupled: Delegates native table scanning to the Model
            $result = $this->model->getLocationByIpRange($ipLong);

            if ($result) {
                return [
                    'ip' => $ip,
                    'country_code' => $result->country_code ?? 'IR',
                    'country_name' => $result->country_name ?? 'Iran',
                    'city' => $result->city ?? 'Tehran',
                    'latitude' => floatval($result->latitude ?? 35.6892),
                    'longitude' => floatval($result->longitude ?? 51.3890),
                    'timezone' => 'Asia/Tehran',
                    'source' => 'local_db',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->error("antifraud.geoip_lookup.local_db_failed", ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'geoip.localDb.lookup']);
        }

        return null;
    }
    /**
     * دریافت از کش
     */
    /** @return array<string, mixed>|null */
    private function getCachedLocation(string $ip): ?array
    {
        $cached = $this->cache->get('geoip:' . $ip);
        if (!$cached) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        $decoded = (array)(json_decode(str_value($cached), true) ?? []);
        return $decoded;
    }
    
    /**
     * ذخیره در کش
     */
    /** @param array<string, mixed> $data */
    private function cacheLocation(string $ip, array $data): void
    {
        // کش برای ۷ روز
        $this->cache->put('geoip:' . $ip, json_encode($data), 60 * 24 * 7);
    }
    
    /**
     * بررسی IP خصوصی
     */
private function isPrivateIP(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // اگر فرمت آی‌پی اصلاً معتبر نیست، یک آی‌پی خصوصی در نظر گرفته نمی‌شود (بلکه کلاً نامعتبر است)
            return false; 
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
    
    /**
     * بررسی قرار گرفتن IP در یک Range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    
    /**
     * موقعیت پیش‌فرض (ایران)
     */
    /** @return array<string, mixed> */
    private function getDefaultLocation(): array
    {
        return [
            'ip' => '',
            'country_code' => 'IR',
            'country_name' => 'Iran',
            'city' => 'Tehran',
            'latitude' => 35.6892,
            'longitude' => 51.3890,
            'timezone' => 'Asia/Tehran',
            'source' => 'default',
        ];
    }

    /**
     * H19 Support: موقعیت نامشخص جهت جلوگیری از انتساب کور به ایران
     */
    /** @return array<string, mixed> */
    private function getUnknownLocation(string $ip): array
    {
        return [
            'ip' => $ip,
            'country_code' => 'XX',
            'country_name' => 'Unknown',
            'city' => 'Unknown',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'UTC',
            'source' => 'unknown',
        ];
    }
    
    /**
     * بررسی اینکه IP از کدام کشور است
     */
    public function getCountryCode(string $ip): string
    {
        $location = $this->lookup($ip);
        $countryCode = $location['country_code'] ?? null;
        return is_string($countryCode) && preg_match('/^[A-Z]{2}$/', $countryCode) === 1
            ? $countryCode
            : 'IR';
    }
    
    /**
     * بررسی اینکه IP ایرانی است یا خیر
     */
    public function isIranianIP(string $ip): bool
    {
        return $this->getCountryCode($ip) === 'IR';
    }
    
    /**
     * محاسبه فاصله بین دو موقعیت (کیلومتر)
     */
    /**
     * @param array<string, mixed> $location1
     * @param array<string, mixed> $location2
     */
    public function calculateDistance(array $location1, array $location2): float
    {
        $lat1 = deg2rad(float_value($location1['latitude']));
        $lon1 = deg2rad(float_value($location1['longitude']));
        $lat2 = deg2rad(float_value($location2['latitude']));
        $lon2 = deg2rad(float_value($location2['longitude']));
        
        $earthRadius = 6371; // کیلومتر
        
        $latDiff = $lat2 - $lat1;
        $lonDiff = $lon2 - $lon1;
        
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($lat1) * cos($lat2) *
             sin($lonDiff / 2) * sin($lonDiff / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * دانلود و بروزرسانی دیتابیس MaxMind با کنترل کامل Integrity و Zip Bomb Protection (Issue #17 Fix)
     */
    /** @return array<string, mixed> */
    public function updateMaxMindDatabase(): array
    {
        if (empty($this->maxmindLicenseKey)) {
            return [
                'success' => false,
                'message' => 'MaxMind license key not configured',
            ];
        }
        
        try {
            $url = "https://download.maxmind.com/app/geoip_download?" . http_build_query([
                'edition_id' => 'GeoLite2-City',
                'license_key' => $this->maxmindLicenseKey,
                'suffix' => 'tar.gz',
            ]);
            
            $downloadPath = $this->databasePath . 'GeoLite2-City.tar.gz';
            
            // ایجاد دایرکتوری اگر وجود نداشت
            if (!is_dir($this->databasePath)) {
                mkdir($this->databasePath, 0755, true);
            }
            
            // دانلود فایل به صورت chunked با سقف حجم 50MB
            $maxBytes = 50 * 1024 * 1024; // 50MB max archive size
            $fp = @fopen($downloadPath, 'wb');
            $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 15.0]]));
            
            if (!$fp || !$in) {
                if ($fp) fclose($fp);
                if ($in) fclose($in);
                throw new \Core\Exceptions\InfrastructureException('خطا در دانلود پایگاه داده GeoIP');
            }

            $downloaded = 0;
            while (!feof($in)) {
                $chunk = fread($in, 8192);
                if ($chunk === false) break;
                $downloaded += strlen($chunk);
                if ($downloaded > $maxBytes) {
                    fclose($fp);
                    fclose($in);
                    @unlink($downloadPath);
                    throw new \Core\Exceptions\SecurityException('حجم فایل دانلود شده از سقف مجاز ۵۰ مگابایت بیشتر است.');
                }
                fwrite($fp, $chunk);
            }
            fclose($fp);
            fclose($in);

            // بررسی امضای Gzip (\x1f\x8b)
            $header = @file_get_contents($downloadPath, false, null, 0, 2);
            if ($header !== "\x1f\x8b") {
                @unlink($downloadPath);
                throw new \Core\Exceptions\SecurityException('فایل دانلود شده یک آرشیو معتبر Gzip نیست.');
            }
            
            // استخراج فایل با ایمن‌سازی مسیر
            $this->extractGeoIPDatabase($downloadPath);
            
            // حذف فایل فشرده
            @unlink($downloadPath);
            
            return [
                'success' => true,
                'message' => 'MaxMind database updated successfully',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error("geoip.maxmind_update_failed", ["error" => $e->getMessage()]);
            @unlink($downloadPath ?? '');
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * استخراج فایل دیتابیس از tar.gz
     */
    private function extractGeoIPDatabase(string $tarFile): void
    {
        // برای استخراج نیاز به PharData داریم
        $phar = new \PharData($tarFile);
        $phar->extractTo($this->databasePath, null, true);
        
        // پیدا کردن فایل .mmdb
        $files = glob($this->databasePath . '*/GeoLite2-City.mmdb');
        
        if (!empty($files)) {
            $sourceFile = $files[0];
            $destFile = $this->databasePath . 'GeoLite2-City.mmdb';
            
            // کپی فایل به مسیر اصلی
            copy($sourceFile, $destFile);
            
            // حذف دایرکتوری موقت
            $tempDir = dirname($sourceFile);
            $this->removeDirectory($tempDir);
        }
    }
    
    /**
     * حذف دایرکتوری به صورت بازگشتی
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    public function check(string $ip): array
    {
        $score = 0;
        $reasons = [];
        $details = [];

        if ($this->isPrivateIP($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.private_range_points', 50);
            $reasons[] = 'استفاده از IP خصوصی';
            $details['is_private'] = true;
        }

        if ($this->isSuspiciousRange($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.suspicious_range_points', 30);
            $reasons[] = 'محدوده IP مشکوک (Datacenter/VPN)';
            $details['suspicious_range'] = true;
        }

        $location = $this->lookup($ip);
        $locationSource = $location['source'] ?? '';
        if (($locationSource === 'default' || $locationSource === 'unknown') && !$this->isPrivateIP($ip) && $ip !== '') {
            $score += $this->policy->getInt('fraud', 'ip.unidentified_points', 40);
            $reasons[] = 'عدم شناسایی موقعیت جغرافیایی IP (نامشخص)';
            $details['unidentified'] = true;
        }

        if ($this->model->isTorNode($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.tor_points', 80);
            $reasons[] = 'استفاده از شبکه Tor';
            $details['is_tor'] = true;
        }

        $userCount = $this->model->getUserCountByIp($ip);
        if ($userCount > $this->policy->getInt('fraud', 'ip.shared_ip_user_threshold', 5)) {
            $score += $this->policy->getInt('fraud', 'ip.shared_ip_points', 40);
            $reasons[] = "استفاده مشترک توسط {$userCount} کاربر";
            $details['user_count'] = $userCount;
        }

        if ($this->model->getIpVelocity($ip)) {
            $score += 25;
            $reasons[] = 'الگوی سرعت تغییر IP مشکوک است';
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'is_suspicious' => $score >= 60,
            'reasons' => $reasons,
            'details' => $details,
        ];
    }

    private function isSuspiciousRange(string $ip): bool
    {
        $ranges = $this->model->getSuspiciousIpRanges();
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, (string)$range->ip_range)) return true;
        }
        return false;
    }

    

    /** @return array<string, mixed>|null */
    public function getGeolocation(string $ip): ?array
    {
        if ($this->isPrivateIP($ip)) return null;
        try {
            return $this->lookup($ip);
        } catch (\Throwable $e) {
            $this->logger->error('antifraud.geoip_lookup.failed', ['error' => $e->getMessage()]);
            return ['country_code' => 'XX', 'country' => 'XX', 'city' => 'Unknown'];
        }
    }

    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $this->model->blacklistIp($ip, $reason, $expiresAt);
    }

    /** @param array<string, mixed> $checkResult */
    public function logIPCheck(int $userId, string $ip, array $checkResult): void
    {
        if ($checkResult['is_suspicious']) {
            $this->model->logFraudEvent([
                'user_id' => $userId,
                'ip' => $ip,
                'score' => int_value($checkResult['score']),
                'type' => 'ip_suspicious',
                'details' => $checkResult
            ]);
        }
    }
}


