<?php
$title = 'Outbox DLQ';
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

  
<?php $list = $outbox['items'] ?? $outbox['rows'] ?? $outbox ?? []; $summary=$summary??[]; ?>
<div class="card"><div class="card-body"><div class="text-muted">کل رکوردها: <?= number_format((int)($summary['total'] ?? count($list))) ?></div></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>#</th><th>Event</th><th>Status</th><th>Attempts</th><th>Error</th></tr></thead><tbody><?php if(empty($list)): ?><tr><td colspan="5" class="text-center text-muted py-4">Outbox DLQ خالی است.</td></tr><?php endif; ?><?php foreach($list as $ev): $e=$ev; ?><tr><td><?= e($e['id']??'') ?></td><td><?= e($e['event_type']??$e['type']??'') ?></td><td><?= e($e['status']??'') ?></td><td><?= e($e['attempts']??$e['retry_count']??0) ?></td><td><?= e(mb_substr((string)($e['last_error']??''),0,120)) ?></td></tr><?php endforeach; ?></tbody></table></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
