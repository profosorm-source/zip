<?php $title = 'جزئیات گزارش #' . $report->id;  ob_start(); ?>

<?php
$statusLabels = [
    'open' => ['باز', 'bg-primary'], 'in_progress' => ['در حال بررسی', 'bg-info'],
    'resolved' => ['حل شده', 'bg-success'], 'closed' => ['بسته شده', 'bg-secondary'],
    'duplicate' => ['تکراری', 'bg-warning'], 'wont_fix' => ['رد شده', 'bg-danger'],
];
$priorityLabels = [
    'low' => ['کم', 'bg-secondary'], 'normal' => ['متوسط', 'bg-info'],
    'high' => ['بالا', 'bg-warning'], 'critical' => ['بحرانی', 'bg-danger'],
];
$categoryLabels = [
    'ui_issue' => 'ظاهری', 'functional' => 'عملکردی', 'payment' => 'پرداخت',
    'security' => 'امنیتی', 'performance' => 'سرعت', 'content' => 'محتوا', 'other' => 'سایر',
];
?>

<div class="container-fluid">
    <a href="<?= url('/admin/bug-reports') ?>" class="btn btn-sm btn-outline-secondary mb-3">
        <span class="material-icons">arrow_forward</span> بازگشت
    </a>

    <div class="row">
        <!-- اطلاعات اصلی -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">گزارش #<?= e($report->id) ?></h6>
                    <div>
                        <?php if ($report->is_suspicious): ?>
                            <span class="badge bg-danger me-1">⚠️ مشکوک</span>
                        <?php endif; ?>
                        <?php $st = $statusLabels[$report->status] ?? ['?', 'bg-secondary']; ?>
                        <span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
                        <?php $pri = $priorityLabels[$report->priority] ?? ['?', 'bg-secondary']; ?>
                        <span class="badge <?= e($pri[1]) ?>"><?= e($pri[0]) ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3"><strong>کاربر:</strong> <?= e($report->user_full_name ?? '') ?></div>
                        <div class="col-md-3"><strong>دسته:</strong> <?= e($categoryLabels[$report->category] ?? '') ?></div>
                        <div class="col-md-3"><strong>تاریخ:</strong> <?= e(to_jalali($report->created_at ?? '')) ?></div>
                        <div class="col-md-3"><strong>تعداد امروز:</strong> <?= e($report->daily_report_count) ?></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4"><strong>مرورگر:</strong> <?= e($report->browser ?? '—') ?></div>
                        <div class="col-md-4"><strong>سیستم‌عامل:</strong> <?= e($report->os ?? '—') ?></div>
                        <div class="col-md-4"><strong>رزولوشن:</strong> <?= e($report->screen_resolution ?? '—') ?></div>
                    </div>
                    <div class="mb-3">
                        <strong>صفحه:</strong> <code dir="ltr"><?= e($report->page_url ?? '') ?></code>
                    </div>
                    <div class="mb-3">
                        <strong>IP:</strong> <code><?= e($report->ip_address ?? '') ?></code>
                        <?php if ($report->device_fingerprint): ?>
                            &nbsp; <strong>FP:</strong> <code><?= e(\mb_substr($report->device_fingerprint, 0, 16)) ?>...</code>
                        <?php endif; ?>
                    </div>

                    <hr>
                    <strong>توضیحات:</strong>
                    <div class="p-3 bg-light rounded mt-2"><?= e($report->description) ?></div>

                    <?php if ($report->screenshot_path): ?>
                        <div class="mt-3">
                            <strong>اسکرین‌شات:</strong><br>
                            <a href="<?= asset($report->screenshot_path) ?>" target="_blank">
                                <img src="<?= asset($report->screenshot_path) ?>" alt="screenshot" class="mt-1" class="aggr-cleaned">
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- پیام‌ها -->
            <div class="card">
                <div class="card-header"><h6 class="mb-0">پیام‌ها و یادداشت‌ها</h6></div>
                <div class="card-body">
                    <?php if (!empty($comments)): ?>
                        <?php foreach ($comments as $c): ?>
                            <div class="d-flex mb-3 <?= $c->user_type === 'admin' ? 'flex-row-reverse' : '' ?>">
                                <div class="p-3 rounded">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong >
                                            <?= e($c->user_full_name ?? '') ?>
                                            <?php if ($c->user_type === 'admin'): ?><span class="badge bg-primary">ادمین</span><?php endif; ?>
                                            <?php if ($c->is_internal): ?><span class="badge bg-warning">داخلی</span><?php endif; ?>
                                        </strong>
                                        <small class="text-muted"><?= e(to_jalali($c->created_at ?? '')) ?></small>
                                    </div>
                                    <div ><?= e($c->comment) ?></div>
                                    <?php if ($c->attachment_path): ?>
                                        <img src="<?= asset($c->attachment_path) ?>" alt="پیوست" class="mt-2" class="aggr-cleaned">
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">بدون پیام</p>
                    <?php endif; ?>

                    <hr>
                    <div class="row g-2">
                        <div class="col-md-9">
                            <textarea id="adminComment" class="form-control form-control-sm" rows="2" placeholder="پاسخ یا یادداشت..."></textarea>
                        </div>
                        <div class="col-md-3 d-flex flex-column gap-1">
                            <div class="form-check form-check-inline">
                                <input type="checkbox" id="isInternal" class="form-check-input">
                                <label for="isInternal" class="form-check-label">یادداشت داخلی</label>
                            </div>
                            <button class="btn btn-sm btn-primary" id="sendAdminComment">ارسال پیام</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- پنل کناری -->
        <div class="col-lg-4">
            <!-- تغییر وضعیت -->
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">تغییر وضعیت</h6></div>
                <div class="card-body">
                    <select id="changeStatus" class="form-select form-select-sm mb-2">
                        <?php foreach ($statusLabels as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $report->status === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea id="statusNote" class="form-control form-control-sm mb-2" rows="2" placeholder="یادداشت (اختیاری)"></textarea>
                    <button class="btn btn-sm btn-primary w-100" id="applyStatus">اعمال تغییر</button>
                </div>
            </div>

            <!-- تغییر اولویت -->
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">اولویت</h6></div>
                <div class="card-body">
                    <select id="changePriority" class="form-select form-select-sm mb-2">
                        <?php foreach ($priorityLabels as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $report->priority === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-warning w-100" id="applyPriority">تغییر اولویت</button>
                </div>
            </div>

            <!-- عملیات -->
            <div class="card">
                <div class="card-header"><h6 class="mb-0">عملیات</h6></div>
                <div class="card-body d-flex flex-column gap-2">
                    <button class="btn btn-sm btn-outline-danger" id="toggleSuspicious">
                        <?= $report->is_suspicious ? '✅ حذف برچسب مشکوک' : '⚠️ علامت‌گذاری مشکوک' ?>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" id="deleteReport">🗑️ حذف گزارش</button>
                </div>
            </div>
        </div>
    </div>
</div>




<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
