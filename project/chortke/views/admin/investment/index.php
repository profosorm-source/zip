<?php $title = 'مدیریت سرمایه‌گذاری';  ob_start(); ?>
<div id="investmentRoot" data-base="<?= url('/admin/investment') ?>" data-withdrawals-base="<?= url('/admin/investment/withdrawals') ?>" data-apply-url="<?= url('/admin/investment/apply-profit') ?>" data-trades-base="<?= url('/admin/investment/trades') ?>" data-trades-store="<?= url('/admin/investment/trades/store') ?>" data-trades-list="<?= url('/admin/investment/trades') ?>"></div>


<div class="content-header">
    <h4><i class="material-icons">trending_up</i> مدیریت سرمایه‌گذاری</h4>
    <div>
        <a href="<?= url('/admin/investment/trades') ?>" class="btn btn-sm btn-outline-primary">
            <i class="material-icons">candlestick_chart</i> تریدها
        </a>
        <a href="<?= url('/admin/investment/apply-profit') ?>" class="btn btn-sm btn-primary">
            <i class="material-icons">calculate</i> اعمال سود/ضرر
        </a>
        <a href="<?= url('/admin/investment/withdrawals') ?>" class="btn btn-sm btn-outline-warning">
            <i class="material-icons">savings</i> برداشت‌ها
        </a>
    </div>
</div>

<!-- آمار -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon"><i class="material-icons">people</i></div>
        <div class="stat-info">
            <span class="stat-label">فعال</span>
            <span class="stat-value"><?= e($stats->active_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon"><i class="material-icons">attach_money</i></div>
        <div class="stat-info">
            <span class="stat-label">کل سرمایه (USDT)</span>
            <span class="stat-value"><?= number_format($stats->total_invested ?? 0, 2) ?></span>
        </div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon"><i class="material-icons">account_balance_wallet</i></div>
        <div class="stat-info">
            <span class="stat-label">موجودی کل</span>
            <span class="stat-value"><?= number_format($stats->total_balance ?? 0, 2) ?></span>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon"><i class="material-icons">show_chart</i></div>
        <div class="stat-info">
            <span class="stat-label">تریدها</span>
            <span class="stat-value"><?= e($tradeStats->total ?? 0) ?> (باز: <?= e($tradeStats->open_count ?? 0) ?>)</span>
        </div>
    </div>
</div>

<!-- فیلتر و لیست -->
<div class="card">
    <div class="card-header">
        <h5>لیست سرمایه‌گذاری‌ها (<?= number_format($total) ?>)</h5>
        <form method="GET">
            <select name="status" class="form-control form-control-sm" data-autosubmit>
                <option value="">همه</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="frozen" <?= ($filters['status'] ?? '') === 'frozen' ? 'selected' : '' ?>>فریز</option>
                <option value="closed" <?= ($filters['status'] ?? '') === 'closed' ? 'selected' : '' ?>>بسته</option>
                <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>تعلیق</option>
            </select>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..."
                   value="<?= e($filters['search'] ?? '') ?>">
            <button class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($investments)): ?>
            <p class="text-center text-muted">سرمایه‌گذاری‌ای یافت نشد.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کاربر</th>
                        <th>سرمایه</th>
                        <th>موجودی فعلی</th>
                        <th>سود کل</th>
                        <th>ضرر کل</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investments as $inv): ?>
                    <tr>
                        <td><?= e($inv->id) ?></td>
                        <td><?= e($inv->user_name ?? '') ?></td>
                        <td><?= number_format($inv->amount, 2) ?></td>
                        <td class="<?= $inv->current_balance >= $inv->amount ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($inv->current_balance, 2) ?>
                        </td>
                        <td class="text-success"><?= number_format($inv->total_profit, 2) ?></td>
                        <td class="text-danger"><?= number_format($inv->total_loss, 2) ?></td>
                        <td>
                            <?php
                            $isl = [
                                'active' => ['فعال', 'badge-success'],
                                'frozen' => ['فریز', 'badge-info'],
                                'closed' => ['بسته', 'badge-secondary'],
                                'suspended' => ['تعلیق', 'badge-danger'],
                            ][$inv->status] ?? ['؟', 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($isl[1]) ?>"><?= e($isl[0]) ?></span>
                        </td>
                        <td><?= e(to_jalali($inv->start_date ?? '')) ?></td>
                        <td>
                            <a href="<?= url('/admin/investment/' . $inv->id) ?>" class="btn btn-xs btn-outline-primary">
                                <i class="material-icons">visibility</i>
                            </a>
                            <?php if ($inv->status === 'active'): ?>
                            <button class="btn btn-xs btn-outline-danger" data-click="suspendInvestment" data-args="<?= e($inv->id) ?>">
                                <i class="material-icons">block</i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= url('/admin/investment?' . \http_build_query(\array_merge($filters, ['page' => $i]))) ?>"
                   class="page-link <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>



<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
