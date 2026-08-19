<?php
$title = 'مدیریت وظایف سفارشی';

ob_start();
?>
<div id="customTasksRoot" data-approve-url="<?= url('/admin/custom-tasks/approve') ?>" data-resolve-url="<?= url('/admin/custom-tasks/disputes/resolve') ?>"></div>
<?php
$statusLabels = custom_task_status_labels_map();
$statusClasses = custom_task_status_classes_map();
$typeLabels = custom_task_types();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-primary">assignment</i> مدیریت وظایف سفارشی</h4>
        <a href="<?= url('/admin/custom-tasks/disputes') ?>" class="btn btn-outline-danger btn-sm">
            <i class="material-icons">gavel</i> اختلاف‌ها
        </a>
    </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
            <select name="status" class="form-select form-select-sm">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="task_type" class="form-select form-select-sm">
                <option value="">همه انواع</option>
                <?php foreach ($typeLabels as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($filters['task_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-secondary btn-sm">پاکسازی</a>
            <span class="text-muted ms-auto"><?= number_format($total) ?> مورد</span>
        </form>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>عنوان</th><th>تبلیغ‌دهنده</th><th>نوع</th>
                        <th>قیمت</th><th>تعداد</th><th>بودجه</th><th>کارمزد</th>
                        <th>وضعیت</th><th>تاریخ</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tasks as $idx => $t): ?>
                    <tr id="task-row-<?= e($t->id) ?>">
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td >
                            <strong ><?= e(\mb_substr($t->title, 0, 30)) ?></strong>
                        </td>
                        <td ><?= e($t->creator_name ?? '—') ?></td>
                        <td><span class="badge"><?= e($typeLabels[$t->task_type] ?? $t->task_type) ?></span></td>
                        <td><?= $t->currency === 'usdt' ? number_format($t->price_per_task, 2) : number_format($t->price_per_task) ?></td>
                        <td><?= e($t->completed_count) ?>/<?= e($t->total_quantity) ?></td>
                        <td ><?= $t->currency === 'usdt' ? number_format($t->total_budget, 2) : number_format($t->total_budget) ?></td>
                        <td ><?= $t->currency === 'usdt' ? number_format($t->site_fee_amount, 2) : number_format($t->site_fee_amount) ?></td>
                        <td><span class="badge <?= $statusClasses[$t->status] ?? '' ?>"><?= e($statusLabels[$t->status] ?? $t->status) ?></span></td>
                        <td ><?= to_jalali($t->created_at ?? '') ?></td>
                        <td>
                            <?php if ($t->status === 'pending_review'): ?>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-success btn-approve-task" data-id="<?= e($t->id) ?>" data-decision="approve" title="تأیید">
                                    <i class="material-icons">check</i>
                                </button>
                                <button class="btn btn-sm btn-danger btn-approve-task" data-id="<?= e($t->id) ?>" data-decision="reject" title="رد">
                                    <i class="material-icons">close</i>
                                </button>
                            </div>
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

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= \min($pages, 20); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= url('/admin/custom-tasks?page=' . $i . '&status=' . e($filters['status'] ?? '') . '&task_type=' . e($filters['task_type'] ?? '') . '&search=' . e($filters['search'] ?? '')) ?>"><?= e($i) ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
