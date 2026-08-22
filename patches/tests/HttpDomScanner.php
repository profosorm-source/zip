<?php

declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع اسکن جامع و عمیق خروجی بصری و DOM تمامی صفحات (HTTP Body Scanner)\n";
echo "======================================================================\n\n";

$configuredBaseUrl = getenv('CHORTKE_E2E_BASE_URL');
$baseUrl = rtrim(is_string($configuredBaseUrl) && $configuredBaseUrl !== '' ? $configuredBaseUrl : 'http://127.0.0.1:8080', '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'chortke_cookie_');

function httpGet(string $url, string $cookieFile, string $sessionId = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($sessionId !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, "CHORTKE_SESSION={$sessionId}; CHORTKE_ADM_SESS={$sessionId}");
    } else {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

function httpPost(string $url, array $data, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

// 1. ایجاد و اعتبارسنجی نشست ادمین برای اسکنر
$sessionService = app(\Core\Session::class);
$sessionService->start();
$sessionService->set('user_id', 3);
$sessionService->set('user_role', 'super_admin');
$sessionService->set('is_admin', true);
$sessionService->set('logged_in', true);
$sessionService->set('admin_verify_time', time());
$sessionService->set('user_verify_time', time());
$sessionId = session_id();

try {
    $securityModel = app(\App\Models\SecurityModel::class);
    $securityModel->upsertSession([
        'user_id' => 3,
        'session_id' => $sessionId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'HttpDomScanner/1.0',
        'device_type' => 'desktop',
        'browser' => 'CLI',
        'os' => 'Debian',
        'fingerprint' => 'scanner_fingerprint_test',
    ]);

    $redis = app(\Core\Redis::class);
    if ($redis && $redis->isAvailable()) {
        $redis->set("user_verify:3", (string)time(), 600);
        $redis->set("admin_verify:3", (string)time(), 600);
        $redis->set("session:activity:{$sessionId}", (string)time(), 600);
    }
} catch (\Throwable $e) {}

// 🛡️ Flush session to Redis/File storage before sending cURL request!
session_write_close();

$cookieContent = "127.0.0.1\tFALSE\t/\tFALSE\t0\tCHORTKE_SESSION\t{$sessionId}\n";
file_put_contents($cookieJar, $cookieContent);

// ۲. لیست تمام آدرس‌های کلیدی سیستم
$urlsToScan = [
    '/dashboard',
    '/profile',
    '/kyc',
    '/tasks',
    '/custom-tasks',
    '/social-tasks',
    '/seo',
    '/social-accounts',
    '/lottery',
    '/investment',
    '/notifications',
    '/admin/dashboard',
    '/admin/users',
    '/admin/custom-tasks',
    '/admin/social-tasks',
    '/admin/seo-ad',
    '/admin/lottery',
    '/admin/investment',
    '/admin/risk-policies',
    '/admin/logs',
    '/admin/database-health',
    '/admin/kpi',
    '/admin/analytics/custom-tasks',
    '/admin/analytics/social-tasks',
];

$pass = 0;
$fail = 0;

$errorKeywords = [
    "Table '",
    "doesn't exist",
    "SQLSTATE[",
    "Fatal error:",
    "TypeError:",
    "ErrorException:",
    "Undefined variable",
    "Cannot use object of type stdClass as array",
    "Call to undefined function",
];

foreach ($urlsToScan as $path) {
    $response = httpGet($baseUrl . $path, $cookieJar, $sessionId);
    $html = $response['body'];
    $status = $response['status'];
    
    $detectedErrors = [];
    foreach ($errorKeywords as $keyword) {
        if (str_contains($html, $keyword)) {
            $detectedErrors[] = $keyword;
        }
    }
    
    if ($status === 200 && empty($detectedErrors)) {
        $pass++;
        echo "  [PASS] {$path} (HTTP 200 - HTML Body Clean & Error-Free)\n";
    } else {
        $fail++;
        $reason = !empty($detectedErrors) ? "دیده‌شدن خطای متنی در HTML: " . implode(', ', $detectedErrors) : "کد وضعیت HTTP {$status}";
        echo "  [FAIL] {$path} -> {$reason}\n";
    }
}

@unlink($cookieJar);

echo "\n======================================================================\n";
echo "  خلاصه نتایج اسکن بصری و DOM خروجی HTML صفحات:\n";
echo "  موفق و بدون خطای متنی (PASS): {$pass}\n";
echo "  ناموفق یا حاوی خطا (FAIL): {$fail}\n";
echo "======================================================================\n\n";

if ($fail === 0) {
    echo "SUCCESS: ALL HTML RESPONSES ARE COMPLETELY CLEAN AND ERROR-FREE!\n";
    exit(0);
} else {
    echo "FAILURE: SOME HTML RESPONSES CONTAINED ERRORS.\n";
    exit(1);
}
