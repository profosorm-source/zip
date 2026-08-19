<?php
declare(strict_types=1);


// ─── CLI-only guard ───────────────────────────────────────────────────────────
// این فایل فقط از طریق CLI (crontab/shell) قابل اجرا است.
// دسترسی مستقیم از مرورگر یا HTTP ممنوع است.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}
// ─────────────────────────────────────────────────────────────────────────────

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/core/Autoloader.php';
require_once BASE_PATH . '/bootstrap/app.php';
$app = \Core\Application::getInstance();

use App\Services\EmailService;

$service = app(EmailService::class);
$result = $service->processQueue(10);

echo "Email Queue Processed: " . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;