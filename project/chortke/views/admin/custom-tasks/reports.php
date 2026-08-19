<?php
$title = 'گزارشات تسک‌های سفارشی';
ob_start();
$stats = $stats ?? [];
$reports = $reports ?? [];
?>
<div class="container-fluid py-4">
    <h1 class="h4 mb-3">گزارشات تسک‌های سفارشی</h1>
    
    <div class="row mb-4">
        <div class="col-md-3"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">کل تسک‌ها</h5><h2><?= fa_number($stats['total'] ?? 0) ?></h2>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">اجراها</h5><h2><?= fa_number($stats['executions'] ?? 0) ?></h2>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">تأیید شده</h5><h2 class="text-success"><?= fa_number($stats['approved'] ?? 0) ?></h2>
        </div></div></div>
        <div class="col-md-3"><div class="card text-center"><div class="card-body">
            <h5 class="text-muted">رد شده</h5><h2 class="text-danger"><?= fa_number($stats['rejected'] ?? 0) ?></h2>
        </div></div></div>
    </div>
    
    <div class="card"><div class="card-header">گزارشات اخیر</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>#</th><th>تسک</th><th>وضعیت</th><th>تعداد اجرا</th><th>نرخ موفقیت</th></tr></thead>
            <tbody>
                <?php if (empty($reports)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">گزارشی یافت نشد.</td></tr>
                <?php else: $i = 0; foreach ($reports as $r): $i++; ?>
                <tr><td><?= fa_number($i) ?></td><td><?= e($r->title ?? '—') ?></td>
                    <td><span class="badge bg-<?= ($r->status ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= e($r->status ?? '—') ?></span></td>
                    <td><?= fa_number($r->execution_count ?? 0) ?></td>
                    <td><?= fa_number($r->success_rate ?? 0) ?>%</td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
