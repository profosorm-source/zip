<?php
$title = 'Sentry Failed Jobs';
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

  
<?php $list = $failed_jobs['items'] ?? $failed_jobs['rows'] ?? $failed_jobs ?? []; $summary = $summary ?? []; ?>
<div class="row g-3 mb-3"><div class="col-md-4"><div class="card"><div class="card-body"><span class="text-muted">کل</span><div class="h4"><?= number_format((int)($summary['total'] ?? count($list))) ?></div></div></div></div></div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>#</th><th>Queue</th><th>Failed at</th><th>Exception</th></tr></thead><tbody><?php if(empty($list)): ?><tr><td colspan="4" class="text-center text-muted py-4">Failed job وجود ندارد.</td></tr><?php endif; ?><?php foreach($list as $job): $j=$job; $id=(int)($j['id']??0); ?><tr><td><a href="<?= url('/admin/sentry/failed-jobs/'.$id) ?>"><?= $id ?></a></td><td><?= e($j['queue']??'default') ?></td><td><?= e($j['failed_at']??$j['created_at']??'—') ?></td><td><?= e(mb_substr((string)($j['exception']??''),0,120)) ?></td></tr><?php endforeach; ?></tbody></table></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
