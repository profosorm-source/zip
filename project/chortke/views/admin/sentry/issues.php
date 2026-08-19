<?php
$title = 'Sentry Issues';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0"><?= e($title) ?></h1></div>
  <div class="mb-3 d-flex gap-2 flex-wrap">
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry') ?>">داشبورد</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/issues') ?>">Issues</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/failed-jobs') ?>">Failed Jobs</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/outbox-dlq') ?>">Outbox DLQ</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/performance') ?>">Performance</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/analytics') ?>">Analytics</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/alerts') ?>">Alerts</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('/admin/sentry/audit') ?>">Audit</a>
</div>

  
<?php $list = $issues['items'] ?? $issues['rows'] ?? $issues ?? [];  ?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>#</th><th>عنوان/پیام</th><th>سطح</th><th>وضعیت</th><th>آخرین مشاهده</th></tr></thead><tbody>
<?php if (empty($list)): ?><tr><td colspan="5" class="text-center text-muted py-4">Issue فعالی یافت نشد.</td></tr><?php endif; ?>
<?php foreach ($list as $item): $r=$item; $id=(int)($r['id'] ?? $r['issue_id'] ?? 0); ?>
<tr><td><?= $id ?></td><td><a href="<?= url('/admin/sentry/issues/' . $id) ?>"><?= e($r['title'] ?? $r['message'] ?? $r['fingerprint'] ?? '—') ?></a></td><td><span class="badge bg-secondary"><?= e($r['level'] ?? $r['severity'] ?? '—') ?></span></td><td><?= e($r['status'] ?? 'unresolved') ?></td><td><?= e($r['last_seen'] ?? $r['created_at'] ?? '—') ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
