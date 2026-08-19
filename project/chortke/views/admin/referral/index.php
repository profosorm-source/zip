<?php
$title = 'مدیریت سیستم معرفی و کمیسیون';

ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">share</i>
                مدیریت سیستم معرفی
            </h4>
            <p class="text-muted mb-0">مدیریت کمیسیون‌ها، تنظیمات و ضدتقلب</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/referral/settings') ?>" class="btn btn-outline-primary btn-sm">
                <i class="material-icons">settings</i>
                تنظیمات
            </a>
            <button class="btn btn-success btn-sm" data-click="batchPay" data-args="irt">
                <i class="material-icons">payment</i>
                پرداخت دسته‌ای (تومان)
            </button>
        </div>
    </div>
</div>

<!-- آمار کلی -->
<div class="row mt-3">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">کل پرداخت شده (تومان)</small>
                        <h5 class="mt-1 mb-0"><?= number_format($stats->total_paid_irt ?? 0) ?></h5>
                    </div>
                    <div >
                        <i class="material-icons">check_circle</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">در انتظار پرداخت (تومان)</small>
                        <h5 class="mt-1 mb-0"><?= number_format($stats->total_pending_irt ?? 0) ?></h5>
                    </div>
                    <div >
                        <i class="material-icons">schedule</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">معرف‌های فعال</small>
                        <h5 class="mt-1 mb-0"><?= number_format($stats->active_referrers ?? 0) ?></h5>
                    </div>
                    <div >
                        <i class="material-icons">people</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">لغو شده</small>
                        <h5 class="mt-1 mb-0"><?= number_format($stats->cancelled_count ?? 0) ?></h5>
                    </div>
                    <div >
                        <i class="material-icons">cancel</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- برترین معرف‌ها -->
<?php if (!empty($topReferrers)): ?>
<div class="card mt-2">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-warning">emoji_events</i>
            برترین معرف‌ها
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>رتبه</th>
                        <th>نام</th>
                        <th>ایمیل</th>
                        <th>زیرمجموعه</th>
                        <th>تعداد کمیسیون</th>
                        <th>مجموع درآمد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topReferrers as $idx => $tr): ?>
                    <tr>
                        <td>
                            <?php if ($idx === 0): ?>
                                <span >🥇</span>
                            <?php elseif ($idx === 1): ?>
                                <span >🥈</span>
                            <?php elseif ($idx === 2): ?>
                                <span >🥉</span>
                            <?php else: ?>
                                <?= $idx + 1 ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= url('/admin/referral/user/' . (int)($tr->referrer_id ?? $tr->id ?? 0)) ?>">
                                <?= e($tr->full_name ?? '—') ?>
                            </a>
                        </td>
                        <td dir="ltr"><?= e($tr->email ?? '') ?></td>
                        <td><?= number_format((int)($tr->referred_count ?? $tr->referrals ?? 0)) ?> نفر</td>
                        <td><?= number_format((int)($tr->commission_count ?? $tr->total_count ?? 0)) ?></td>
                        <td><strong class="text-success"><?= number_format((float)($tr->total_earned ?? $tr->total_commission ?? 0)) ?> تومان</strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary">filter_list</i>
            فیلتر و جستجو
        </h6>
    </div>
    <div class="card-body">
        <form method="GET" action="<?= url('/admin/referral') ?>">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="جستجو (نام/ایمیل)" value="<?= e($filters['search'] ?? '') ?>">
                </div>
                <div class="col-md-2 mb-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                        <option value="paid" <?= ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>پرداخت شده</option>
                        <option value="cancelled" <?= ($filters['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <select name="source_type" class="form-select form-select-sm">
                        <option value="">همه منابع</option>
                        <?php foreach ($sourceTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($filters['source_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <select name="currency" class="form-select form-select-sm">
                        <option value="">همه ارزها</option>
                        <option value="irt" <?= ($filters['currency'] ?? '') === 'irt' ? 'selected' : '' ?>>تومان</option>
                        <option value="usdt" <?= ($filters['currency'] ?? '') === 'usdt' ? 'selected' : '' ?>>USDT</option>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="material-icons">search</i> فیلتر
                    </button>
                    <a href="<?= url('/admin/referral') ?>" class="btn btn-outline-secondary btn-sm">پاکسازی</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- جدول کمیسیون‌ها -->
<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary">receipt_long</i>
            لیست کمیسیون‌ها
        </h6>
        <span class="badge bg-info"><?= number_format($total) ?> رکورد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>معرف</th>
                        <th>زیرمجموعه</th>
                        <th>منبع</th>
                        <th>مبلغ اصلی</th>
                        <th>درصد</th>
                        <th>کمیسیون</th>
                        <th>ارز</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($commissions)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">
                            <i class="material-icons">inbox</i>
                            <p class="mt-2">رکوردی یافت نشد.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($commissions as $idx => $c): ?>
                    <tr id="comm-row-<?= e($c->id) ?>">
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td>
                            <a href="<?= url('/admin/referral/user/' . $c->referrer_id) ?>" class="aggr-cleaned">
                                <?= e($c->referrer_name ?? '—') ?>
                            </a>
                        </td>
                        <td ><?= e($c->referred_name ?? '—') ?></td>
                        <td>
                            <span class="badge">
                                <?= e(($c->source_label ?? $c->source_type)) ?>
                            </span>
                        </td>
                        <?php
                            $sourceAmount = (float)($c->source_amount ?? $c->amount ?? 0);
                            $ctx = [];
                            if (!empty($c->context)) {
                                $decoded = json_decode((string)$c->context, true);
                                if (is_array($decoded)) $ctx = $decoded;
                            }
                            $commissionPercent = $c->commission_percent ?? ($ctx['percentage'] ?? null);
                        ?>
                        <td><?= $c->currency === 'usdt' ? number_format($sourceAmount, 2) : number_format($sourceAmount) ?></td>
                        <td><?= e($commissionPercent ?? '—') ?><?= $commissionPercent !== null ? '%' : '' ?></td>
                        <td><strong class="text-success"><?= $c->currency === 'usdt' ? number_format((float)$c->commission_amount, 2) : number_format((float)$c->commission_amount) ?></strong></td>
                        <td><?= $c->currency === 'usdt' ? 'USDT' : 'تومان' ?></td>
                        <td>
                            <?php
                            $sLabel = ['pending'=>'در انتظار','paid'=>'پرداخت شده','cancelled'=>'لغو','failed'=>'ناموفق'];
                            $sClass = ['pending'=>'badge-warning','paid'=>'badge-success','cancelled'=>'badge-danger','failed'=>'badge-danger'];
                            ?>
                            <span class="badge <?= $sClass[$c->status] ?? '' ?>"><?= $sLabel[$c->status] ?? $c->status ?></span>
                        </td>
                        <td ><?= to_jalali($c->created_at ?? '') ?></td>
                        <td>
                            <?php if ($c->status === 'pending'): ?>
                            <button class="btn btn-sm btn-outline-danger" data-click="cancelCommission" data-args="<?= e($c->id) ?>" title="لغو">
                                <i class="material-icons">close</i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('/admin/referral?page=' . $i . '&status=' . e($filters['status'] ?? '') . '&source_type=' . e($filters['source_type'] ?? '') . '&currency=' . e($filters['currency'] ?? '') . '&search=' . e($filters['search'] ?? '')) ?>">
                        <?= e($i) ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>




<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
