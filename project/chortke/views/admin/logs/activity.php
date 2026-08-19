<?php
$title = $title ?? 'لاگ‌های فعالیت';
ob_start();
$logs = $logs ?? [];
?>
<div class="content-header d-flex justify-content-between align-items-center">
    <div>
        <h4 class="page-title mb-1">
            <span class="material-icons text-primary align-middle">history</span>
            لاگ‌های فعالیت
        </h4>
        <p class="text-muted mb-0 text-12">مجموع: <?= number_format($total ?? 0) ?> رویداد</p>
    </div>
</div>

<!-- فیلترها -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:200px"
                   placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
            <input type="number" name="user_id" class="form-control form-control-sm" style="max-width:120px"
                   placeholder="شناسه کاربر" value="<?= e($filters['user_id'] ?? '') ?>">
            <input type="text" name="action" class="form-control form-control-sm" style="max-width:150px"
                   placeholder="اکشن" value="<?= e($filters['action'] ?? '') ?>">
            <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:150px"
                   value="<?= e($filters['date_from'] ?? '') ?>">
            <input type="date" name="date_to" class="form-control form-control-sm" style="max-width:150px"
                   value="<?= e($filters['date_to'] ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <a href="<?= url('/admin/logs/activity') ?>" class="btn btn-outline-secondary btn-sm">پاک کردن</a>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5">
                <span class="material-icons text-muted" style="font-size:3rem">receipt_long</span>
                <p class="text-muted mt-3 mb-0">هیچ لاگی ثبت نشده است</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>کاربر</th>
                            <th>رویداد</th>
                            <th>اکشن</th>
                            <th>توضیحات</th>
                            <th>IP</th>
                            <th>تاریخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-muted"><?= (int)($log->id ?? 0) ?></td>
                            <td>
                                <?php if (!empty($log->user_id)): ?>
                                    <a href="<?= url('/admin/users/' . (int)$log->user_id . '/edit') ?>"
                                       class="text-decoration-none">
                                        <?= e($log->full_name ?? $log->username ?? ('#' . $log->user_id)) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">سیستم</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($log->event ?? '') ?></code></td>
                            <td><span class="badge bg-info bg-opacity-10 text-info"><?= e($log->action ?? '—') ?></span></td>
                            <td class="text-muted"><?= e(mb_substr($log->description ?? '', 0, 80)) ?></td>
                            <td class="text-muted" dir="ltr"><?= e($log->ip_address ?? '—') ?></td>
                            <td class="text-muted"><?= e(to_jalali($log->created_at ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (($totalPages ?? 1) > 1): ?>
            <div class="card-footer bg-white">
                <?php
                $queryParams = $_GET;
                ?>
                <nav>
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <?php for ($p = 1; $p <= ($totalPages ?? 1); $p++): ?>
                            <li class="page-item <?= $p === ($page ?? 1) ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($queryParams, ['page' => $p])) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
