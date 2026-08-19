<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/core/Autoloader.php';
require_once BASE_PATH . '/bootstrap/app.php';

// راه‌اندازی اپلیکیشن (DI container)
$app = \Core\Application::getInstance();

// EmailService را از DI container بگیر — نه با new مستقیم
$emailService = $app->make(\App\Services\EmailService::class);
$result = $emailService->processQueue(10);

echo "Email Queue Processed: " . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
