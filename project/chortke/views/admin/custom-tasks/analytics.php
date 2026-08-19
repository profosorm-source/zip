<?php
$title = 'آمار تسک‌های سفارشی';

ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-primary">analytics</i> آمار تسک‌های سفارشی</h4>
        <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_back</i> بازگشت به لیست
        </a>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted">کل تسک‌ها</div>
            <div class="fw-bold text-primary"><?= fa_number($taskStats['total'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted">فعال</div>
            <div class="fw-bold text-success"><?= fa_number($taskStats['active'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted">Submissions</div>
            <div class="fw-bold text-info"><?= fa_number($submissionStats['total'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="text-muted">تأییدشده</div>
            <div class="fw-bold text-success"><?= fa_number($submissionStats['approved'] ?? 0) ?></div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><strong>تسک‌های پرطرفدار</strong></div>
    <div class="card-body">
        <?php if (empty($trending)): ?>
            <div class="text-muted text-center py-4">داده‌ای موجود نیست.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>تسک</th><th>تعداد submissions</th><th>تأییدشده</th><th>درصد تأیید</th></tr></thead>
                <tbody>
                <?php foreach ($trending as $t): ?>
                    <tr>
                        <td><?= e($t->title ?? '—') ?></td>
                        <td><?= fa_number($t->submission_count ?? 0) ?></td>
                        <td><?= fa_number($t->approved_count ?? 0) ?></td>
                        <td><?= number_format($t->approval_rate ?? 0, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>