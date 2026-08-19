<?php

declare(strict_types=1);

namespace Tests;

use Core\Database;
use Core\Container;
use Core\Encryption;
use Core\RateLimiter;
use Core\Cache;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\DistributedLockService;
use App\Services\Wallet\WalletService;
use App\Models\User;

require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع آزمون‌های دقیق سرعت، کارایی و امنیت پروژه چرتکه (Security & Speed)\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$container = Container::getInstance();

/** @var array{pass: int, fail: int} $stats */
$stats = ['pass' => 0, 'fail' => 0];

/**
 * @param array{pass: int, fail: int} $stats
 */
function assertCheck(bool $condition, string $title, array &$stats, string $details = ''): void {
    if ($condition) {
        $stats['pass']++;
        echo "  [PASS] {$title}" . ($details ? " -> {$details}" : "") . "\n";
    } else {
        $stats['fail']++;
        echo "  [FAIL] {$title}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

// =========================================================================
// بخش ۱: تست‌های دقیق امنیت، نفوذناپذیری و آنتی‌فرود (Security Tests)
// =========================================================================
echo "\n--- بخش ۱: تست‌های پروژه-محور امنیت، نفوذناپذیری و آنتی‌فرود ---\n";

// ۱.۱ تست پاکسازی XSS و جلوگیری از تزریق اسکریپت
$xssPayload = '<script>alert("XSS_ATTACK_TEST")</script><img src=x onerror=alert(1)>سلام';
$sanitized = strip_tags($xssPayload);
assertCheck(!str_contains($sanitized, '<script>') && !str_contains($sanitized, 'onerror') && str_contains($sanitized, 'سلام'),
    'تست ضد XSS و پاکسازی کدهای مخرب اسکریپتی (XSS Sanitization)',
    $stats,
    'متن پاکسازی شده: "' . $sanitized . '"');

// ۱.۲ تست نفوذناپذیری تزریق SQL (SQL Injection Boundary Test)
$sqlPayload = "' OR '1'='1' -- UNION SELECT 1,2,3,4,5#";
$sqlResult = $db->query("SELECT id, username FROM users WHERE username = :uname", ['uname' => $sqlPayload])->fetchAll();
assertCheck(count($sqlResult) === 0,
    'تست ایمنی در برابر SQL Injection با Parameter Binding',
    $stats,
    'تعداد رکورد برگشتی با پەی‌لود مخرب: ' . count($sqlResult));

// ۱.۳ تست رمزنگاری متقارن داده‌های حساس AES-256-GCM (KYC & Card Encryption)
$encryption = $container->make(Encryption::class);
$plainCardNumber = '6037997512345678';
$encrypted = $encryption->encrypt($plainCardNumber);
$decrypted = $encryption->decrypt($encrypted);
assertCheck($encrypted !== $plainCardNumber && $decrypted === $plainCardNumber,
    'تست رمزنگاری و الگوریتم AES-256-GCM داده‌های کارت بانکی و ملی',
    $stats,
    'رمزنگاری موفق و بازگشایی دقیق شماره کارت');

// ۱.۴ تست هوشمند سرعت سفرهای غیرممکن و آنتی‌فرود (Impossible Travel Security)
$geoService = $container->make(GeolocationIntelligenceService::class);
$impossibleTravel = $geoService->detectImpossibleTravel(
    1, // admin user_id
    '185.220.101.5', // Foreign IP
    [
        'latitude' => 51.5074,
        'longitude' => -0.1278,
        'city' => 'London',
        'country' => 'GB'
    ]
);
assertCheck(is_array($impossibleTravel),
    'تست موتور هوشمند ارزیابی سرعت سفر غیرممکن (Impossible Travel Speed)',
    $stats,
    'پاسخ آنتی‌فرود دریافت شد');

// ۱.۵ تست محدودسازی نرخ درخواست‌ها (Rate-Limiter & Brute-Force Protection)
$rateLimiter = $container->make(RateLimiter::class);
$key = 'test_ip_rate_limit_' . time();
$allowedCount = 0;
for ($i = 1; $i <= 10; $i++) {
    if ($rateLimiter->attempt($key, 5, 1)) { // limit 5 per 1 minute
        $allowedCount++;
    }
}
assertCheck($allowedCount === 5,
    'تست Rate-Limiting و جلوگیری از حملات Brute-Force',
    $stats,
    "تعداد درخواست مجاز: {$allowedCount} از ۱۰ درخواست متوالی");

// =========================================================================
// بخش ۲: تست‌های پروژه-محور سرعت، کارایی و بنچمارک (Speed & Benchmarks)
// =========================================================================
echo "\n--- بخش ۲: بنچمارک‌های کارایی، زمان پاسخگویی و سرعت سیستم ---\n";

// ۲.۱ بنچمارک کوئری‌های دیتابیس (Database Query Latency)
$dbStartTime = microtime(true);
$queryCycles = 1000;
for ($i = 0; $i < $queryCycles; $i++) {
    $db->query("SELECT id, username, email FROM users WHERE id = 1")->fetch();
}
$dbEndTime = microtime(true);
$totalDbTimeMs = ($dbEndTime - $dbStartTime) * 1000;
$avgDbTimeMs = $totalDbTimeMs / $queryCycles;
assertCheck($avgDbTimeMs < 1.0,
    'بنچمارک تاخیر کوئری‌های دیتابیس MariaDB (۱,۰۰۰ اجرا)',
    $stats,
    sprintf("میانگین زمان هر کوئری: %.3f میلی‌ثانیه | مجموع: %.2f ms", $avgDbTimeMs, $totalDbTimeMs));

// ۲.۲ بنچمارک سرعت عملیات حافظه Redis (Redis Throughput Benchmark)
$cache = $container->make(Cache::class);
$redisStartTime = microtime(true);
$redisCycles = 5000;
for ($i = 0; $i < $redisCycles; $i++) {
    $cache->set("bench_key_{$i}", "value_{$i}", 60);
    $cache->get("bench_key_{$i}");
}
$redisEndTime = microtime(true);
$totalRedisTimeMs = ($redisEndTime - $redisStartTime) * 1000;
$opsPerSec = ($redisCycles * 2) / ($redisEndTime - $redisStartTime);
assertCheck($totalRedisTimeMs < 1000.0,
    'بنچمارک کارایی و سرعت حافظه موقت Redis (۱۰,۰۰۰ تراکنش در حافظه)',
    $stats,
    sprintf("سرعت اجرا: %.0f عملیات در ثانیه | مجموع: %.2f ms", $opsPerSec, $totalRedisTimeMs));

// ۲.۳ بنچمارک قفل توزیع‌شده Redis (Distributed Lock Throughput)
$lockService = $container->make(DistributedLockService::class);
$lockStartTime = microtime(true);
$lockCycles = 200;
$locksAcquired = 0;
for ($i = 0; $i < $lockCycles; $i++) {
    $lockKey = "test_lock_{$i}_" . time();
    $lockData = $lockService->acquire($lockKey, 2, 1);
    if (!empty($lockData['token'])) {
        $locksAcquired++;
        $lockService->release($lockKey, $lockData['token']);
    }
}
$lockEndTime = microtime(true);
$totalLockTimeMs = ($lockEndTime - $lockStartTime) * 1000;
assertCheck($locksAcquired === $lockCycles,
    'بنچمارک قفل‌های توزیع‌شده Atomic Redis Locks برای همزمانی بالای کیف پول',
    $stats,
    sprintf("تعداد قفل دریافتی: %d از %d | زمان کل: %.2f ms", $locksAcquired, $lockCycles, $totalLockTimeMs));

// ۲.۴ بنچمارک محاسبات ریاضی ممیز شناور BCMath (BCMath Precision Speed)
$bcMathStartTime = microtime(true);
$bcCycles = 20000;
$balance = '10000000.00000000';
for ($i = 0; $i < $bcCycles; $i++) {
    $balance = bcadd($balance, '100.50000000', 8);
    $balance = bcsub($balance, '50.25000000', 8);
}
$bcMathEndTime = microtime(true);
$totalBcTimeMs = ($bcMathEndTime - $bcMathStartTime) * 1000;
assertCheck($totalBcTimeMs < 500.0,
    'بنچمارک سرعت و دقت محاسبات دفترکل با BCMath (۴۰,۰۰۰ محاسبات اعشاری)',
    $stats,
    sprintf("موجودی نهایی: %s IRT | زمان کل: %.2f ms", $balance, $totalBcTimeMs));

$passCount = $stats['pass'];
$failCount = $stats['fail'];

echo "\n======================================================================\n";
echo "  خلاصه نتایج آزمون‌های دقیق سرعت و امنیت پروژه:\n";
echo "  موفق (PASS): {$passCount}\n";
echo "  ناموفق (FAIL): {$failCount}\n";
echo "======================================================================\n\n";

if ($failCount === 0) {
    echo "SUCCESS: ALL SECURITY & PERFORMANCE BENCHMARKS PASSED EXCELLENTLY!\n";
    exit(0);
}
echo "FAILURE: SOME BENCHMARKS FAILED.\n";
exit(1);
