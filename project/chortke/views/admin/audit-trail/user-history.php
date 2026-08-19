<?php
$title = 'تاریخچه حسابرسی کاربر';
ob_start();
$history = $history ?? [];
$userId = $userId ?? 0;
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">تاریخچه حسابرسی — کاربر #<?= fa_number($userId) ?></h1>
        <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-sm btn-outline-primary">← بازگشت</a>
    </div>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>#</th><th>رویداد</th><th>موجودیت</th><th>IP</th><th>زمان</th></tr></thead>
        <tbody>
            <?php if (empty($history)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">تاریخچه‌ای یافت نشد.</td></tr>
            <?php else: $i = 0; foreach ($history as $h): $i++; ?>
            <tr>
                <td><?= fa_number($i) ?></td>
                <td><code><?= e($h->event) ?></code></td>
                <td><?= e(($h->entity_type ?? '') . '#' . ($h->entity_id ?? '')) ?></td>
                <td dir="ltr"><?= e($h->ip_address ?? '—') ?></td>
                <td><?= e($h->created_at) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
