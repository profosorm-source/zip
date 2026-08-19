<?php
$title = 'مدیریت';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><?= e($title) ?></h1>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="<?= url('/admin/analytics/transactions?period=day') ?>" class="btn btn-outline-primary <?= $period === 'day' ? 'active' : '' ?>">امروز</a>
                <a href="<?= url('/admin/analytics/transactions?period=week') ?>" class="btn btn-outline-primary <?= $period === 'week' ? 'active' : '' ?>">هفته</a>
                <a href="<?= url('/admin/analytics/transactions?period=month') ?>" class="btn btn-outline-primary <?= $period === 'month' ? 'active' : '' ?>">ماه</a>
                <a href="<?= url('/admin/analytics/transactions?period=year') ?>" class="btn btn-outline-primary <?= $period === 'year' ? 'active' : '' ?>">سال</a>
            </div>
        </div>
    </div>

    <!-- Transaction Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">واریز‌ها</h6>
                    <h3 class="text-success"><?= number_format($metrics['deposits']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['deposits']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">برداشت‌ها</h6>
                    <h3 class="text-danger"><?= number_format($metrics['withdrawals']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['withdrawals']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">پرداخت‌ها</h6>
                    <h3 class="text-info"><?= number_format($metrics['payments']['count']) ?></h3>
                    <small class="text-muted"><?= number_format($metrics['payments']['amount']) ?> تومان</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted">درآمد پلتفرم</h6>
                    <h3 class="text-warning"><?= number_format((int)$metrics['platform_fee']) ?></h3>
                    <small class="text-muted">تومان</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Net Flow -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">جریان خالص</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-6">
                            <h6 class="text-muted">جریان خالص</h6>
                            <h2 class="text-<?= e($metrics['net_flow'] > 0 ? 'success' : 'danger') ?>">
                                <?= number_format((int)$metrics['net_flow']) ?> تومان
                            </h2>
                            <small class="text-muted">
                                (واریز - برداشت)
                            </small>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">نسبت برداشت به واریز</h6>
                            <h2 class="text-info">
                                <?php
                                $depositAmount = $metrics['deposits']['amount'];
                                $withdrawalAmount = $metrics['withdrawals']['amount'];
                                $ratio = $depositAmount > 0 ? ($withdrawalAmount / $depositAmount) * 100 : 0;
                                echo e(round($ratio, 1)) . '%';
                                ?>
                            </h2>
                            <small class="text-muted">
                                از کل واریز‌ها
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Volume Chart Placeholder -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="m-0">نمودار حجم تراکنش‌ها (۳۰ روز گذشته)</h5>
                </div>
                <div class="card-body">
                    <div id="transactionChart">
                        <canvas id="transactionVolumeChart" data-chart-url="<?= url("/admin/analytics/chart-data?type=transactions") ?>"></canvas>
                    </div>
                    <small class="text-muted">داده‌های نمودار از طریق API بارگذاری می‌شود</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <div class="row">
        <div class="col-md-12">
            <a href="<?= url('/admin/analytics') ?>" class="btn btn-outline-secondary">
                ← بازگشت به داشبورد
            </a>
        </div>
    </div>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
