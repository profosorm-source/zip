<?php
$title = 'Failed Job Details';
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

  
<?php $j=$job ?? []; ?>
<div class="card"><div class="card-body"><p>شناسه: <?= e($j['id'] ?? '—') ?> | صف: <?= e($j['queue'] ?? 'default') ?></p><h5>Exception</h5><pre class="bg-light p-3 rounded" dir="ltr"><?= e($j['exception'] ?? '') ?></pre><h5>Payload</h5><pre class="bg-light p-3 rounded" dir="ltr"><?= e($j['payload'] ?? '') ?></pre></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
