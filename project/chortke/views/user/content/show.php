<?php
$title = 'جزئیات محتوا';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' content-hub-page');
$activeSpoke = 'overview';
$revenues = $revenues ?? [];
$statusMeta = static function (string $status): array {
    return match ($status) {
        'pending' => ['در انتظار بررسی', 'cnt-badge-pending', 'hourglass_empty'],
        'under_review' => ['در حال بررسی', 'cnt-badge-review', 'rate_review'],
        'approved' => ['تأیید شده', 'cnt-badge-approved', 'check_circle'],
        'published' => ['منتشر شده', 'cnt-badge-published', 'public'],
        'rejected' => ['رد شده', 'cnt-badge-rejected', 'cancel'],
        'suspended' => ['تعلیق شده', 'cnt-badge-suspended', 'block'],
        default => ['نامشخص', 'cnt-badge-pending', 'help'],
    };
};
$platformLabel = static fn($p): string => match ((string)$p) {'aparat'=>'آپارات','youtube'=>'یوتیوب','upload_center'=>'آپلود سنتر', default=>(string)$p};
$meta = $statusMeta((string)($submission->status ?? ''));
ob_start();
?>
<div class="cnt-wrap">
  <section class="cnt-hero">
    <div>
      <div class="cnt-kicker"><span class="material-icons">movie</span> جزئیات محتوا</div>
      <h1><?= e($submission->title ?? 'محتوا') ?></h1>
      <p>وضعیت بررسی، اطلاعات انتشار و تاریخچه درآمد این محتوا را اینجا می‌بینید.</p>
    </div>
    <div class="cnt-actions"><a href="<?= e(url('/content')) ?>" class="cnt-btn cnt-btn-ghost"><span class="material-icons">arrow_forward</span> بازگشت</a></div>
  </section>
  <div class="cnt-hub-layout">
    <?php include view_path('user.content._content-nav'); ?>
    <main class="cnt-hub-main">
      <section class="cnt-card cnt-section p-0">
        <div class="cnt-form-card__head"><h2><span class="material-icons">info</span> اطلاعات محتوا</h2><span class="cnt-badge <?= e($meta[1]) ?>"><i class="material-icons icon-xs"><?= e($meta[2]) ?></i><?= e($meta[0]) ?></span></div>
        <div class="cnt-form-card__body">
          <div class="cnt-detail-grid">
            <div><small>پلتفرم</small><strong><?= e($platformLabel($submission->platform ?? '')) ?></strong></div>
            <div><small>دسته‌بندی</small><strong><?= e($submission->category ?? 'تعیین نشده') ?></strong></div>
            <div><small>تاریخ ارسال</small><strong><?= e(!empty($submission->created_at) ? to_jalali($submission->created_at) : '—') ?></strong></div>
            <?php if (!empty($submission->approved_at)): ?><div><small>تاریخ تأیید</small><strong><?= e(to_jalali($submission->approved_at)) ?></strong></div><?php endif; ?>
            <?php if (!empty($submission->published_at)): ?><div><small>تاریخ انتشار</small><strong><?= e(to_jalali($submission->published_at)) ?></strong></div><?php endif; ?>
            <?php if (!empty($submission->channel_name)): ?><div><small>نام کانال</small><strong><?= e($submission->channel_name) ?></strong></div><?php endif; ?>
          </div>
          <div class="cnt-url-box"><small>لینک ویدیو</small><a href="<?= e($submission->video_url ?? $submission->url ?? '#') ?>" target="_blank" rel="noopener" dir="ltr"><?= e($submission->video_url ?? $submission->url ?? '—') ?></a></div>
          <?php if (!empty($submission->published_url)): ?><div class="cnt-url-box"><small>لینک انتشار</small><a href="<?= e($submission->published_url) ?>" target="_blank" rel="noopener" dir="ltr"><?= e($submission->published_url) ?></a></div><?php endif; ?>
          <?php if (!empty($submission->description)): ?><div class="cnt-desc"><strong>توضیحات</strong><p><?= nl2br(e($submission->description)) ?></p></div><?php endif; ?>
          <?php if (in_array(($submission->status ?? ''), ['rejected', 'suspended'], true) && !empty($submission->rejection_reason)): ?><div class="alert alert-danger mt-3"><strong>دلیل رد/تعلیق:</strong><p><?= nl2br(e($submission->rejection_reason)) ?></p></div><?php endif; ?>
        </div>
      </section>

      <section class="cnt-card cnt-section mt-3">
        <div class="cnt-section-head"><h2><span class="material-icons">payments</span> درآمدهای این محتوا</h2></div>
        <?php if (empty($revenues)): ?>
          <div class="cnt-empty"><span class="material-icons">schedule</span><h2>هنوز درآمدی ثبت نشده</h2><p>درآمد بعد از انتشار و ثبت دوره توسط مدیریت نمایش داده می‌شود.</p></div>
        <?php else: ?>
          <div class="cnt-list">
            <?php foreach ($revenues as $rev): ?>
              <div class="cnt-mini-row"><span>دوره <?= e($rev->period ?? '—') ?></span><strong><?= number_format((float)($rev->net_user_amount ?? $rev->amount ?? 0)) ?> تومان</strong></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercontenthub.css') . '">';
include view_path('layouts.user');
?>
