<?php
$title = 'جزئیات آگهی #' . ($ad->id ?? '');

ob_start();
$typeLabels = $typeLabels ?? [];
$statusLabels = $statusLabels ?? [];
$statusClasses = $statusClasses ?? [];
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0">
            <i class="material-icons text-primary">campaign</i>
            <?= e($title) ?>
        </h4>
        <a href="<?= url('/admin/ads') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" class="icon-16 align-middle">arrow_back</i> بازگشت به لیست
        </a>
    </div>
</div>

<div class="row mt-3">
    <!-- Generic Card -->
    <div class="col-md-12">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>اطلاعات عمومی</strong>
                <span class="badge <?= $statusClasses[$ad->status ?? ''] ?? 'badge-secondary' ?>" class="badge-sm">
                    <?= e($statusLabels[$ad->status ?? ''] ?? ($ad->status ?? '—')) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">نوع</div>
                        <div class="fw-bold"><?= e($typeLabels[$ad->type ?? ''] ?? ($ad->type ?? '—')) ?></div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">شناسه</div>
                        <div class="fw-bold"><?= fa_number($ad->id ?? 0) ?></div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">کاربر</div>
                        <div class="fw-bold"><?= e($ad->user_name ?? ($ad->user_email ?? '—')) ?> (<?= fa_number($ad->user_id ?? 0) ?>)</div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">تاریخ ایجاد</div>
                        <div class="fw-bold"><?= to_jalali($ad->created_at ?? '') ?></div>
                    </div>
                    <div class="col-md-12 mb-2">
                        <div class="text-muted" class="text-11">عنوان</div>
                        <div class="fw-bold fs-5"><?= e($ad->title ?? '—') ?></div>
                    </div>
                    <?php if (!empty($ad->description)): ?>
                    <div class="col-md-12 mb-2">
                        <div class="text-muted" class="text-11">توضیحات</div>
                        <div class="text-13 pre-wrap"><?= nl2br(e($ad->description ?? '')) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">بودجه کل</div>
                        <div class="fw-bold"><?= $ad->currency === 'usdt' ? number_format($ad->total_budget ?? 0, 2) : number_format($ad->total_budget ?? 0) ?> <?= e($ad->currency === 'usdt' ? 'USDT' : 'تومان') ?></div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">بودجه باقیمانده</div>
                        <div class="fw-bold <?= (($ad->remaining_budget ?? 0) <= 0) ? 'text-danger' : 'text-success' ?>">
                            <?= $ad->currency === 'usdt' ? number_format($ad->remaining_budget ?? 0, 2) : number_format($ad->remaining_budget ?? 0) ?> <?= e($ad->currency === 'usdt' ? 'USDT' : 'تومان') ?>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">تعداد کل</div>
                        <div class="fw-bold"><?= fa_number($ad->total_count ?? 0) ?></div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="text-muted" class="text-11">تکمیل‌شده</div>
                        <div class="fw-bold"><?= fa_number($ad->completed_count ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$finance = $finance ?? [];
$activeEscrow = $finance['active_escrow'] ?? null;
$lastEscrow = $finance['escrows'][0] ?? null;
$displayEscrow = $activeEscrow ?: $lastEscrow;
$deliverySummary = $finance['delivery_summary'] ?? null;
$deliveryByType = $finance['delivery_by_type'] ?? [];
$financeTransactions = $finance['transactions'] ?? [];
$terminalStatuses = ['completed','cancelled','expired'];
?>

<div class="card mb-3" id="adminAdActions" data-action-url-template="<?= e(url('/admin/ads/' . (int)$ad->id . '/action')) ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="material-icons align-middle icon-18">admin_panel_settings</i> عملیات یکپارچه ادمین</strong>
        <small class="text-muted">اقدام‌ها type-aware هستند و در رد/لغو/حذف، refund واقعی انجام می‌شود.</small>
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php if (in_array((string)$ad->status, ['pending','pending_review','rejected','paused'], true)): ?>
            <button class="btn btn-success btn-sm" data-admin-ad-action="approve" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">check_circle</i> تأیید/فعال‌سازی</button>
        <?php endif; ?>
        <?php if (in_array((string)$ad->status, ['active','approved'], true)): ?>
            <button class="btn btn-warning btn-sm" data-admin-ad-action="pause" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">pause_circle</i> توقف</button>
        <?php elseif ((string)$ad->status === 'paused'): ?>
            <button class="btn btn-success btn-sm" data-admin-ad-action="resume" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">play_circle</i> ازسرگیری</button>
        <?php endif; ?>
        <?php if (!in_array((string)$ad->status, $terminalStatuses, true)): ?>
            <button class="btn btn-outline-danger btn-sm" data-admin-ad-action="reject" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">block</i> رد + آزادسازی</button>
            <button class="btn btn-outline-secondary btn-sm" data-admin-ad-action="cancel" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">cancel</i> لغو + refund</button>
            <button class="btn btn-outline-dark btn-sm" data-admin-ad-action="delete" data-id="<?= (int)$ad->id ?>"><i class="material-icons icon-16 align-middle">delete</i> حذف نرم</button>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong><i class="material-icons align-middle icon-18">account_balance_wallet</i> وضعیت مالی و Escrow</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">نوع escrow</div>
                    <div class="fw-bold"><?= e($finance['order_type'] ?? '—') ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">آخرین وضعیت escrow</div>
                    <div class="fw-bold text-success"><?= $displayEscrow ? number_format((float)$displayEscrow->amount) : '۰' ?> <?= e(($displayEscrow->currency ?? $ad->currency ?? 'irt') === 'usdt' ? 'USDT' : 'تومان') ?></div>
                    <small class="text-muted"><?= e($displayEscrow->status ?? 'بدون escrow') ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">مصرف delivery</div>
                    <div class="fw-bold"><?= number_format((float)($deliverySummary->spent_amount ?? 0)) ?> تومان</div>
                    <small class="text-muted">کارمزد: <?= number_format((float)($deliverySummary->platform_fee ?? 0)) ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">آخرین delivery</div>
                    <div class="fw-bold"><?= !empty($deliverySummary->last_delivery_at) ? to_jalali($deliverySummary->last_delivery_at) : '—' ?></div>
                    <small class="text-muted"><?= fa_number((int)($deliverySummary->event_count ?? 0)) ?> رویداد</small>
                </div>
            </div>
        </div>
        <?php if (!empty($displayEscrow->refund_reason)): ?>
        <div class="alert alert-warning mt-3 mb-0 small">
            <strong>دلیل refund:</strong> <?= e($displayEscrow->refund_reason) ?>
            <?php if (!empty($displayEscrow->refunded_at)): ?>
                <span class="ms-2 text-muted">در تاریخ <?= e(substr((string)$displayEscrow->refunded_at, 0, 16)) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($deliveryByType)): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
                <thead><tr><th>نوع رویداد</th><th>واحد</th><th>بودجه مصرفی</th><th>کارمزد</th><th>تعداد event</th></tr></thead>
                <tbody>
                <?php foreach ($deliveryByType as $row): ?>
                    <tr>
                        <td><?= e($row->event_type) ?></td>
                        <td><?= fa_number((float)$row->units) ?></td>
                        <td><?= number_format((float)$row->amount) ?></td>
                        <td><?= number_format((float)$row->platform_fee) ?></td>
                        <td><?= fa_number((int)$row->event_count) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($typeDetailUrl)): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><i class="material-icons" class="icon-16 align-middle">info</i> این آگهی دارای پنل مدیریت تخصصی است.</span>
    <a href="<?= e($typeDetailUrl) ?>" class="btn btn-sm btn-primary">
        <i class="material-icons" class="icon-14 align-middle">open_in_new</i> مشاهده در پنل تخصصی
    </a>
</div>
<?php endif; ?>

<!-- Type-specific Partial -->
<?php
$partial = match($ad->type ?? '') {
    'custom_task'  => 'admin/ads/partials/custom_task_detail',
    'social_task'  => 'admin/ads/partials/social_task_detail',
    'banner'       => 'admin/ads/partials/banner_detail',
    'seo'          => 'admin/ads/partials/seo_detail',
    'adtube'       => 'admin/ads/partials/adtube_detail',
    'notification' => 'admin/ads/partials/notification_detail',
    default        => null,
};
?>
<?php if ($partial): ?>
    <?php include view_path($partial); ?>
<?php else: ?>
    <div class="alert alert-secondary">جزئیات تخصصی برای این نوع آگهی تعریف نشده است.</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="ads-index-data">' . json_encode([
    'bulkUrl' => url('/admin/ads/bulk'),
    'actionUrlTemplate' => url('/admin/ads/__ID__/action'),
    'csrf' => csrf_token(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
    . '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/adsindex.js') . '"></script>';
include view_path('layouts.admin');
?>