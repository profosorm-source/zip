<?php
$title = 'جزئیات تسک سفارشی #' . ($task->id ?? '');

ob_start();
$statusLabels = $statusLabels ?? [];
$submissionStatusLabels = $submissionStatusLabels ?? [];
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0">
            <i class="material-icons text-primary">assignment</i>
            <?= e($title) ?>
        </h4>
        <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_back</i> بازگشت به لیست
        </a>
    </div>
</div>

<!-- Task Info -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>اطلاعات تسک</strong>
        <span class="badge <?= ($task->status ?? '') === 'active' ? 'badge-success' : (($task->status ?? '') === 'pending_review' ? 'badge-warning' : 'badge-secondary') ?>" class="aggr-cleaned">
            <?= e($statusLabels[$task->status ?? ''] ?? ($task->status ?? '—')) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">شناسه</div>
                <div class="fw-bold"><?= fa_number($task->id ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">کاربر</div>
                <div class="fw-bold"><?= e($task->user_name ?? ($task->user_email ?? ('#' . ($task->user_id ?? '—')))) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع تسک</div>
                <div class="fw-bold"><?= e($task->task_type ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع اثبات</div>
                <div class="fw-bold"><?= e($task->proof_type ?? '—') ?></div>
            </div>
            <div class="col-md-12 mb-2">
                <div class="text-muted">عنوان</div>
                <div class="fw-bold fs-5"><?= e($task->title ?? '—') ?></div>
            </div>
            <?php if (!empty($task->description)): ?>
            <div class="col-md-12 mb-2">
                <div class="text-muted">توضیحات</div>
                <div ><?= nl2br(e($task->description)) ?></div>
            </div>
            <?php endif; ?>
            <div class="col-md-3 mb-2">
                <div class="text-muted">بودجه کل</div>
                <div class="fw-bold"><?= number_format($task->total_budget ?? 0) ?> <?= e($task->currency === 'usdt' ? 'USDT' : 'تومان') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">باقیمانده</div>
                <div class="fw-bold <?= (($task->remaining_budget ?? 0) <= 0) ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($task->remaining_budget ?? 0) ?> <?= e($task->currency === 'usdt' ? 'USDT' : 'تومان') ?>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">قیمت هر تسک</div>
                <div class="fw-bold"><?= number_format($task->price_per_task ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">کارمزد</div>
                <div class="fw-bold"><?= number_format($task->site_fee_amount ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تعداد کل</div>
                <div class="fw-bold"><?= fa_number($task->total_count ?? ($task->total_quantity ?? 0)) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تکمیل‌شده</div>
                <div class="fw-bold"><?= fa_number($task->completed_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">در حال انجام</div>
                <div class="fw-bold"><?= fa_number($task->pending_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">مهلت</div>
                <div class="fw-bold"><?= to_jalali($task->deadline ?? '') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Submissions -->
<div class="card mb-4">
    <div class="card-header"><strong>Submissions (<?= fa_number(count($submissions ?? [])) ?>)</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>ID</th><th>کاربر</th><th>وضعیت</th>
                        <th>اثبات</th><th>تاریخ ارسال</th><th>تاریخ بررسی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Submission‌ای ثبت نشده.</td></tr>
                    <?php else: ?>
                    <?php foreach ($submissions as $idx => $s): ?>
                    <tr>
                        <td class="text-muted"><?= fa_number($idx + 1) ?></td>
                        <td class="fw-bold"><?= fa_number($s->id ?? 0) ?></td>
                        <td ><?= e($s->worker_name ?? ($s->worker_username ?? ('#' . ($s->worker_id ?? '—')))) ?></td>
                        <td>
                            <span class="badge <?= ($s->status ?? '') === 'approved' ? 'badge-success' : (($s->status ?? '') === 'rejected' ? 'badge-danger' : 'badge-warning') ?>" class="aggr-cleaned">
                                <?= e($submissionStatusLabels[$s->status ?? ''] ?? ($s->status ?? '—')) ?>
                            </span>
                        </td>
                        <td >
                            <?php if (!empty($s->proof_url)): ?>
                                <a href="<?= e($s->proof_url) ?>" target="_blank">مشاهده اثبات</a>
                            <?php elseif (!empty($s->proof_text)): ?>
                                <?= e(mb_substr($s->proof_text, 0, 60)) ?><?= (mb_strlen($s->proof_text ?? '') > 60) ? '...' : '' ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td ><?= to_jalali($s->submitted_at ?? ($s->created_at ?? '')) ?></td>
                        <td ><?= to_jalali($s->reviewed_at ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>