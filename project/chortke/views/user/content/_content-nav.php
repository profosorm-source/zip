<?php
$activeSpoke = $activeSpoke ?? 'overview';
$settings = $settings ?? [];
$siteShare = (float)($settings['site_share_percent'] ?? setting('content_site_share_percent', 40));
$minMonths = (int)($settings['min_months'] ?? setting('content_min_months_for_revenue', 2));
$spokes = [
    ['key' => 'overview', 'href' => url('/content'), 'icon' => 'video_library', 'label' => 'محتواهای من', 'desc' => 'وضعیت ویدیوها و مسیر درآمد'],
    ['key' => 'submit', 'href' => url('/content/create'), 'icon' => 'upload_file', 'label' => 'ارسال محتوا', 'desc' => 'ثبت لینک ویدیو برای بررسی'],
    ['key' => 'revenues', 'href' => url('/content/revenues'), 'icon' => 'payments', 'label' => 'درآمدها', 'desc' => 'پرداخت‌ها و مبالغ قابل دریافت'],
];
?>
<aside class="cnt-hub-sidebar" aria-label="ناوبری ماژول محتوا">
  <div class="cnt-module-card">
    <div class="cnt-module-card__top">
      <span class="cnt-module-card__icon"><i class="material-icons">movie_creation</i></span>
      <div>
        <div class="cnt-module-card__eyebrow">CONTENT EARNING</div>
        <h2 class="cnt-module-card__title">کسب درآمد از محتوا</h2>
        <p class="cnt-module-card__desc">ویدیوی خود را معرفی کنید؛ پس از تأیید و انتشار، درآمد دوره‌ای محاسبه می‌شود.</p>
      </div>
    </div>
    <nav class="cnt-module-nav">
      <?php foreach ($spokes as $spoke): ?>
        <a class="cnt-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
          <span class="cnt-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
          <span class="cnt-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
          <i class="material-icons cnt-module-nav__chev">chevron_left</i>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="cnt-mini-card">
    <div class="cnt-mini-card__head"><i class="material-icons">info</i> قوانین درآمد</div>
    <div class="cnt-mini-row"><span>شروع محاسبه درآمد</span><strong>از ماه <?= fa_number($minMonths + 1) ?></strong></div>
    <div class="cnt-mini-row"><span>سهم پایه سایت</span><strong><?= fa_number($siteShare) ?>٪</strong></div>
    <div class="cnt-mini-row"><span>نیازمندی اصلی</span><strong>تأیید و انتشار</strong></div>
  </div>
</aside>
