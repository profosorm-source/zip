<?php
$title = 'Sentry Analytics';
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

  
<?php $hotspots=$hotspots??[]; $sources=$error_sources??[]; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Trend</h2><pre class="bg-light p-3 rounded" dir="ltr"><?= e(json_encode($trends ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></div></div>
<div class="row"><div class="col-md-6"><div class="card"><div class="card-header">Hotspots</div><div class="card-body"><?php if(empty($hotspots)): ?><p class="text-muted">داده‌ای موجود نیست.</p><?php else: ?><ul><?php foreach($hotspots as $h): $r=$h; ?><li><?= e($r['hotspot']??$r['name']??'—') ?></li><?php endforeach; ?></ul><?php endif; ?></div></div></div><div class="col-md-6"><div class="card"><div class="card-header">Sources</div><div class="card-body"><?php if(empty($sources)): ?><p class="text-muted">داده‌ای موجود نیست.</p><?php else: ?><ul><?php foreach($sources as $src): $r=$src; ?><li><?= e($r['culprit']??$r['source']??'—') ?></li><?php endforeach; ?></ul><?php endif; ?></div></div></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
