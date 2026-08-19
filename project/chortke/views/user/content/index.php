<?php
$title = 'کسب درآمد از محتوا';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' content-hub-page');
$activeSpoke = 'overview';
ob_start();
$submissions = $submissions ?? [];
$stats = $stats ?? [];
$statusMeta = static function (string $status): array {
    return match ($status) {
        'pending' => ['در انتظار', 'cnt-badge-pending', 'hourglass_empty'],
        'under_review' => ['در حال بررسی', 'cnt-badge-review', 'rate_review'],
        'approved' => ['تأیید شده', 'cnt-badge-approved', 'check_circle'],
        'published' => ['منتشر شده', 'cnt-badge-published', 'public'],
        'rejected' => ['رد شده', 'cnt-badge-rejected', 'cancel'],
        'suspended' => ['تعلیق', 'cnt-badge-suspended', 'block'],
        default => [$status ?: 'نامشخص', 'cnt-badge-pending', 'help'],
    };
};
$platformLabel = static fn($p): string => match ((string)$p) {
    'aparat' => 'آپارات',
    'youtube' => 'یوتیوب',
    'upload_center' => 'آپلود سنتر',
    default => (string)$p,
};
?>
<div class="cnt-wrap">
  <section class="cnt-hero">
    <div>
      <div class="cnt-kicker"><span class="material-icons">movie_creation</span> کسب درآمد از محتوا</div>
      <h1>مرکز محتوای من</h1>
      <p>ویدیوهای خود را ثبت کنید؛ پس از تأیید و انتشار، درآمد دوره‌ای شما محاسبه و پرداخت می‌شود.</p>
    </div>
    <div class="cnt-actions">
      <a href="<?= e(url('/content/create')) ?>" class="cnt-btn cnt-btn-primary"><span class="material-icons">upload_file</span> ارسال محتوای جدید</a>
      <a href="<?= e(url('/content/revenues')) ?>" class="cnt-btn cnt-btn-ghost"><span class="material-icons">payments</span> درآمدهای من</a>
    </div>
  </section>

  <div class="cnt-hub-layout">
    <?php include view_path('user.content._content-nav'); ?>
    <main class="cnt-hub-main">
      <section class="cnt-stats">
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">video_library</i></span><div><small>کل محتواها</small><strong><?= fa_number((int)($stats['total'] ?? 0)) ?></strong></div></article>
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">hourglass_empty</i></span><div><small>در انتظار</small><strong><?= fa_number((int)($stats['pending'] ?? 0)) ?></strong></div></article>
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">public</i></span><div><small>منتشر شده</small><strong><?= fa_number((int)($stats['published'] ?? 0)) ?></strong></div></article>
        <article class="cnt-stat-card"><span class="cnt-stat-icon"><i class="material-icons">payments</i></span><div><small>درآمد دریافتی</small><strong><?= number_format((float)($totalRevenue ?? 0)) ?></strong></div></article>
      </section>

      <?php if (empty($submissions)): ?>
        <section class="cnt-card cnt-empty">
          <span class="material-icons">ondemand_video</span>
          <h2>هنوز محتوایی ثبت نکرده‌اید</h2>
          <p>اولین ویدیوی خود را ثبت کنید تا پس از بررسی مدیریت وارد مسیر درآمدزایی شود.</p>
          <a href="<?= e(url('/content/create')) ?>" class="cnt-btn cnt-btn-primary"><span class="material-icons">upload_file</span> ارسال محتوا</a>
        </section>
      <?php else: ?>
        <section class="cnt-list">
          <?php foreach ($submissions as $item): $meta = $statusMeta((string)($item->status ?? '')); ?>
            <article class="cnt-content-card">
              <div class="cnt-content-card__top">
                <span class="cnt-card-icon"><i class="material-icons">play_circle</i></span>
                <div>
                  <h2 class="cnt-card-title"><?= e($item->title ?? 'بدون عنوان') ?></h2>
                  <div class="cnt-card-meta"><?= e($platformLabel($item->platform ?? '')) ?> · <?= e(!empty($item->created_at) ? to_jalali($item->created_at) : '—') ?></div>
                </div>
                <span class="cnt-badge <?= e($meta[1]) ?>"><i class="material-icons icon-xs"><?= e($meta[2]) ?></i><?= e($meta[0]) ?></span>
              </div>
              <div class="cnt-card-foot">
                <small><?= e(mb_substr((string)($item->description ?? ''), 0, 120)) ?></small>
                <a href="<?= e(url('/content/' . (int)$item->id)) ?>" class="cnt-btn cnt-btn-ghost"><span class="material-icons">visibility</span> جزئیات</a>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercontenthub.css') . '">';
include view_path('layouts.user');
?>
