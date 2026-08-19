<?php
/**
 * Cache Flush Helper — پاک‌سازی کامل cache برای تست‌ها
 * این فایل از CLI اجرا می‌شود تا risk scores و captcha state را ریست کند.
 */
require_once __DIR__ . '/../bootstrap/app.php';

$c = \Core\Container::getInstance()->make(\Core\Cache::class);
$c->flush();
echo 'OK';
