<?php

declare(strict_types=1);

/**
 * cli.php
 * نقطه ورود ابزارهای خط فرمان پروژه چرتکه (Enterprise Daemonized CLI Engine)
 */

if (php_sapi_name() !== 'cli') {
    die("Only CLI access allowed.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// 🛡️ سپر امنیتی اعتبارسنجی مالکیت فرآیند در لینوکس (POSIX Root Execution Guard):
// هشدار به سیستم ادمین در صورت اجرای دستورات با دسترسی کاربر root (sudo) جهت جلوگیری از تخریب دسترسی فایل‌های کش و لاگ (www-data)
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    $allowRoot = in_array('--allow-root', $argv, true);
    if (!$allowRoot) {
        $msg = "\n⚠️ [Security Warning] You are executing CLI commands as 'root' (UID 0).\n";
        $msg .= "This can cause severe file permission issues (Permission denied) for cache and log files generated for 'www-data'.\n";
        $msg .= "If you are sure you want to run as root, pass the '--allow-root' flag.\n\n";
        if (defined('STDERR')) {
            fwrite(STDERR, $msg);
            fflush(STDERR);
        } else {
            echo $msg;
        }
        exit(1);
    }
}

// 🛡️ اطمینان از فعال بودن سیستم مدیریت حافظه و پاکسازی اشیاء حلقوی (Circular Reference GC)
// جهت جلوگیری از اتمام حافظه (OOM) در دیمن‌های طولانی‌مدت پس‌زمینه
if (function_exists('gc_enable')) {
    gc_enable();
}

// 🛡️ سپر محافظتی خروجی بلادرنگ و لغو بافرینگ (Real-Time Unbuffered TTY Logging Guard):
// بستن تمامی بافرهای فعال خروجی و فعال‌سازی تخلیه بلادرنگ جهت رصد فوری لاگ‌ها در دیمن‌های داکر و کوبرنتیز
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
}

// 🛡️ تحکیم محدودیت‌های زمانی و حافظه‌ای دیمن‌ها (Daemon Execution Limits Guard):
// لغو محدودیت زمانی اجرای اسکریپت و ارتقای سقف حافظه جهت پردازش صف‌های سنگین
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '0');

// 🛡️ تفکیک جریان خروجی خطاهای مفسر (STDERR Engine Error Routing Guard):
// هدایت مستقیم هشدارهای مفسر PHP به بافر STDERR جهت جلوگیری از آلوده شدن خروجی استاندارد
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

// جلوگیری از اجرای هم‌زمان دستورات مشابه و انباشت پردازش‌ها (Fork Bomb & Concurrency Protection)
$commandName = isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1]) : 'default';
$lockDir = __DIR__ . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/cli_' . $commandName . '.lock';

$lockFp = @fopen($lockFile, 'c');
if ($lockFp) {
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo "⚠️ Command '{$commandName}' is already running. Preventing concurrent execution to avoid resource exhaust.\n";
        @fclose($lockFp);
        exit(0);
    }
}

// 🛡️ ارتقای کلیدی معماری دیمن‌های لینوکسی (POSIX Signal Handling & Graceful Shutdown Guard):
// ثبت هندلرهای سیگنال جهت جلوگیری از مرگ ناگهانی ورکرها در زمان ری‌استارت سرور یا دیپلوی کدهای جدید (SIGTERM / SIGINT)
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = function (int $signal) use ($lockFp, $lockFile, $commandName) {
        $sigName = $signal === SIGTERM ? 'SIGTERM' : ($signal === SIGINT ? 'SIGINT' : 'SIGHUP');
        $msg = "\n⚠️ [CLI Daemon] Received signal {$sigName}. Initiating graceful shutdown for command '{$commandName}'...\n";
        if (defined('STDERR')) {
            fwrite(STDERR, $msg);
            fflush(STDERR);
        } else {
            echo $msg;
        }
        if (isset($lockFp) && is_resource($lockFp)) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            @unlink($lockFile);
        }
        exit(142); // 128 + 14 (SIGALRM/SIGTERM standard exit code)
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGHUP, $signalHandler);
}

// 🛡️ سپر محافظتی تخلیه قطعی قفل‌ها و رهگیری خطاهای کشنده در زمان کرش‌های مهلک (Fatal Crash Guard)
register_shutdown_function(function () use ($lockFp, $lockFile) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $fatalMsg = "\n🔥 [CLI Fatal Crash] Type: {$error['type']} | Message: {$error['message']} | File: {$error['file']}:{$error['line']}\n";
        if (defined('STDERR')) {
            fwrite(STDERR, $fatalMsg);
            fflush(STDERR);
        } else {
            echo $fatalMsg;
        }
    }
    if (isset($lockFp) && is_resource($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
        @unlink($lockFile);
    }
});

// بارگذاری bootstrap
require_once __DIR__ . '/bootstrap/app.php';

use Core\Container;

try {
    $dispatcher = Container::getInstance()->make(\Core\Console\CliDispatcher::class);
    $dispatcher->run($argv);
} catch (\Throwable $e) {
    $errorMsg = "❌ Error executing command: " . $e->getMessage() . "\n";
    if (defined('STDERR')) {
        fwrite(STDERR, $errorMsg);
        fflush(STDERR);
    } else {
        echo $errorMsg;
    }
    if (isset($lockFp) && is_resource($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
        @unlink($lockFile);
    }
    exit(1);
}

if (isset($lockFp) && is_resource($lockFp)) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    @unlink($lockFile);
}
