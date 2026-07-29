<?php

/**
 * Cron: تایید خودکار اسکروهای منقضی در ویترین
 *
 * اجرا: هر ۶ ساعت یکبار
 * دستور Cron:
 *   0 *\/6 * * * 
 /usr/bin/php /var/www/html/storage/cron/vitrine_auto_confirm.php >> /var/log/vitrine-cron.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\VitrineService;
use Core\Container;

$container = Container::getInstance();

echo "\n[" . date('Y-m-d H:i:s') . "] === VITRINE AUTO-CONFIRM CRON ===\n";

try {
    $service = $container->make(VitrineService::class);
    $results = $service->processExpiredEscrows();

    echo "[" . date('Y-m-d H:i:s') . "] پردازش شد: {$results['processed']} | خطا: {$results['errors']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] === پایان ===\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
