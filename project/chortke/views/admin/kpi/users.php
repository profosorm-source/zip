<?php $title = 'آنالیتیکس کاربران';  ob_start(); ?>
<div id="kpiRoot"></div>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1">groups</span> آنالیتیکس کاربران</h4>
        <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-outline-secondary">بازگشت به KPI</a>
    </div>

    <!-- آمار اصلی -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-primary"><?= number_format($userStats['total']) ?></div><small class="text-muted">کل</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-success"><?= number_format($userStats['active']) ?></div><small class="text-muted">فعال</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-info"><?= e($userStats['new_today']) ?></div><small class="text-muted">جدید امروز</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-warning"><?= e($userStats['new_this_week']) ?></div><small class="text-muted">جدید هفته</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div class="text-danger"><?= number_format($userStats['banned']) ?></div><small class="text-muted">مسدود</small></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3"><div ><?= e($userStats['kyc_pending']) ?></div><small class="text-muted">KYC در انتظار</small></div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- نمودار ثبت‌نام -->
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ثبت‌نام روزانه (۳۰ روز)</h6></div>
                <div class="card-body"><canvas id="regChart" height="280"></canvas></div>
            </div>
        </div>
        <!-- قرعه‌کشی -->
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">قرعه‌کشی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>شرکت‌کنندگان فعال:</span><strong><?= number_format($lotteryStats['total_participants']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>رأی امروز:</span><strong><?= number_format($lotteryStats['votes_today']) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>میانگین امتیاز شانس:</span><strong><?= e($lotteryStats['avg_chance_score']) ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <!-- کاربران برتر -->
    <div class="card">
        <div class="card-header"><h6 class="mb-0">کاربران برتر (بر اساس درآمد)</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>نام</th><th>ایمیل</th><th>سطح</th><th>درآمد تسک</th><th>کمیسیون</th><th>مجموع</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUsers as $i => $userRow): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($userRow['full_name'] ?? '') ?></strong></td>
                                <td><small><?= e($userRow['email'] ?? '') ?></small></td>
                                <td><span class="badge bg-info"><?= e($userRow['level_slug'] ?? 'silver') ?></span></td>
                                <td><?= number_format((float)($userRow['task_earnings'] ?? 0)) ?></td>
                                <td><?= number_format((float)($userRow['commission_earnings'] ?? 0)) ?></td>
                                <td><strong><?= number_format((float)($userRow['task_earnings'] ?? 0) + (float)($userRow['commission_earnings'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topUsers)): ?>
                            <tr><td colspan="7" class="text-center py-3 text-muted">داده‌ای موجود نیست</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>





<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
