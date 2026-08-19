<?php
$title = 'جزئیات رکورد حسابرسی';
ob_start();
$record = $record ?? null;
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">جزئیات رکورد حسابرسی</h1>
        <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-sm btn-outline-primary">← بازگشت</a>
    </div>

    <?php if (!$record): ?>
    <div class="alert alert-warning">رکورد یافت نشد.</div>
    <?php else: ?>
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">شناسه</dt><dd class="col-sm-9"><?= e($record->id) ?></dd>
                <dt class="col-sm-3">رویداد</dt><dd class="col-sm-9"><code><?= e($record->event) ?></code></dd>
                <dt class="col-sm-3">نوع موجودیت</dt><dd class="col-sm-9"><?= e($record->entity_type ?? '—') ?></dd>
                <dt class="col-sm-3">شناسه موجودیت</dt><dd class="col-sm-9"><?= e($record->entity_id ?? '—') ?></dd>
                <dt class="col-sm-3">کاربر</dt><dd class="col-sm-9"><?= e($record->user_id ?? '—') ?></dd>
                <dt class="col-sm-3">IP</dt><dd class="col-sm-9" dir="ltr"><?= e($record->ip_address ?? '—') ?></dd>
                <dt class="col-sm-3">زمان</dt><dd class="col-sm-9"><?= e($record->created_at ?? '—') ?></dd>
            </dl>
        </div>
    </div>
    <?php if (!empty($record->old_values) || !empty($record->new_values)): ?>
    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card"><div class="card-header">مقادیر قبلی</div>
                <div class="card-body"><pre class="mb-0"><code><?= e(json_encode($record->old_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></code></pre></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-header">مقادیر جدید</div>
                <div class="card-body"><pre class="mb-0"><code><?= e(json_encode($record->new_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></code></pre></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
