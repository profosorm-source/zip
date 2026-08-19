<?php
$title = 'Sentry Issue Details';
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

  
<?php $i=$issue ?? []; $events=$events ?? []; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?= e($i['title'] ?? $i['message'] ?? 'Issue') ?></h2><p class="text-muted mb-1">سطح: <?= e($i['level'] ?? $i['severity'] ?? '—') ?> | وضعیت: <?= e($i['status'] ?? '—') ?></p><pre class="bg-light p-3 rounded small" dir="ltr"><?= e($i['stack_trace'] ?? $i['trace'] ?? $i['message'] ?? '') ?></pre></div></div>
<div class="card"><div class="card-header">Events</div><div class="card-body"><?php if(empty($events)): ?><p class="text-muted">رویدادی ثبت نشده است.</p><?php else: ?><ul><?php foreach($events as $ev): $e=$ev; ?><li><?= e($e['event_id'] ?? $e['created_at'] ?? 'event') ?></li><?php endforeach; ?></ul><?php endif; ?></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
