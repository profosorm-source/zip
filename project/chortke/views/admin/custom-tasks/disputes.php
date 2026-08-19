<?php
$title = 'مدیریت اختلاف‌ها';

ob_start();
?>
<div id="customTasksRoot" data-approve-url="<?= url('/admin/custom-tasks/approve') ?>" data-resolve-url="<?= url('/admin/custom-tasks/disputes/resolve') ?>"></div>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-danger">gavel</i> مدیریت اختلاف‌ها</h4>
        <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="ref_type" class="form-select form-select-sm">
                <option value="">همه ماژول‌ها</option>
                <option value="custom_task_submission" <?= ($filters['ref_type'] ?? '') === 'custom_task_submission' ? 'selected' : '' ?>>تسک سفارشی</option>
                <option value="order" <?= in_array($filters['ref_type'] ?? '', ['order','story_order','influencer_order','influencer'], true) ? 'selected' : '' ?>>اینفلوئنسر / سفارش</option>
                <option value="vitrine_listing" <?= ($filters['ref_type'] ?? '') === 'vitrine_listing' ? 'selected' : '' ?>>ویترین</option>
            </select>
            <select name="status" class="form-select form-select-sm">
                <option value="">همه وضعیت‌ها</option>
                <option value="open" <?= ($filters['status'] ?? '') === 'open' ? 'selected' : '' ?>>باز</option>
                <option value="under_review" <?= ($filters['status'] ?? '') === 'under_review' ? 'selected' : '' ?>>در حال بررسی</option>
                <option value="resolved" <?= ($filters['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>حل‌شده</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <span class="text-muted ms-auto"><?= number_format($total) ?> مورد</span>
        </form>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>ماژول</th><th>موضوع</th><th>ثبت‌کننده</th><th>نقش</th><th>دلیل</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($disputes)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">اختلافی یافت نشد.</td></tr>
                    <?php else: ?>
                    <?php
                    $dStatusLabels = ['open'=>'باز','open_peer'=>'باز','under_review'=>'در بررسی','escalated'=>'ارجاع به ادمین','resolved'=>'حل‌شده','resolved_for_executor'=>'حل به نفع مجری','resolved_for_advertiser'=>'حل به نفع تبلیغ‌دهنده','resolved_admin'=>'حل‌شده','closed'=>'بسته'];
                    $dStatusClasses = ['open'=>'badge-danger','open_peer'=>'badge-danger','under_review'=>'badge-warning','escalated'=>'badge-warning','resolved'=>'badge-success','resolved_for_executor'=>'badge-success','resolved_for_advertiser'=>'badge-success','resolved_admin'=>'badge-success','closed'=>'badge-secondary'];
                    $roleLabels = ['worker'=>'کارمند','advertiser'=>'تبلیغ‌دهنده','customer'=>'مشتری','party'=>'طرف معامله'];
                    $moduleLabels = ['custom_task_submission'=>'تسک سفارشی','order'=>'اینفلوئنسر','story_order'=>'اینفلوئنسر','influencer_order'=>'اینفلوئنسر','influencer'=>'اینفلوئنسر','vitrine_listing'=>'ویترین'];
                    ?>
                    <?php foreach ($disputes as $idx => $d): ?>
                    <tr>
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td><span class="badge bg-info"><?= e($moduleLabels[$d->ref_type] ?? $d->ref_type) ?></span></td>
                        <td ><?= e(\mb_substr($d->task_title ?? '—', 0, 30)) ?></td>
                        <td ><?= e($d->raiser_name ?? '—') ?></td>
                        <td><span class="badge bg-secondary"><?= e($roleLabels[$d->raised_by_role] ?? $d->raised_by_role) ?></span></td>
                        <td ><a href="<?= url('/admin/custom-tasks/disputes/' . (int)$d->id) ?>"><?= e(\mb_substr($d->reason, 0, 60)) ?></a></td>
                        <td><span class="badge <?= $dStatusClasses[$d->status] ?? '' ?>"><?= e($dStatusLabels[$d->status] ?? $d->status) ?></span></td>
                        <td ><?= to_jalali($d->created_at ?? '') ?></td>
                        <td>
                            <?php if (\in_array($d->status, ['open', 'open_peer', 'under_review', 'escalated'])): ?>
                            <button class="btn btn-sm btn-primary btn-resolve" data-id="<?= e($d->id) ?>" data-reason="<?= e($d->reason ?? '') ?>" data-ref-type="<?= e($d->ref_type ?? '') ?>">
                                <i class="material-icons">gavel</i> داوری
                            </button>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('/admin/custom-tasks/disputes/' . (int)$d->id) ?>">جزئیات</a>
                            <?php elseif (in_array($d->status, ['resolved','resolved_admin','resolved_for_executor','resolved_for_advertiser','closed'], true)): ?>
                            <span class="text-muted">
                                <?php
                                $decisionLabels = ['worker_wins'=>'حق با کارمند','advertiser_wins'=>'حق با تبلیغ‌دهنده','executor'=>'حق با مجری','advertiser'=>'حق با تبلیغ‌دهنده'];
                                echo $decisionLabels[$d->admin_decision] ?? '—';
                                ?>
                            </span>
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
                <a class="page-link" href="<?= url('/admin/custom-tasks/disputes?page=' . $i . '&status=' . e($filters['status'] ?? '') . '&ref_type=' . e($filters['ref_type'] ?? '')) ?>"><?= e($i) ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>



<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/customtasks.js') . '"></script>';
include view_path('layouts.admin');
?>
