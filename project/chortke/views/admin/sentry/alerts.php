<?php
$title = 'Sentry Alerts';
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

  
<?php $active_alerts=$active_alerts??[]; $rules=$rules??[]; ?>
<div class="row"><div class="col-md-6"><div class="card"><div class="card-header">هشدارهای فعال</div><div class="table-responsive"><table class="table mb-0"><tbody><?php if(empty($active_alerts)): ?><tr><td class="text-muted text-center py-4">هشدار فعالی وجود ندارد.</td></tr><?php endif; ?><?php foreach($active_alerts as $a): $r=$a; ?><tr><td><?= e($r['title']??$r['message']??'alert') ?></td><td><?= e($r['severity']??'medium') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><div class="col-md-6"><div class="card"><div class="card-header">قوانین</div><div class="table-responsive"><table class="table mb-0"><tbody><?php if(empty($rules)): ?><tr><td class="text-muted text-center py-4">قاعده‌ای تعریف نشده است.</td></tr><?php endif; ?><?php foreach($rules as $rule): $r=$rule; ?><tr><td><?= e($r['rule_name']??$r['name']??'rule') ?></td><td><?= e($r['severity']??'medium') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
