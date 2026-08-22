<?php
/**
 * روتر سرور توسعه PHP (php -S) — هم‌ترازسازی با nginx/Apache
 *
 * مسئله‌ای که حل می‌کند
 * ---------------------
 * سرور داخلی PHP برای URIهایی که به پسوند فایل ختم می‌شوند، مقدار
 * SCRIPT_NAME را برابر «کل مسیر درخواست» قرار می‌دهد، نه اسکریپت واقعی.
 * نمونهٔ اندازه‌گیری‌شده:
 *
 *   REQUEST_URI = /file/view/captcha/captcha_1988e8e375ef3c80.png
 *   SCRIPT_NAME = /file/view/captcha/captcha_1988e8e375ef3c80.png   ← نادرست
 *
 * از سوی دیگر core/Request::parseUri() وقتی APP_BASE_PATH خالی باشد،
 * مسیر پایه را از dirname(SCRIPT_NAME) حدس می‌زند. در نتیجه
 * «/file/view/captcha» به‌عنوان base path از URI بریده می‌شود و روتر فقط
 * «/captcha_1988e8e375ef3c80.png» را می‌بیند → هیچ روتی تطبیق نمی‌یابد →
 * ۴۰۴ سراسری.
 *
 * پیامد عملی: تصویر کپچای صفحهٔ ورود همیشه ۴۰۴ می‌گرفت و تست‌های مرورگری
 * (لایه ۷) که خطاهای شبکه را رصد می‌کنند شکست می‌خوردند.
 *
 * این ایراد محصول نیست: در nginx و Apache مقدار SCRIPT_NAME برابر
 * /index.php است و مسیر پایه درست محاسبه می‌شود. بنابراین به‌جای دستکاری
 * کد محصول، اینجا محیط توسعه با محیط واقعی هم‌تراز می‌شود.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// فایل‌های ایستای واقعی را خود سرور داخلی سرو کند
$publicFile = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($publicFile) && !str_ends_with($publicFile, '.php')) {
    return false;
}

// ── ایندکس دایرکتوری (DirectoryIndex) ─────────────────────────────────────
// nginx و Apache برای درخواستِ یک دایرکتوری، فایل index.php داخل آن را اجرا
// می‌کنند. نمونهٔ واقعی در این پروژه: نصب‌کنندهٔ گرافیکی مستقل در
// public/install/index.php که در روتر فریم‌ورک ثبت نشده است.
// سرور داخلی PHP چنین کاری نمی‌کند و درخواست را به همین روتر می‌سپارد؛
// در نتیجه «/install/» به front controller می‌رسید و ۴۰۴ می‌گرفت — یعنی
// یک تفاوت محیطی، نه ایراد محصول. اینجا همان رفتار وب‌سرور واقعی بازسازی
// می‌شود تا اسکریپت‌های مستقلِ داخل public قابل آزمون باشند.
$dirIndex = __DIR__ . '/public' . rtrim($path, '/') . '/index.php';
if ($path !== '/' && is_file($dirIndex)) {
    $rel = rtrim($path, '/') . '/index.php';
    $_SERVER['SCRIPT_NAME']     = $rel;
    $_SERVER['PHP_SELF']        = $rel;
    $_SERVER['SCRIPT_FILENAME'] = $dirIndex;
    require $dirIndex;
    return true;
}

// هم‌ترازسازی با front controller واقعی
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';

require __DIR__ . '/public/index.php';
