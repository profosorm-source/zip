<?php
$title = 'داشبورد یکپارچه تبلیغات';

ob_start();
$typeLabels = $typeLabels ?? [];
$statusLabels = $statusLabels ?? [];
$statusClasses = $statusClasses ?? [];
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admin/adsindex.css') . '">';
?>

<div class="ads-index" id="adminAdsIndex">

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-primary">campaign</i> داشبورد یکپارچه تبلیغات</h4>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-primary btn-sm">تسک‌های سفارشی</a>
            <a href="<?= url('/admin/social-tasks') ?>" class="btn btn-outline-primary btn-sm">شبکه‌های اجتماعی</a>
            <a href="<?= url('/admin/banners') ?>" class="btn btn-outline-primary btn-sm">بنرها</a>
            <a href="<?= url('/admin/seo-ad') ?>" class="btn btn-outline-primary btn-sm">SEO</a>
        </div>
    </div>
</div>

<!-- Overview Stats -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card text-center p-2">
            <div class="stat-label">کل تبلیغات</div>
            <div class="stat-value text-primary"><?= fa_number($overview['total'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-2">
            <div class="stat-label">فعال</div>
            <div class="stat-value text-success"><?= fa_number($overview['active'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-2">
            <div class="stat-label">در انتظار</div>
            <div class="stat-value text-warning"><?= fa_number($overview['pending'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-2">
            <div class="stat-label">تکمیل‌شده</div>
            <div class="stat-value text-info"><?= fa_number($overview['completed'] ?? 0) ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mt-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm filter-input" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
            <select name="type" class="form-select form-select-sm filter-select">
                <option value="">همه انواع</option>
                <?php foreach ($typeLabels as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm filter-select">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="user_id" class="form-control form-control-sm filter-input-xs" placeholder="User ID" value="<?= e($filters['user_id'] ?? '') ?>">
            <input type="date" name="date_from" class="form-control form-control-sm filter-input-date" placeholder="از" value="<?= e($filters['date_from'] ?? '') ?>">
            <input type="date" name="date_to" class="form-control form-control-sm filter-input-date" placeholder="تا" value="<?= e($filters['date_to'] ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <a href="<?= url('/admin/ads') ?>" class="btn btn-outline-secondary btn-sm">پاکسازی</a>
            <span class="text-muted ms-auto results-count"><?= fa_number($total ?? 0) ?> مورد</span>
        </form>
    </div>
</div>

<!-- Bulk Actions -->
<div class="card mt-3 mb-0">
    <div class="card-body py-2 d-flex gap-2 align-items-center">
        <select id="bulkAction" class="form-select form-select-sm bulk-select">
            <option value="">عملیات گروهی...</option>
            <option value="approve">تأیید</option>
            <option value="reject">رد</option>
            <option value="pause">توقف</option>
            <option value="resume">از سرگیری</option>
            <option value="cancel">لغو با آزادسازی بودجه</option>
            <option value="delete">حذف نرم + آزادسازی</option>
        </select>
        <button id="btnBulkApply" class="btn btn-sm btn-dark">اجرا</button>
    </div>
</div>

<!-- Table -->
<div class="card mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 ads-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="checkAll"></th>
                        <th>#</th><th>ID</th><th>عنوان</th><th>نوع</th><th>کاربر</th>
                        <th>وضعیت</th><th>بودجه</th><th>باقیمانده</th><th>تاریخ</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
                    <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr id="ad-row-<?= e($item->id) ?>">
                        <td><input type="checkbox" class="ad-check" value="<?= e($item->id) ?>"></td>
                        <td class="text-muted"><?= fa_number((($page ?? 1) - 1) * 30 + $idx + 1) ?></td>
                        <td class="fw-bold"><?= fa_number($item->id) ?></td>
                        <td class="ad-title-cell">
                            <strong><?= e(mb_substr($item->title ?? '—', 0, 40)) ?></strong>
                        </td>
                        <td><span class="badge ad-type-badge"><?= e($typeLabels[$item->type] ?? $item->type) ?></span></td>
                        <td class="ad-user-cell"><?= e($item->user_name ?? ($item->user_email ?? '—')) ?></td>
                        <td><span class="badge <?= $statusClasses[$item->status] ?? '' ?>"><?= e($statusLabels[$item->status] ?? $item->status) ?></span></td>
                        <td class="ad-amount-cell"><?= $item->currency === 'usdt' ? number_format($item->total_budget ?? 0, 2) : number_format($item->total_budget ?? 0) ?></td>
                        <td class="ad-amount-cell"><?= $item->currency === 'usdt' ? number_format($item->remaining_budget ?? 0, 2) : number_format($item->remaining_budget ?? 0) ?></td>
                        <td class="ad-date-cell"><?= to_jalali($item->created_at ?? '') ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="<?= url('/admin/ads/' . $item->id) ?>" class="btn btn-sm btn-outline-primary ad-action-btn" title="مشاهده">
                                    <i class="material-icons">visibility</i>
                                </a>
                                <?php if (in_array((string)$item->status, ['pending','pending_review','rejected','paused'], true)): ?>
                                <button type="button" class="btn btn-sm btn-outline-success ad-action-btn" data-admin-ad-action="approve" data-id="<?= (int)$item->id ?>" title="تأیید/فعال‌سازی">
                                    <i class="material-icons">check_circle</i>
                                </button>
                                <?php endif; ?>
                                <?php if (in_array((string)$item->status, ['active','approved'], true)): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning ad-action-btn" data-admin-ad-action="pause" data-id="<?= (int)$item->id ?>" title="توقف">
                                    <i class="material-icons">pause_circle</i>
                                </button>
                                <?php elseif ((string)$item->status === 'paused'): ?>
                                <button type="button" class="btn btn-sm btn-outline-success ad-action-btn" data-admin-ad-action="resume" data-id="<?= (int)$item->id ?>" title="ازسرگیری">
                                    <i class="material-icons">play_circle</i>
                                </button>
                                <?php endif; ?>
                                <?php if (!in_array((string)$item->status, ['completed','cancelled','expired'], true)): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger ad-action-btn" data-admin-ad-action="reject" data-id="<?= (int)$item->id ?>" title="رد و آزادسازی بودجه">
                                    <i class="material-icons">block</i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (($totalPages ?? 0) > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
            <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
                <a class="page-link" href="<?= url('/admin/ads?page=' . $i . '&type=' . e($filters['type'] ?? '') . '&status=' . e($filters['status'] ?? '') . '&search=' . e($filters['search'] ?? '') . '&user_id=' . e($filters['user_id'] ?? '') . '&date_from=' . e($filters['date_from'] ?? '') . '&date_to=' . e($filters['date_to'] ?? '')) ?>"><?= fa_number($i) ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

</div>

<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="ads-index-data"><?= json_encode([
    'bulkUrl' => url('/admin/ads/bulk'),
    'actionUrlTemplate' => url('/admin/ads/__ID__/action'),
    'csrf' => csrf_token(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/adsindex.js') . '"></script>';
include view_path('layouts.admin');
?>

