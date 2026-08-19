<?php
$title = 'Sentry Audit Trail';
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

  
<?php $items = $results['items'] ?? $results['rows'] ?? $results ?? []; ?>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>#</th><th>Event</th><th>User</th><th>Date</th></tr></thead><tbody><?php if(empty($items)): ?><tr><td colspan="4" class="text-center text-muted py-4">رکورد Audit یافت نشد.</td></tr><?php endif; ?><?php foreach($items as $item): $r=$item; ?><tr><td><?= e($r['id']??'') ?></td><td><?= e($r['event']??$r['action']??'—') ?></td><td><?= e($r['user_id']??'—') ?></td><td><?= e($r['created_at']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
