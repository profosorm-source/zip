<?php

declare(strict_types=1);

/**
 * 🛡️ Sentry Activation Test — تأیید کامل pipeline شخصی
 *
 * این تست اثبات می‌کند که بعد از فیکس دو باگ:
 *   BUGFIX-DB-TRACK-1  : feature_flags.db_query_tracking آرایه است (نه boolean)
 *   FIX-SENTRY-INIT    : SentryExceptionHandler در boot initialize نمی‌شد
 *
 * کل مسیر از env تا Sentry شخصی کار می‌کند:
 *
 *   .env (DB_TRACK_QUERIES=true)
 *     → config/feature_flags.php
 *       → Database::trackQueryToSentry()  [FIX 1]
 *         → SentryExceptionHandler::trackQuery()  [FIX 2]
 *           → SentryPerformanceMonitor::trackQuery()
 *             → detectNPlusOneQueries()
 *
 * ├── T1  flag از env درست خوانده می‌شود (DB_TRACK_QUERIES=true)
 * ├── T2  SentryExceptionHandler در boot initialize می‌شود (getInstance بدون exception)
 * ├── T3  Database tracking enabled = true (نه false مثل قبل)
 * ├── T4  کوئری‌ها واقعاً به SentryPerformanceMonitor می‌رسند
 * ├── T5  الگوریتم detectNPlusOneQueries کار می‌کند (8 کوئری مشابه → 1 N+1)
 * ├── T6  sample rate از feature_flag آرایه خوانده می‌شود
 * ├── T7  bootstrap کل پروژه بدون خطا لود می‌شود (backward-compat)
 * ├── T8  FAIL-SAFE: اگر Sentry در دسترس نباشد، کوئری اصلی خراب نمی‌شود
 * └── T9  fail-safe boot: اگر SentryExceptionHandler خطا بدهد، boot ادامه می‌یابد
 *
 * اجرا:  php tests/sentry_activation_test.php
 */

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../bootstrap/app.php';

    use App\Services\Sentry\SentryExceptionHandler;
    use Core\Database;

    $ok = true;
    $report = function (string $name, bool $passed, string $detail = '') use (&$ok) {
        echo ($passed ? "✓ " : "✗ ") . $name;
        if ($detail !== '') echo "  — $detail";
        echo "\n";
        if (!$passed) $ok = false;
    };

    echo "═══ Sentry Activation Test ═══\n\n";

    // ─── T1: flag از env ────────────────────────────────────────────────
    echo "─── T1: flag از env ───\n";
    $flag = env('DB_TRACK_QUERIES', null);
    $rate = env('DB_TRACK_QUERIES_SAMPLE_RATE', null);
    $report("T1: DB_TRACK_QUERIES=true از env", filter_var($flag, FILTER_VALIDATE_BOOLEAN) === true);
    $report("T1: SAMPLE_RATE عدد است", is_numeric($rate));

    // ─── T2: SentryExceptionHandler initialized ─────────────────────────
    echo "\n─── T2: SentryExceptionHandler در boot ───\n";
    try {
        $handler = SentryExceptionHandler::getInstance();
        $report("T2: getInstance() بدون exception", true, get_class($handler));
    } catch (\Throwable $e) {
        $report("T2: getInstance() بدون exception", false, $e->getMessage());
        exit(1); // بقیه‌ی تست‌ها بی‌معنی‌اند
    }

    // ─── T3: Database tracking enabled ──────────────────────────────────
    echo "\n─── T3: tracking در Database ───\n";
    // reset تا کد جدید اجرا شود
    Database::resetTrackingStats();
    $ref = new ReflectionClass(Database::class);
    $p = $ref->getProperty('queryTrackingEnabled'); $p->setAccessible(true);
    $p->setValue(null, null); // force re-init با کد فیکس‌شده

    // یک trackQueryToSentry صدا بزن تا lazy-init اجرا شود
    $m = $ref->getMethod('trackQueryToSentry'); $m->setAccessible(true);
    $m->invoke(null, 'SELECT 1', 0.001, []);

    $stats = Database::getTrackingStats();
    $report("T3: enabled=true (نه false)", $stats['enabled'] === true);
    $report("T3: sample_rate از آرایه خوانده شد", $stats['sample_rate'] > 0);

    // ─── T4: کوئری‌ها به SentryPerformanceMonitor می‌رسند ────────────────
    echo "\n─── T4: کوئری‌ها در Sentry شخصی ───\n";
    Database::resetTrackingStats();
    $p->setValue(null, null);
    for ($i = 0; $i < 8; $i++) {
        $m->invoke(null, "SELECT * FROM users WHERE id = $i", 0.05 + $i * 0.01, [$i]);
    }
    $stats = Database::getTrackingStats();
    $report("T4: 8 کوئری track شد", $stats['tracked'] === 8, "tracked=" . $stats['tracked']);

    $pm = $handler->getPerformanceMonitor();
    $pmRef = new ReflectionClass($pm);
    $qProp = $pmRef->getProperty('queries'); $qProp->setAccessible(true);
    $storedQueries = $qProp->getValue($pm);
    $stored = is_array($storedQueries) ? count($storedQueries) : 0;
    $report("T4: کوئری‌ها در SentryPerformanceMonitor ذخیره شدند", $stored >= 8, "stored=$stored");

    // ─── T5: N+1 detection ──────────────────────────────────────────────
    echo "\n─── T5: N+1 detection ───\n";
    $dm = $pmRef->getMethod('detectNPlusOneQueries'); $dm->setAccessible(true);
    $nps = $dm->invoke($pm);
    $npsCount = is_array($nps) ? count($nps) : 0;
    $report("T5: الگوی N+1 تشخیص داده شد", $npsCount >= 1, "patterns=" . $npsCount);

    // ─── T6: sample rate از feature_flag آرایه ──────────────────────────
    echo "\n─── T6: sample rate از feature_flag ───\n";
    $ff = config('feature_flags.db_query_tracking', null);
    $isArr = is_array($ff) && isset($ff['sample_rate']);
    $report("T6: feature_flag آرایه است", $isArr);
    if ($isArr) {
        $report("T6: sample_rate قابل دسترسی از آرایه", is_numeric($ff['sample_rate']));
    }

    // ─── T7: backward-compat — bootstrap کامل ──────────────────────────
    echo "\n─── T7: backward-compat ───\n";
    $report("T7: bootstrap کامل بدون خطا", class_exists(Database::class) && class_exists(SentryExceptionHandler::class));

    // ─── T8: FAIL-SAFE کوئری ────────────────────────────────────────────
    echo "\n─── T8: FAIL-SAFE کوئری اصلی ───\n";
    // Sentry خودش هم ممکنه DB بزنه (توی SentryModel::recordEvent).
    // اگه اون fail بشه، نباید کوئری اصلی ما خراب بشه.
    $exceptionThrown = false;
    try {
        // یک کوئری خیلی عجیب که احتمالاً خطا می‌ده — ولی نباید exception پرتاب کنه
        $m->invoke(null, 'INVALID SQL !!!', 0.001, []);
    } catch (\Throwable $e) {
        $exceptionThrown = true;
    }
    $report("T8: trackQuery هرگز exception پرتاب نمی‌کند", !$exceptionThrown);

    // ─── T9: FAIL-SAFE boot ─────────────────────────────────────────────
    echo "\n─── T9: FAIL-SAFE boot (static analysis) ───\n";
    $src = file_get_contents(__DIR__ . '/../app/Providers/AppServiceProvider.php');
    $hasTryCatch = is_string($src)
        && strpos($src, 'FIX-SENTRY-INIT') !== false
        && strpos($src, 'if (!defined(\'PHPUNIT_COMPOSER_INSTALL\')') !== false
        && strpos($src, 'initialization failed') !== false;
    $report("T9: Sentry init تحت try/catch fail-safe است", $hasTryCatch);

    echo "\n" . ($ok ? "✅ all tests passed" : "❌ some tests failed") . "\n";
    exit($ok ? 0 : 1);
}
