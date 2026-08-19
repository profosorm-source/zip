<?php
// cron/create_rechecks.php
// اجرا: روزانه یکبار
// crontab: 0 3 * * * php /path/to/cron/create_rechecks.php


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

use App\Services\TaskRecheckService;

echo "[" . date('Y-m-d H:i:s') . "] Creating task rechecks...\n";

$service = app(TaskRecheckService::class);
$result = $service->createRechecks(50);

echo "Created: {$result['created']} rechecks\n";