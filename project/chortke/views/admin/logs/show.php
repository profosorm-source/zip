<?php
$title = $title ?? 'جزئیات لاگ';

ob_start();

$rawMetadata = $log->metadata ?? null;
$metadataJson = null;
if (is_string($rawMetadata) && $rawMetadata !== '') {
    $decoded = json_decode($rawMetadata, true);
    if (is_array($decoded)) {
        $metadataJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $metadataJson = $rawMetadata;
    }
}
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0">جزئیات لاگ</h5>
            <small class="text-muted">نوع: <?= e($type) ?></small>
        </div>
        <a href="<?= url('/admin/logs/' . $type) ?>" class="btn btn-sm btn-secondary">بازگشت</a>
    </div>

    <div class="card-body">
        <?php if (empty($log)): ?>
            <div class="alert alert-warning">لاگ مورد نظر یافت نشد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered mb-4">
                    <tbody>
                        <tr>
                            <th>شناسه</th>
                            <td><?= e($log->id ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>نوع</th>
                            <td><?= e($log->type ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>سطح</th>
                            <td><?= e($log->level ?? $log->action ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>متن</th>
                            <td><?= e($log->message ?? $log->description ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>کاربر</th>
                            <td><?= e($log->full_name ?? $log->user_id ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>ایمیل</th>
                            <td><?= e($log->email ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>آی‌پی</th>
                            <td><code><?= e($log->ip_address ?? '-') ?></code></td>
                        </tr>
                        <tr>
                            <th>User Agent</th>
                            <td><pre class="m-0"><?= e($log->user_agent ?? '-') ?></pre></td>
                        </tr>
                        <tr>
                            <th>تاریخ</th>
                            <td><?= e($log->created_at ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>درخواست</th>
                            <td><?= e($log->request_id ?? '-') ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="mb-3">
                    <h6>متادیتا</h6>
                    <pre class="bg-light p-3 rounded">
<?= e($metadataJson ?? '-'); ?>
                    </pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
