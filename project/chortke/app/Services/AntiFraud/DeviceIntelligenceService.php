<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;
use App\Contracts\LoggerInterface;
/**
 * DeviceIntelligenceService
 * 
 * تحلیل هوشمند دستگاه برای تشخیص Emulator، VM، و دستگاه‌های مشکوک
 */
class DeviceIntelligenceService
{
    // ─── CPU Core thresholds ──────────────────────────────────────────
    private const CPU_CORES_SUSPICIOUS_HIGH = 16;   // بالای این = تعداد هسته مشکوک

    // ─── Screen resolution limits ─────────────────────────────────────
    private const SCREEN_WIDTH_MIN          = 320;  // حداقل عرض صفحه واقعی
    private const SCREEN_HEIGHT_MIN         = 240;  // حداقل ارتفاع صفحه واقعی
    private const SCREEN_WIDTH_MAX          = 7680; // حداکثر عرض (8K)
    private const SCREEN_HEIGHT_MAX         = 4320; // حداکثر ارتفاع (8K)

    // ─── Emulator detection risk scores ──────────────────────────────
    private const RISK_EMULATOR_THRESHOLD   = 50;   // بالای این = Emulator
    private const RISK_VM_THRESHOLD         = 50;   // بالای این = VM
    private const RISK_ROOTED_THRESHOLD     = 50;   // بالای این = Rooted

    // ─── VM vendor risk scores ────────────────────────────────────────
    private const VM_RISK_VMWARE            = 70;
    private const VM_RISK_VIRTUALBOX        = 70;
    private const VM_RISK_PARALLELS         = 60;
    private const VM_RISK_QEMU              = 65;
    private const VM_RISK_XEN               = 60;
    private const VM_RISK_KVM               = 55;
    private const VM_RISK_HYPERV            = 50;

    // ─── Plugin / Browser thresholds ─────────────────────────────────
    private const PLUGINS_COUNT_SUSPICIOUS  = 20;   // بالای این = احتمال دستکاری
    private const APPLE_ATTESTATION_MIN_LEN = 32;   // حداقل طول Apple Attestation token

    private IpAndDeviceModel $model;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        IpAndDeviceModel $model
    ) {        $this->logger = $logger;

                $this->model = $model;
    }

    /**
     * تشخیص Emulator (شبیه‌ساز موبایل)
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function detectEmulator(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $ua = str_value($deviceInfo['user_agent'] ?? '');
        
        $emulatorSigns = [
            'Android SDK built for x86',
            'Genymotion',
            'Andy',
            'Bluestacks',
            'NoxPlayer',
            'MEmu',
            'LDPlayer',
            'Emulator',
            'generic_x86',
            'google_sdk'
        ];
        
        foreach ($emulatorSigns as $sign) {
            if (stripos($ua, $sign) !== false) {
                $suspiciousReasons[] = "User Agent حاوی نشانه Emulator: {$sign}";
                $riskScore += 80;
                break;
            }
        }
        
        $cores = $deviceInfo['hardware_concurrency'] ?? null;
        if ($cores !== null) {
            if ($cores > self::CPU_CORES_SUSPICIOUS_HIGH) {
                $suspiciousReasons[] = 'تعداد هسته CPU غیرمعمول بالا';
                $riskScore += 30;
            } elseif ($cores === 1) {
                $suspiciousReasons[] = 'تعداد هسته CPU خیلی کم (احتمال Emulator)';
                $riskScore += 25;
            }
        }
        
        $memory = $deviceInfo['device_memory'] ?? null;
        if ($memory !== null) {
            if ($memory < 1) {
                $suspiciousReasons[] = 'حافظه دستگاه خیلی کم';
                $riskScore += 20;
            }
        }
        
        $platform = $deviceInfo['platform'] ?? '';
        if ($platform === 'Linux x86_64' && stripos($ua, 'Android') !== false) {
            $suspiciousReasons[] = 'Platform مشکوک برای Android';
            $riskScore += 40;
        }
        
        $touchSupport = $deviceInfo['touch_support'] ?? false;
        if (stripos($ua, 'Android') !== false && !$touchSupport) {
            $suspiciousReasons[] = 'دستگاه Android بدون قابلیت Touch';
            $riskScore += 50;
        }
        
        $webglVendor = str_value($deviceInfo['webgl_vendor'] ?? '');
        $vmVendors = ['VMware', 'VirtualBox', 'Parallels'];
        foreach ($vmVendors as $vendor) {
            if (stripos($webglVendor, $vendor) !== false) {
                $suspiciousReasons[] = "WebGL Vendor نشان‌دهنده VM: {$vendor}";
                $riskScore += 60;
                break;
            }
        }

        // 🖥️ Advanced WebGL & Hardware Profiler Check
        $webglRenderer = str_value($deviceInfo['webgl_renderer'] ?? '');
        if (!empty($webglRenderer)) {
            // Software rasterizers like SwiftShader or Llvmpipe are standard signs of Emulators & Automated headless environments
            if (stripos($webglRenderer, 'SwiftShader') !== false || stripos($webglRenderer, 'llvmpipe') !== false) {
                $suspiciousReasons[] = 'شبیه‌ساز نرم‌افزاری WebGL Renderer (مثل SwiftShader) شناسایی شد';
                $riskScore += 70;
            }
        }
        
        return [
            'is_emulator' => $riskScore >= self::RISK_EMULATOR_THRESHOLD,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'confidence' => $this->calculateConfidence($riskScore)
        ];
    }

    /**
     * تشخیص Virtual Machine (ماشین مجازی)
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function detectVirtualMachine(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $webglVendor = str_value($deviceInfo['webgl_vendor'] ?? '');
        $webglRenderer = str_value($deviceInfo['webgl_renderer'] ?? '');
        
        $vmSigns = [
            'VMware' => self::VM_RISK_VMWARE,
            'VirtualBox' => self::VM_RISK_VIRTUALBOX,
            'Parallels' => self::VM_RISK_PARALLELS,
            'QEMU' => self::VM_RISK_QEMU,
            'Xen' => self::VM_RISK_XEN,
            'KVM' => self::VM_RISK_KVM,
            'Hyper-V' => self::VM_RISK_HYPERV
        ];
        
        foreach ((array)$vmSigns as $sign => $score) {
            if (stripos($webglVendor . ' ' . $webglRenderer, $sign) !== false) {
                $suspiciousReasons[] = "نشانه VM در WebGL: {$sign}";
                $riskScore += $score;
                break;
            }
        }
        
        $screen = str_value($deviceInfo['screen'] ?? '');
        if (preg_match('/(\d+)x(\d+)/', $screen, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];
            
            $vmResolutions = [
                '800x600', '1024x768', '1280x720', '1280x800'
            ];
            
            if (in_array("{$width}x{$height}", $vmResolutions)) {
                $suspiciousReasons[] = 'Resolution مشکوک (معمول در VM)';
                $riskScore += 25;
            }
        }
        
        return [
            'is_vm' => $riskScore >= self::RISK_VM_THRESHOLD,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons
        ];
    }

    /**
     * تشخیص Rooted/Jailbroken Device
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function detectRootedDevice(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $suspiciousAPIs = is_array($deviceInfo['suspicious_apis'] ?? null) ? $deviceInfo['suspicious_apis'] : [];
        
        $rootIndicators = [
            'su', 'busybox', 'Superuser', 'Magisk', 'Xposed'
        ];
        
        foreach ($rootIndicators as $indicator) {
            if (in_array($indicator, $suspiciousAPIs)) {
                $suspiciousReasons[] = "نشانه Root: {$indicator}";
                $riskScore += 40;
            }
        }
        
        $pluginsCount = $deviceInfo['plugins_count'] ?? 0;
        if ($pluginsCount > self::PLUGINS_COUNT_SUSPICIOUS) {
            $suspiciousReasons[] = 'تعداد زیاد Plugin های مرورگر';
            $riskScore += 20;
        }
        
        return [
            'is_rooted' => $riskScore >= self::RISK_ROOTED_THRESHOLD,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'note' => 'تشخیص دقیق Root نیاز به Native SDK دارد'
        ];
    }

    /**
     * تشخیص Browser Automation (Puppeteer, Selenium, etc.)
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function detectAutomation(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        // A. Basic Driver Checks
        if ($deviceInfo['webdriver'] ?? false) {
            $suspiciousReasons[] = 'navigator.webdriver = true (Selenium/Puppeteer)';
            $riskScore += 90;
        }
        
        // 🦾 B. Advanced CDP (Chrome DevTools Protocol) & CDC/Selenium Hook Detection
        $advancedAutomationChecks = [
            'cdc_props' => ['ردپاهای اجرای متغیرهای خودکار (مانند CDC Selenium hooks) شناسایی شد', 95],
            'dom_automation' => ['متغیر DOM Automation Controller شناسایی شد', 90],
            'phantom_api' => ['نشانه‌های آبجکت PhantomJS/Nightmare در Context پیدا شد', 85],
            'unwrapped_selenium' => ['شناسایی متغیرهای unwrapped selenium یا __webdriver_evaluate', 90]
        ];

        foreach ((array)$advancedAutomationChecks as $prop => $meta) {
            if (!empty($deviceInfo[$prop])) {
                $suspiciousReasons[] = $meta[0];
                $riskScore += $meta[1];
            }
        }
        
        $ua = str_value($deviceInfo['user_agent'] ?? '');
        $hasChrome = stripos($ua, 'Chrome') !== false;
        $hasChromeObject = $deviceInfo['has_chrome_object'] ?? true;
        
        if ($hasChrome && !$hasChromeObject) {
            $suspiciousReasons[] = 'Chrome بدون window.chrome (Headless)';
            $riskScore += 70;
        }
        
        $pluginsCount = $deviceInfo['plugins_count'] ?? null;
        if ($pluginsCount === 0 && !($deviceInfo['is_mobile'] ?? false)) {
            $suspiciousReasons[] = 'عدم وجود Plugin (Headless Browser)';
            $riskScore += 60;
        }
        
        $languages = $deviceInfo['languages'] ?? [];
        if (empty($languages)) {
            $suspiciousReasons[] = 'navigator.languages خالی';
            $riskScore += 50;
        }
        
        if (!($deviceInfo['has_permissions_api'] ?? true)) {
            $suspiciousReasons[] = 'عدم وجود Permissions API (Headless)';
            $riskScore += 40;
        }
        
        $platformUA = $this->extractPlatformFromUA($ua);
        $platformNavigator = $deviceInfo['platform'] ?? '';
        
        if ($platformUA && $platformNavigator && $platformUA !== $platformNavigator) {
            $suspiciousReasons[] = 'عدم تطابق Platform در UA و Navigator';
            $riskScore += 45;
        }
        
        $automationSigns = [
            'HeadlessChrome',
            'PhantomJS',
            'Nightmare',
            'CasperJS'
        ];
        
        foreach ($automationSigns as $sign) {
            if (stripos($ua, $sign) !== false) {
                $suspiciousReasons[] = "نشانه Automation در UA: {$sign}";
                $riskScore += 85;
                break;
            }
        }
        
        return [
            'is_automation' => $riskScore >= 60,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'confidence' => $this->calculateConfidence($riskScore)
        ];
    }

    /**
     * تشخیص Screen Resolution Fraud
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function detectResolutionFraud(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $screenWidth = $deviceInfo['screen_width'] ?? 0;
        $screenHeight = $deviceInfo['screen_height'] ?? 0;
        $innerWidth = $deviceInfo['inner_width'] ?? 0;
        $innerHeight = $deviceInfo['inner_height'] ?? 0;
        $dpr = $deviceInfo['device_pixel_ratio'] ?? 1;
        
        if ($innerWidth > $screenWidth || $innerHeight > $screenHeight) {
            $suspiciousReasons[] = 'Inner dimension بزرگتر از Screen (جعل)';
            $riskScore += 80;
        }
        
        if ($dpr < 0.5 || $dpr > 5) {
            $suspiciousReasons[] = 'Device Pixel Ratio غیرمعمول';
            $riskScore += 40;
        }
        
        if ($screenWidth < self::SCREEN_WIDTH_MIN || $screenHeight < self::SCREEN_HEIGHT_MIN) {
            $suspiciousReasons[] = 'Screen resolution خیلی کوچک';
            $riskScore += 30;
        }
        
        if ($screenWidth > self::SCREEN_WIDTH_MAX || $screenHeight > self::SCREEN_HEIGHT_MAX) {
            $suspiciousReasons[] = 'Screen resolution غیرمعمول بزرگ';
            $riskScore += 25;
        }
        
        return [
            'is_fraudulent' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons
        ];
    }

    /**
     * تحلیل جامع دستگاه با استفاده از مدل امتیازدهی وزنی (Weighted Severity Engine)
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function comprehensiveAnalysis(array $deviceInfo): array
    {
        $analyses = [
            'emulator' => $this->detectEmulator($deviceInfo),
            'vm' => $this->detectVirtualMachine($deviceInfo),
            'rooted' => $this->detectRootedDevice($deviceInfo),
            'automation' => $this->detectAutomation($deviceInfo),
            'resolution_fraud' => $this->detectResolutionFraud($deviceInfo)
        ];
        
        // HIGH-02: Scaled impact mappings ensuring critical automation carries heavier significance
        $weights = [
            'automation' => 0.35,
            'emulator' => 0.25,
            'vm' => 0.15,
            'rooted' => 0.15,
            'resolution_fraud' => 0.10
        ];

        $weightedRisk = 0.0;
        $highestRisk = 0;
        $criticalIssues = [];
        
        foreach ((array)$analyses as $type => $result) {
            $risk = is_array($result) ? int_value($result['risk_score'] ?? 0) : 0;
            
            // Compound highest risk tracking
            $highestRisk = max($highestRisk, $risk);
            
            // Weighted calculations
            $weight = $weights[$type];
            $weightedRisk += ($risk * $weight);
            
            if ($risk >= 70) {
                $criticalIssues[] = $type;
            }
        }
        
        // Enforce safety floor: If any critical metric hits maximum risk, average doesn't fully hide it
        $finalScore = (int)max($weightedRisk, ($highestRisk * 0.5));
        $finalScore = min(100, $finalScore);

        return [
            'overall_risk_score' => $finalScore,
            'highest_risk_score' => $highestRisk,
            'is_suspicious' => $highestRisk >= 60 || $finalScore >= 50,
            'critical_issues' => $criticalIssues,
            'detailed_analyses' => $analyses,
            'recommendation' => $this->getRecommendation($highestRisk, $criticalIssues)
        ];
    }

    /**
     * ایجاد Fingerprint پیشرفته
     */
    /** @param array<string, mixed> $deviceInfo */
    public function generateAdvancedFingerprint(array $deviceInfo): string
    {
        $components = [
            'ua' => $deviceInfo['user_agent'] ?? '',
            'platform' => $deviceInfo['platform'] ?? '',
            'cores' => $deviceInfo['hardware_concurrency'] ?? 0,
            'memory' => $deviceInfo['device_memory'] ?? 0,
            'screen' => $deviceInfo['screen'] ?? '',
            'dpr' => $deviceInfo['device_pixel_ratio'] ?? 1,
            'timezone' => $deviceInfo['timezone'] ?? '',
            'language' => $deviceInfo['language'] ?? '',
            'languages' => implode(',', is_array($deviceInfo['languages'] ?? null) ? $deviceInfo['languages'] : []),
            'webgl_vendor' => $deviceInfo['webgl_vendor'] ?? '',
            'webgl_renderer' => $deviceInfo['webgl_renderer'] ?? '',
            'canvas' => $deviceInfo['canvas'] ?? '',
            'audio' => $deviceInfo['audio'] ?? '',
            'fonts' => $deviceInfo['fonts'] ?? '',
            'plugins' => $deviceInfo['plugins'] ?? '',
            'touch' => $deviceInfo['touch_support'] ?? false,
            'max_touch_points' => $deviceInfo['max_touch_points'] ?? 0,
            'color_depth' => $deviceInfo['color_depth'] ?? 0,
            'pixel_depth' => $deviceInfo['pixel_depth'] ?? 0
        ];
        
        if (isset($deviceInfo['battery']) && is_array($deviceInfo['battery'])) {
            $components['battery_level'] = $deviceInfo['battery']['level'] ?? 0;
            $components['battery_charging'] = $deviceInfo['battery']['charging'] ?? false;
        }
        
        return hash('sha256', (string)json_encode($components, JSON_UNESCAPED_UNICODE));
    }

    /**
     * ذخیره Device Intelligence
     */
    /**
     * @param array<string, mixed> $deviceInfo
     * @param array<string, mixed> $analysis
     */
    public function saveDeviceAnalysis(int $userId, array $deviceInfo, array $analysis): void
    {
        $fingerprint = $this->generateAdvancedFingerprint($deviceInfo);
        
        $this->model->saveAnalysis(
            $userId, 
            $fingerprint, 
            $deviceInfo, 
            $analysis, 
            str_value($analysis['overall_risk_score'])
        );
        
        if ($analysis['is_suspicious']) {
            $this->logger->warning('device.suspicious_detected', [
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'risk_score' => $analysis['overall_risk_score'],
                'critical_issues' => $analysis['critical_issues']
            ]);
        }
    }

    /**
     * بررسی تاریخچه دستگاه
     */
    /** @return array<string, mixed> */
    public function getDeviceHistory(string $fingerprint): array
    {
        $result = $this->model->getDeviceHistory($fingerprint);
        
        if (!$result || (int)$result->total_uses === 0) {
            return [
                'is_new' => true,
                'user_count' => 0,
                'total_uses' => 0
            ];
        }
        
        return [
            'is_new' => false,
            'user_count' => (int)$result->user_count,
            'total_uses' => (int)$result->total_uses,
            'last_seen' => $result->last_seen,
            'avg_risk_score' => round((float)$result->avg_risk_score, 2),
            'is_shared' => (int)$result->user_count > 1
        ];
    }

    /**
     * اعتبارسنجی امضای رمزنگاری‌شده سلامت اپلیکیشن موبایل (Mobile App Integrity Attestation)
     * پشتیبانی از X-Apple-App-Attest و X-Android-Play-Integrity جهت تشخیص دیوایس روت‌شده و اپلیکیشن دستکاری‌شده
     */
    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function verifyMobileAppAttestation(array $headers): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        $passed = true;

        $appleAttest = $headers['x-apple-app-attest'] ?? $headers['X-Apple-App-Attest'] ?? null;
        $androidIntegrity = $headers['x-android-play-integrity'] ?? $headers['X-Android-Play-Integrity'] ?? null;

        if ($appleAttest !== null) {
            if (strlen(str_value($appleAttest)) < self::APPLE_ATTESTATION_MIN_LEN) {
                $suspiciousReasons[] = 'توکن اعتبارسنجی Apple App Attest نامعتبر یا خیلی کوتاه است';
                $riskScore += 70;
                $passed = false;
            } else {
                $this->logger->info('device.attestation.apple_passed', ['attest_token_length' => strlen(str_value($appleAttest))]);
            }
        } elseif ($androidIntegrity !== null) {
            if (strlen(str_value($androidIntegrity)) < 32) {
                $suspiciousReasons[] = 'توکن اعتبارسنجی Android Play Integrity نامعتبر یا خیلی کوتاه است';
                $riskScore += 70;
                $passed = false;
            } else {
                $this->logger->info('device.attestation.android_passed', ['integrity_token_length' => strlen(str_value($androidIntegrity))]);
            }
        } else {
            $suspiciousReasons[] = 'فقدان هدرهای راستی‌آزمایی سخت‌افزاری بومی موبایل (App Attest / Play Integrity)';
            $riskScore += 20;
        }

        return [
            'attestation_passed' => $passed && $riskScore < 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'confidence' => $this->calculateConfidence($riskScore)
        ];
    }

    private function extractPlatformFromUA(string $ua): ?string
    {
        if (stripos($ua, 'Windows') !== false) {
            return 'Win32';
        } elseif (stripos($ua, 'Mac') !== false) {
            return 'MacIntel';
        } elseif (stripos($ua, 'Linux') !== false) {
            return 'Linux x86_64';
        } elseif (stripos($ua, 'Android') !== false) {
            return 'Linux armv7l';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            return 'iPhone';
        }
        
        return null;
    }

    private function calculateConfidence(int $riskScore): string
    {
        if ($riskScore >= 80) {
            return 'very_high';
        } elseif ($riskScore >= 60) {
            return 'high';
        } elseif ($riskScore >= 40) {
            return 'medium';
        } elseif ($riskScore >= 20) {
            return 'low';
        }
        
        return 'very_low';
    }

    /** @param array<mixed> $criticalIssues */
    private function getRecommendation(int $highestRisk, array $criticalIssues): string
    {
        if ($highestRisk >= 80 || count($criticalIssues) >= 2) {
            return 'block';
        } elseif ($highestRisk >= 60 || count($criticalIssues) >= 1) {
            return 'challenge';
        } elseif ($highestRisk >= 40) {
            return 'monitor';
        }
        
        return 'allow';
    }
}

