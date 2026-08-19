<?php $title = 'داشبورد KPI و آنالیتیکس';  ob_start(); ?>
<div id="kpiRoot" data-chart-url="<?= url('/admin/kpi/chart-data') ?>"></div>

<?php
$curr = $financialStats['currency'] ?? 'irt';
$currSymbol = $curr === 'usdt' ? 'USDT' : 'تومان';

function formatMoney($amount, $curr) {
    if ($curr === 'usdt') return number_format((float)$amount, 2) . ' USDT';
    return number_format((float)$amount) . ' تومان';
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1">analytics</span> داشبورد KPI</h4>
        <div class="btn-group btn-group-sm">
            <a href="<?= url('/admin/kpi/financial') ?>" class="btn btn-outline-primary">مالی</a>
            <a href="<?= url('/admin/kpi/users') ?>" class="btn btn-outline-primary">کاربران</a>
            <div class="dropdown">
                <button class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">خروجی</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/users') ?>">📄 کاربران (CSV)</a></li>
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/transactions') ?>">📄 تراکنش‌ها (CSV)</a></li>
                    <li><a class="dropdown-item" href="<?= url('/admin/kpi/export/summary') ?>">📋 خلاصه (JSON)</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ردیف 1: آمار اصلی -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon"><span class="material-icons">people</span></div>
                    <div class="me-3">
                        <div class="stat-label">کل کاربران</div>
                        <div class="stat-value"><?= number_format($userStats['total']) ?></div>
                        <small class="text-success">+<?= e($userStats['new_today']) ?> امروز</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon"><span class="material-icons">account_balance_wallet</span></div>
                    <div class="me-3">
                        <div class="stat-label">درآمد ماهانه</div>
                        <div class="stat-value"><?= formatMoney($financialStats['monthly_revenue'], $curr) ?></div>
                        <small class="text-muted">امروز: <?= formatMoney($financialStats['today_revenue'], $curr) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon"><span class="material-icons">task_alt</span></div>
                    <div class="me-3">
                        <div class="stat-label">تسک‌های تکمیل (ماهانه)</div>
                        <div class="stat-value"><?= number_format($taskStats['completed_month']) ?></div>
                        <small class="text-muted">امروز: <?= number_format($taskStats['completed_today']) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon"><span class="material-icons">trending_up</span></div>
                    <div class="me-3">
                        <div class="stat-label">ARPU ماهانه</div>
                        <div class="stat-value"><?= formatMoney($financialStats['arpu'], $curr) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 2: KPI های کلیدی -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-primary"><?= e($userStats['dau']) ?></div>
                <small class="text-muted">DAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-info"><?= e($userStats['wau']) ?></div>
                <small class="text-muted">WAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div class="text-success"><?= e($userStats['mau']) ?></div>
                <small class="text-muted">MAU</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div ><?= e($churnRate) ?>%</div>
                <small class="text-muted">Churn Rate</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div ><?= e($conversionRate) ?>%</div>
                <small class="text-muted">Conversion</small>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="card text-center p-3">
                <div ><?= e($fraudStats['suspicious_users']) ?></div>
                <small class="text-muted">مشکوک</small>
            </div>
        </div>
    </div>

    <!-- ردیف 3: نمودارها -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">نمودار درآمد</h6>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary btn-chart-period active" data-days="7">هفته</button>
                        <button class="btn btn-outline-primary btn-chart-period" data-days="30">ماه</button>
                        <button class="btn btn-outline-primary btn-chart-period" data-days="90">سه‌ماه</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">تسک‌ها بر اساس پلتفرم</h6></div>
                <div class="card-body">
                    <canvas id="platformChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 4: ثبت‌نام + تسک -->
    <div class="row mb-4">
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ثبت‌نام روزانه</h6></div>
                <div class="card-body">
                    <canvas id="registrationChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">تسک‌های تکمیل‌شده روزانه</h6></div>
                <div class="card-body">
                    <canvas id="taskChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ردیف 5: آمار سریع -->
    <div class="row">
        <!-- تیکت‌ها -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">پشتیبانی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>تیکت باز:</span><strong class="text-danger"><?= e($ticketStats['open']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>در حال بررسی:</span><strong class="text-warning"><?= e($ticketStats['in_progress']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>کل تیکت‌ها:</span><strong><?= e($ticketStats['total']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>میانگین پاسخ:</span><strong><?= e($ticketStats['avg_response_hours']) ?> ساعت</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- مالی -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">مالی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>واریز امروز:</span><strong class="text-success"><?= formatMoney($financialStats['today_deposits'], $curr) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>برداشت امروز:</span><strong class="text-danger"><?= formatMoney($financialStats['today_withdrawals'], $curr) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>در انتظار:</span><strong class="text-warning"><?= e($financialStats['pending_transactions']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>گردش خالص:</span><strong><?= formatMoney($financialStats['net_flow'], $curr) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- ضد تقلب -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">ضد تقلب</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>کاربران مشکوک:</span><strong class="text-danger"><?= e($fraudStats['suspicious_users']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>بن امروز:</span><strong><?= e($fraudStats['blocked_today']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>لیست سیاه:</span><strong><?= e($fraudStats['silent_blacklisted']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>تقلب تسک (ماه):</span><strong><?= e($fraudStats['fraud_tasks_month']) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- سطح کاربران -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0">سطح‌بندی</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>🥈 Silver:</span><strong><?= number_format($userStats['tiers']['silver'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>🥇 Gold:</span><strong><?= number_format($userStats['tiers']['gold'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>💎 VIP:</span><strong><?= number_format($userStats['tiers']['vip'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>KYC تأیید:</span><strong class="text-success"><?= number_format($userStats['kyc_verified']) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>




<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
