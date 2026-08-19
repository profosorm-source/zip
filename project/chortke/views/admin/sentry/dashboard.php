<?php
$title = 'Sentry Dashboard';
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

  
<?php $overview = $overview ?? ($data['overview'] ?? []); $summary = $overview['summary'] ?? []; $today = $summary['today'] ?? []; ?>
<div class="row g-3 mb-4">
  <?php foreach ([['خطاهای امروز',$today['error_events'] ?? $today['error_issues'] ?? 0,'danger'],['Issueها',$today['error_issues'] ?? 0,'warning'],['Failed jobs',$overview['failed_jobs']['total'] ?? 0,'secondary'],['Health',$overview['health_score']['score'] ?? 100,'success']] as $card): ?>
  <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small"><?= e($card[0]) ?></div><div class="h3 text-<?= e($card[2]) ?>"><?= e((string)$card[1]) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card"><div class="card-body"><p class="text-muted mb-0">این داشبورد از سرویس‌های Sentry داخلی داده می‌گیرد و برای نبود داده، خروجی خالی/صفر را نشان می‌دهد.</p></div></div>

</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
