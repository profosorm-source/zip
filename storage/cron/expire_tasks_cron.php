<?php
// cron/expire_tasks.php
// اجرا: هر 5 دقیقه
// crontab: */5 * * * * php /path/to/cron/expire_tasks.php


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

use App\Models\TaskExecution;

echo "[" . date('Y-m-d H:i:s') . "] Expiring overdue tasks...\n";

$expired = TaskExecution::expireOverdue();

echo "Expired: {$expired} tasks\n";