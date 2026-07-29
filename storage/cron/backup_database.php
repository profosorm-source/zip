<?php

/**
 * Cron Job: Database Backup + Telegram
 * اجرا: روزانه ساعت 2 صبح
 */

// ─── CLI ONLY ───────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only access denied.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting database backup...\n";

// ─── Telegram Sender ───────────────────────────────────────
function sendToTelegram(string $filePath, string $caption = ''): bool
{
    $token = env('TELEGRAM_BOT_TOKEN');
    $chatId = env('TELEGRAM_CHAT_ID');

    if (!$token || !$chatId || !file_exists($filePath)) {
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendDocument";

    $postFields = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => new CURLFile($filePath),
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return false;
    }

    $result = json_decode($response, true);

    return isset($result['ok']) && $result['ok'] === true;
}

try {

    // ─── DB CONFIG ───────────────────────────────────────────
    $dbHost = env('DB_HOST');
    $dbName = env('DB_NAME');
    $dbUser = env('DB_USER');
    $dbPass = env('DB_PASS');

    if (!$dbHost || !$dbName || !$dbUser) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    // ─── BACKUP DIRECTORY ────────────────────────────────────
    $backupPath = __DIR__ . '/../backups/';

    if (!is_dir($backupPath) && !mkdir($backupPath, 0755, true)) {
        throw new RuntimeException('Cannot create backup directory.');
    }

    // ─── FILE NAME ───────────────────────────────────────────
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupPath . $filename;

    // ─── SAFE mysqldump (NO SHELL) ───────────────────────────
    $mysqldump = config('database.mysqldump_path', 'mysqldump');
    $cmd = [
        $mysqldump,
        "--host={$dbHost}",
        "--user={$dbUser}",
        "--password={$dbPass}",
        $dbName
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $filepath, 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start backup process.');
    }

    fclose($pipes[0]);

    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !file_exists($filepath)) {
        throw new RuntimeException('Backup failed: ' . $errorOutput);
    }

    // ─── COMPRESS ────────────────────────────────────────────
    $gzFilepath = $filepath . '.gz';

    $data = file_get_contents($filepath);
    if ($data === false) {
        throw new RuntimeException('Failed to read backup file.');
    }

    file_put_contents($gzFilepath, gzencode($data, 9));
    unlink($filepath);

    chmod($gzFilepath, 0600);

    $fileSize = filesize($gzFilepath);

    echo "✓ Backup created: " . basename($gzFilepath) .
        " (" . round($fileSize / 1024 / 1024, 2) . " MB)\n";

    // ─── SEND TO TELEGRAM ────────────────────────────────────
    if ($fileSize <= 50 * 1024 * 1024) {

        $caption = "📦 Database Backup\n"
            . "🗓 " . date('Y-m-d H:i:s') . "\n"
            . "💾 Size: " . round($fileSize / 1024 / 1024, 2) . " MB";

        $sent = false;

        for ($i = 0; $i < 3; $i++) {
            if (sendToTelegram($gzFilepath, $caption)) {
                echo "✓ Sent to Telegram\n";
                $sent = true;
                break;
            }
            sleep(2);
        }

        if (!$sent) {
            echo "⚠ Failed to send to Telegram\n";
        }

    } else {
        echo "⚠ File too large for Telegram\n";
    }

    // ─── CLEAN OLD BACKUPS ───────────────────────────────────
    $files = glob($backupPath . 'backup_*.{sql,gz}', GLOB_BRACE);
    $deleted = 0;

    foreach ($files as $file) {
        if (is_file($file) && (time() - filemtime($file)) > 30 * 24 * 60 * 60) {
            unlink($file);
            $deleted++;
        }
    }

    if ($deleted > 0) {
        echo "✓ Deleted {$deleted} old backup(s)\n";
    }

    // ─── LOG ─────────────────────────────────────────────────
    if (function_exists('logger')) {
    $this->logger->info('Database backup completed', [
        'file' => basename($gzFilepath),
        'size' => $fileSize
    ]);
}

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;

    if (function_exists('logger')) {
        $this->logger->error('cron.database_backup.failed', [
            'channel' => 'cron',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->logger->error('[BACKUP ERROR] ' . $e->getMessage());
    }

    exit(1);
}