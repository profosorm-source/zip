<?php
$title = 'آمار حسابرسی';
ob_start();
$stats = $stats ?? [];
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">آمار حسابرسی</h1>
        <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-sm btn-outline-primary">← بازگشت</a>
    </div>
    <div class="row">
        <div class="col-md-4"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">کل رویدادها</h5>
            <h2><?= fa_number($stats['total'] ?? 0) ?></h2>
        </div></div></div>
        <div class="col-md-4"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">امروز</h5>
            <h2><?= fa_number($stats['today'] ?? 0) ?></h2>
        </div></div></div>
        <div class="col-md-4"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">انواع رویداد</h5>
            <h2><?= fa_number($stats['event_types'] ?? 0) ?></h2>
        </div></div></div>
    </div>
    <?php if (!empty($stats['by_type'])): ?>
    <div class="card mt-3"><div class="card-header">بر اساس نوع رویداد</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>نوع</th><th>تعداد</th></tr></thead>
            <tbody><?php foreach ($stats['by_type'] as $type => $count): ?>
                <tr><td><code><?= e($type) ?></code></td><td><?= fa_number($count) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
