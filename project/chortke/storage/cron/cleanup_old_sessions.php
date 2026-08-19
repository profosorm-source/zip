<?php

/**
 * Cron Job: پاکسازی Session های قدیمی
 *
 * اجرا: روزانه در ساعت 3 صبح
 * دستور Cron: 0 3 * * * php /path/to/project/storage/cron/cleanup_old_sessions.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\CronService;

echo "[" . date('Y-m-d H:i:s') . "] Cleaning up old sessions...\n";

try {
    $cron         = app(CronService::class);
    $deletedCount = $cron->deleteOldSessions(7); // بیش از 7 روز

    echo "✓ Deleted {$deletedCount} old session(s).\n";

    // پاکسازی فایل‌های Session از دیسک (اختیاری)
    $sessionPath = __DIR__ . '/../../storage/sessions/';
    if (is_dir($sessionPath)) {
        $files     = glob($sessionPath . 'sess_*');
        $fileCount = 0;

        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file)) > (7 * 24 * 60 * 60)) {
                unlink($file);
                $fileCount++;
            }
        }

        echo "✓ Deleted {$fileCount} session file(s).\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    $this->logger->error('cron.sessions.cleanup.failed', [
        'channel' => 'cron',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed.\n";
exit(0);
