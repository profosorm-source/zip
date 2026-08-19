<?php
/** @var array $tokens لیست توکن‌ها */
/** @var int $total */
$title = 'مدیریت توکن‌های API';
$filterSearch = e($search ?? ($_GET['search'] ?? ''));
$filterStatus = $statusFilter ?? ($_GET['status'] ?? '');
ob_start();
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">🔑 مدیریت توکن‌های API</h4>
        <div class="text-muted small">مجموع: <?= number_format($total ?? 0) ?> توکن فعال</div>
    </div>

    <!-- آمار سریع -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <span class="material-icons text-success fs-3">vpn_key</span>
                    <h4 class="mb-0 mt-1"><?= number_format($stats['active'] ?? 0) ?></h4>
                    <small class="text-muted">فعال</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <span class="material-icons text-danger fs-3">key_off</span>
                    <h4 class="mb-0 mt-1"><?= number_format($stats['revoked'] ?? 0) ?></h4>
                    <small class="text-muted">باطل شده</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <span class="material-icons text-warning fs-3">timer_off</span>
                    <h4 class="mb-0 mt-1"><?= number_format($stats['expired'] ?? 0) ?></h4>
                    <small class="text-muted">منقضی</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <span class="material-icons text-info fs-3">touch_app</span>
                    <h4 class="mb-0 mt-1"><?= number_format($stats['used_today'] ?? 0) ?></h4>
                    <small class="text-muted">استفاده امروز</small>
                </div>
            </div>
        </div>
    </div>

    <!-- فیلتر -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو نام توکن یا ایمیل کاربر..." value="<?= e($filterSearch) ?>">
                <select name="status" class="form-select form-select-sm">
                    <option value="">همه</option>
                    <option value="active"  <?= $filterStatus==='active'  ? 'selected':'' ?>>فعال</option>
                    <option value="revoked" <?= $filterStatus==='revoked' ? 'selected':'' ?>>باطل</option>
                    <option value="expired" <?= $filterStatus==='expired' ? 'selected':'' ?>>منقضی</option>
                </select>
                <button class="btn btn-secondary btn-sm">فیلتر</button>
                <a href="<?= url('/admin/api-tokens') ?>" class="btn btn-outline-secondary btn-sm">پاک</a>
                <div class="ms-auto">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-click="revokeExpired">
                        <span class="material-icons align-middle">cleanup</span>
                        باطل کردن منقضی‌ها
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>کاربر</th>
                            <th>نام توکن</th>
                            <th>دسترسی</th>
                            <th>آخرین استفاده</th>
                            <th>استفاده</th>
                            <th>انقضا</th>
                            <th>وضعیت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tokens)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">موردی یافت نشد</td></tr>
                        <?php else: ?>
                        <?php foreach ($tokens as $t): ?>
                        <tr>
                            <td class="text-muted"><?= (int)$t->id ?></td>
                            <td>
                                <a href="<?= url('/admin/users/' . (int)$t->user_id . '/edit') ?>" class="text-decoration-none">
                                    <?= e($t->full_name ?? 'کاربر ' . $t->user_id) ?>
                                </a>
                                <div class="text-muted"><?= e($t->email ?? '') ?></div>
                            </td>
                            <td><?= e($t->name) ?></td>
                            <td>
                                <?php foreach (explode(',', $t->scopes ?? 'read') as $s): ?>
                                    <span class="badge bg-secondary"><?= e(trim($s)) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-muted"><?= $t->last_used_at ? to_jalali($t->last_used_at) : 'هرگز' ?></td>
                            <td><?= number_format((int)($t->use_count ?? 0)) ?></td>
                            <td>
                                <?php if (!$t->expires_at): ?>
                                    <span class="text-muted">بدون انقضا</span>
                                <?php elseif (strtotime($t->expires_at) < time()): ?>
                                    <span class="text-danger"><?= to_jalali($t->expires_at) ?></span>
                                <?php else: ?>
                                    <span class="text-success"><?= to_jalali($t->expires_at) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t->revoked): ?>
                                    <span class="badge bg-danger">باطل</span>
                                <?php elseif ($t->expires_at && strtotime($t->expires_at) < time()): ?>
                                    <span class="badge bg-warning text-dark">منقضی</span>
                                <?php else: ?>
                                    <span class="badge bg-success">فعال</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$t->revoked): ?>
                                <button class="btn btn-xs btn-outline-danger"
                                        data-click="revokeToken" data-args="<?= (int)$t->id ?>"
                                        title="باطل کردن">
                                    <span class="material-icons">block</span>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
