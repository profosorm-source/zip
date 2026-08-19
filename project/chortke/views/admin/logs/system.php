<?php
$title = $title ?? 'لاگ‌های سیستمی';
ob_start();
$logs = $logs ?? []; $total = $total ?? 0; $page = $page ?? 1; $perPage = $perPage ?? 50; $totalPages = $totalPages ?? 1; $filters = $filters ?? [];
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">لاگ‌های سیستمی</h1>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/logs') ?>" class="btn btn-sm btn-outline-primary">همه</a>
            <a href="<?= url('/admin/logs/audit') ?>" class="btn btn-sm btn-outline-primary">Audit</a>
            <a href="<?= url('/admin/logs/security') ?>" class="btn btn-sm btn-outline-primary">امنیتی</a>
        </div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>سطح</th><th>پیام</th><th>زمان</th></tr></thead>
        <tbody>
            <?php if (empty($logs)): ?><tr><td colspan="4" class="text-center text-muted py-4">لاگی یافت نشد.</td></tr>
            <?php else: $i = ($page - 1) * $perPage; foreach ($logs as $log): $i++; ?>
            <tr><td><?= fa_number($i) ?></td><td><code><?= e($log->level ?? '—') ?></code></td>
                <td><?= e(mb_substr($log->message ?? '', 0, 150)) ?></td><td><?= e($log->created_at ?? '—') ?></td></tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table></div></div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
