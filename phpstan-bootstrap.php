<?php

/**
 * PHPStan Bootstrap File
 *
 * این فایل قبل از آنالیز PHPStan اجرا می‌شود.
 * vendor/autoload را load می‌کند تا همه کلاس‌ها شناخته شوند.
 */

// Suppress output during static analysis
define('PHPSTAN_RUNNING', true);

// Load Composer autoloader
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Load helper functions (بدون اجرای کد bootstrap اصلی)
$functions = __DIR__ . '/helpers/functions.php';
if (file_exists($functions)) {
    require_once $functions;
}

// Load Constants
$constants = __DIR__ . '/app/Constants/MagicNumbers.php';
if (file_exists($constants)) {
    require_once $constants;
}

// Define BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
