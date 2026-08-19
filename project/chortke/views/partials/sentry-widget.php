<?php
/**
 * 🛡️ Sentry Widget - نمایش در داشبورد اصلی
 * 
 * این فایل باید در views/admin/dashboard.php اضافه بشه
 */

use App\Services\Sentry\Analytics\DashboardService;
use Core\Database;

try {
    // 🔐 Architectural Fix: Purged raw Database access from partial View layer
    $sentryDashboard = app(DashboardService::class);
    
    // M29 Fix: واکشی یکپارچه از بافر کش ۵ دقیقه‌ای جهت حذف صددرصدی تاخیر در رندر داشبورد اصلی
    $overview = $sentryDashboard->getOverview();
    $sentryHealth = $overview['health_score'] ?? ['score' => 0, 'grade' => 'F', 'status' => 'unknown'];
    $sentryStats  = $overview['error_stats']  ?? ['total_issues' => 0, 'total_events' => 0];
    $cronStatus   = $overview['cron_status']   ?? ['status' => 'unknown'];
} catch (\Throwable $e) {
    $sentryHealth = ['score' => 0, 'grade' => 'F', 'status' => 'unknown'];
    $sentryStats  = ['total_issues' => 0, 'total_events' => 0];
    $cronStatus   = ['status' => 'unknown'];
}
?>

<div class="sentry-widget">
    <div class="sentry-header">
        <h3 class="sentry-title">
            🛡️ سلامت سیستم
            <?php
                // M28 Fix: نمایش هوشمند نبض زنده کرون‌جاب سرور با استفاده از چراغ LED داینامیک
                $cronColor = match($cronStatus['status'] ?? 'unknown') {
                    'healthy'  => '#10b981',
                    'warning'  => '#f59e0b',
                    'critical' => '#ef4444',
                    default    => '#94a3b8'
                };
                $cronLabel = 'کرون‌جاب: ' . ($cronStatus['message'] ?? 'نامعلوم');
            ?>
            <span title="<?= e($cronLabel) ?>" class="sentry-dot" data-dot="<?= $cronColor ?>" ></span>
        </h3>
<a href="/admin/sentry" class="sentry-link">مشاهده کامل →</a>
    </div>

    <div class="sentry-grid-main">
        <!-- Health Score Circle -->
        <div class="sentry-score-circle" >
                <?= round($sentryHealth['score'] ?? 0) ?>
            </div>
            <div class="sentry-score-label">
                نمره: <?= $sentryHealth['grade'] ?? 'N/A' ?>
            </div>
        </div>

        <!-- Stats -->
        <div class="sentry-stats-grid">
            <div class="sentry-stat-box sentry-stat-red">
                <div class="sentry-stat-num">
                    <?= number_format($sentryStats['total_issues'] ?? 0) ?>
                </div>
                <div class="sentry-stat-label">
                    خطاهای فعال
                </div>
            </div>

            <div class="sentry-stat-box sentry-stat-green">
                <div class="sentry-stat-num">
                    <?= number_format($sentryStats['total_events'] ?? 0) ?>
                </div>
                <div class="sentry-stat-label">
                    رویداد (24 ساعت)
                </div>
            </div>

            <div class="sentry-stat-box sentry-levels sentry-stat-warn">
                <div>
                    <?php
                    $critical = $sentryStats['by_level']['critical']['events'] ?? 0;
                    $error = $sentryStats['by_level']['error']['events'] ?? 0;
                    $warning = $sentryStats['by_level']['warning']['events'] ?? 0;
                    ?>
                    <span>🔴 <?= $critical ?> Critical</span> | 
                    <span>🟠 <?= $error ?> Error</span> | 
                    <span>🟡 <?= $warning ?> Warning</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="sentry-footer">
        <a href="/admin/sentry/issues" class="sentry-footer-link" data-hover-bg="#edf2f7">
            🐛 خطاها
        </a>
        <a href="/admin/sentry/performance" class="sentry-footer-link" data-hover-bg="#edf2f7">
            🚀 عملکرد
        </a>
        <a href="/admin/sentry/alerts" class="sentry-footer-link" data-hover-bg="#edf2f7">
            🔔 هشدارها
        </a>
    </div>
</div>


