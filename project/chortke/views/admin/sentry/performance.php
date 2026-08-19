<?php
$title = 'Sentry Performance';
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

  
<?php $stats=$stats??[]; $slow=$slowest_endpoints??[]; ?>
<div class="row g-3 mb-3"><?php foreach([['میانگین پاسخ',$stats['avg_response_time']??0],['درخواست‌ها',$stats['total_requests']??0],['کندترین‌ها',count($slow)]] as $c): ?><div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted"><?= e($c[0]) ?></div><div class="h4"><?= e((string)$c[1]) ?></div></div></div></div><?php endforeach; ?></div>
<div class="card"><div class="card-header">کندترین endpointها</div><div class="table-responsive"><table class="table mb-0"><tbody><?php if(empty($slow)): ?><tr><td class="text-muted text-center py-4">داده‌ای موجود نیست.</td></tr><?php endif; ?><?php foreach($slow as $row): $r=$row; ?><tr><td><?= e($r['name']??$r['route']??'—') ?></td><td><?= e($r['avg_duration']??$r['duration_ms']??0) ?> ms</td></tr><?php endforeach; ?></tbody></table></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
