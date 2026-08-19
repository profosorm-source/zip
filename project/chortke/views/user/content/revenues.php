<?php
$title = 'درآمدهای محتوا';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' content-hub-page');
$activeSpoke = 'revenues';
$revenues = $revenues ?? [];
$totalPaid = $totalPaid ?? 0;
$totalPending = $totalPending ?? 0;
$totalPages = $totalPages ?? 1;
$currentPage = $currentPage ?? 1;
ob_start();
$statusMeta = static fn($s): array => match ((string)$s) {
    'pending' => ['در انتظار تأیید', 'cnt-badge-pending'],
    'approved' => ['آماده پرداخت', 'cnt-badge-review'],
    'paid' => ['پرداخت شده', 'cnt-badge-paid'],
    'cancelled' => ['لغو شده', 'cnt-badge-rejected'],
    default => [(string)$s ?: 'نامشخص', 'cnt-badge-pending'],
};
?>
<div class="cnt-wrap">
  <section class="cnt-hero">
    <div>
      <div class="cnt-kicker"><span class="material-icons">payments</span> درآمد محتوا</div>
      <h1>درآمدهای محتوای من</h1>
      <p>درآمدهای ثبت‌شده، مبالغ در انتظار و پرداخت‌های انجام‌شده برای محتواهای شما.</p>
    </div>
    <div class="cnt-actions"><a href="<?= e(url('/content')) ?>" class="cnt-btn cnt-btn-ghost"><span class="material-icons">arrow_forward</span> بازگشت به محتوا</a></div>
  </section>
  <div class="cnt-hub-layout">
    <?php include view_path('user.content._content-nav'); ?>
    <main class="cnt-hub-main">
      <section class="cnt-stats">
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">check_circle</i></span><div><small>پرداخت‌شده</small><strong><?= number_format((float)$totalPaid) ?></strong></div></article>
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">schedule</i></span><div><small>در انتظار</small><strong><?= number_format((float)$totalPending) ?></strong></div></article>
      </section>
      <?php if (empty($revenues)): ?>
        <section class="cnt-card cnt-empty"><span class="material-icons">payments</span><h2>درآمدی ثبت نشده</h2><p>پس از انتشار و ثبت درآمد توسط مدیریت، پرداخت‌های شما اینجا نمایش داده می‌شود.</p></section>
      <?php else: ?>
        <section class="cnt-list">
          <?php foreach ($revenues as $r): $st = $statusMeta($r->status ?? ''); $sid = (int)($r->submission_id ?? $r->content_id ?? 0); ?>
          <article class="cnt-revenue-card">
            <div class="cnt-content-card__top">
              <span class="cnt-card-icon"><i class="material-icons">payments</i></span>
              <div>
                <h2 class="cnt-card-title"><?= e($r->video_title ?? ('محتوا #' . $sid)) ?></h2>
                <div class="cnt-card-meta">دوره <?= e($r->period ?? '—') ?> · <?= e(!empty($r->created_at) ? to_jalali($r->created_at) : '—') ?></div>
              </div>
              <span class="cnt-badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
            </div>
            <div class="campaign-metrics mt-3">
              <div><small>درآمد کل</small><strong><?= number_format((float)($r->total_revenue ?? $r->gross_amount ?? $r->amount ?? 0)) ?></strong></div>
              <div><small>خالص شما</small><strong><?= number_format((float)($r->net_user_amount ?? $r->amount ?? 0)) ?></strong></div>
              <div><small>بازدید</small><strong><?= fa_number((int)($r->views ?? 0)) ?></strong></div>
            </div>
            <?php if ($sid > 0): ?><div class="cnt-card-foot"><small>شناسه محتوا: #<?= fa_number($sid) ?></small><a class="cnt-btn cnt-btn-ghost" href="<?= e(url('/content/' . $sid)) ?>"><span class="material-icons">visibility</span> جزئیات محتوا</a></div><?php endif; ?>
          </article>
          <?php endforeach; ?>
        </section>
        <?php if ($totalPages > 1): ?><div class="pagination-wrapper"><?php for ($i=1; $i <= $totalPages; $i++): ?><a href="?page=<?= e($i) ?>" class="pagination-btn <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a><?php endfor; ?></div><?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercontenthub.css') . '">';
include view_path('layouts.user');
?>
