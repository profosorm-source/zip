<?php
$title = 'مدیریت Cron Jobs';
ob_start();
?>
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">⏱ مدیریت Cron Jobs</h4>
        <button class="btn btn-success" data-click="runCron" id="runBtn">
            <span class="material-icons align-middle">play_arrow</span>
            اجرای همه
        </button>
    </div>

    <!-- دستور crontab -->
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning text-dark">
            <strong>⚙️ تنظیم در سرور (crontab)</strong>
        </div>
        <div class="card-body">
            <p class="mb-2 small">این دستور را به crontab سرور اضافه کنید تا هر دقیقه اجرا شود:</p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace bg-dark text-light"
                       value="* * * * * /usr/bin/php <?= BASE_PATH ?>/cron.php >> /var/log/chortke-cron.log 2>&1"
                       id="cronCmd" readonly>
                <button class="btn btn-outline-secondary" data-click="copyCron">
                    <span class="material-icons align-middle">content_copy</span> کپی
                </button>
            </div>
        </div>
    </div>

    <!-- وضعیت job ها -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h6 class="mb-0">وضعیت Job ها</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>نام Job</th>
                            <th>زمانبندی</th>
                            <th>آخرین اجرا</th>
                            <th>وضعیت</th>
                            <th>اجرای دستی</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $jobs = [
                            ['name' => 'email_queue',            'schedule' => 'هر دقیقه',        'desc' => 'پردازش صف ایمیل'],
                            ['name' => 'crypto_verify',          'schedule' => 'هر دقیقه',        'desc' => 'تأیید واریز کریپتو'],
                            ['name' => 'cache_cleanup',          'schedule' => 'هر ۵ دقیقه',      'desc' => 'پاک‌سازی cache منقضی'],
                            ['name' => 'expire_ads',             'schedule' => 'هر ساعت',         'desc' => 'انقضای آگهی‌ها'],
                            ['name' => 'expire_banners',         'schedule' => 'هر ساعت',         'desc' => 'انقضای بنرها'],
                            ['name' => 'cleanup_sessions',       'schedule' => 'هر ساعت',         'desc' => 'پاک‌سازی نشست‌های قدیمی'],
                            ['name' => 'cleanup_password_resets','schedule' => 'هر ساعت',         'desc' => 'پاک‌سازی توکن‌های reset'],
                            ['name' => 'user_levels',            'schedule' => 'روزانه ۰۲:۰۰',   'desc' => 'بررسی سطح کاربران'],
                            ['name' => 'cleanup_logs',           'schedule' => 'روزانه ۰۲:۳۰',   'desc' => 'پاک‌سازی لاگ‌های قدیمی'],
                            ['name' => 'cleanup_email_queue',    'schedule' => 'روزانه ۰۳:۰۰',   'desc' => 'پاک‌سازی ایمیل‌های قدیمی'],
                            ['name' => 'cleanup_kyc_files',      'schedule' => 'روزانه ۰۳:۳۰',   'desc' => 'حذف فایل‌های KYC رد شده'],
                            ['name' => 'scheduled_payments',     'schedule' => 'روزانه ۰۳:۱۵',   'desc' => 'اجرای پرداخت‌های زمان‌بندی‌شده رسیده'],
                            ['name' => 'monthly_level_reset',    'schedule' => 'اول ماه ۰۴:۰۰', 'desc' => 'ریست آمار ماهانه سطح'],
                            ['name' => 'weekly_kpi_report',      'schedule' => 'یکشنبه ۰۵:۰۰',  'desc' => 'گزارش هفتگی KPI'],
                        ];
                        foreach ($jobs as $job):
                            $lockFile   = BASE_PATH . '/storage/cron/' . md5($job['name']) . '.lock';
                            $lastRun    = file_exists($lockFile) ? (int)file_get_contents($lockFile) : null;
                            $lastRunStr = $lastRun ? to_jalali(date('Y-m-d H:i:s', $lastRun)) : 'هرگز';
                            $isRecent   = $lastRun && (time() - $lastRun) < 3600;
                        ?>
                        <tr id="row-<?= e($job['name']) ?>">
                            <td>
                                <strong><?= e($job['name']) ?></strong>
                                <div class="small text-muted"><?= e($job['desc']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= e($job['schedule']) ?></span></td>
                            <td class="small text-muted last-run-<?= e($job['name']) ?>"><?= e($lastRunStr) ?></td>
                            <td class="status-<?= e($job['name']) ?>">
                                <?php if ($lastRun === null): ?>
                                    <span class="badge bg-secondary">اجرا نشده</span>
                                <?php elseif ($isRecent): ?>
                                    <span class="badge bg-success">فعال</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">قدیمی</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                        data-click="runSingle" data-args="<?= e($job['name']) ?>" data-pass-el
                                        title="اجرای اجباری این job">
                                    <span class="material-icons">play_circle</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- خروجی اجرا -->
    <div id="cronOutput" class="card border-0 shadow-sm mt-4 d-none">
        <div class="card-header bg-dark text-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0">خروجی اجرا</h6>
            <button class="btn btn-sm btn-outline-light" data-toggle-class="d-none" data-target="#cronOutput" data-mode="add">بستن</button>
        </div>
        <div class="card-body bg-dark text-light font-monospace small" id="cronResult">
        </div>
    </div>

</div>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
