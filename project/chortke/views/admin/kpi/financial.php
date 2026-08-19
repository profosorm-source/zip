<?php $title = 'آنالیتیکس مالی';  ob_start(); ?>
<div id="kpiRoot"></div>

<?php
$curr = $financialStats['currency'] ?? 'irt';
function fmtMoney($amount, $c) {
    if ($c === 'usdt') return number_format((float)$amount, 2) . ' USDT';
    return number_format((float)$amount) . ' تومان';
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1">payments</span> آنالیتیکس مالی</h4>
        <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-outline-secondary">بازگشت به KPI</a>
    </div>

    <!-- آمار مالی -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body p-3">
                    <div class="stat-label">کل واریزها</div>
                    <div class="stat-value"><?= fmtMoney($financialStats['total_deposits'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body p-3">
                    <div class="stat-label">کل برداشت‌ها</div>
                    <div class="stat-value"><?= fmtMoney($financialStats['total_withdrawals'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body p-3">
                    <div class="stat-label">درآمد سایت (کل)</div>
                    <div class="stat-value"><?= fmtMoney($financialStats['site_revenue'], $curr) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body p-3">
                    <div class="stat-label">گردش خالص</div>
                    <div class="stat-value">
                        <?= fmtMoney($financialStats['net_flow'], $curr) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نمودار واریز/برداشت -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">واریز و برداشت روزانه (۳۰ روز)</h6></div>
                <div class="card-body"><canvas id="dwChart" height="280"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">درآمد روزانه</h6></div>
                <div class="card-body"><canvas id="dailyRevenueChart" height="280"></canvas></div>
            </div>
        </div>
    </div>

    <!-- سرمایه‌گذاری + Referral -->
    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سرمایه‌گذاری</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>کل سرمایه‌گذاری‌ها:</span><strong><?= e($investmentStats['total']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>فعال:</span><strong class="text-success"><?= e($investmentStats['active']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل سرمایه:</span><strong><?= fmtMoney($investmentStats['total_invested'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل سود:</span><strong class="text-success"><?= fmtMoney($investmentStats['total_profit'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>کل ضرر:</span><strong class="text-danger"><?= fmtMoney($investmentStats['total_loss'], 'usdt') ?></strong></div>
                    <div class="d-flex justify-content-between"><span>سود خالص:</span><strong ><?= fmtMoney($investmentStats['net_profit'], 'usdt') ?></strong></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سیستم معرفی (Referral)</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>کل معرفی‌ها:</span><strong><?= number_format($referralStats['total']) ?></strong></div>
                    <div class="d-flex justify-content-between mb-3"><span>کل کمیسیون:</span><strong><?= fmtMoney($referralStats['total_commissions'], $curr) ?></strong></div>
                    <?php if (!empty($referralStats['top_referrers'])): ?>
                        <h6 >برترین معرف‌ها:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>نام</th><th>تعداد</th><th>کمیسیون</th></tr></thead>
                                <tbody>
                                    <?php foreach (\array_slice($referralStats['top_referrers'], 0, 5) as $tr): ?>
                                        <tr>
                                            <td><?= e($tr['full_name'] ?? '') ?></td>
                                            <td><?= $tr['referral_count'] ?? 0 ?></td>
                                            <td><?= fmtMoney($tr['total_earned'] ?? 0, $curr) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>






<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
