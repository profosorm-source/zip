<?php // ─── Site Footer ─── ?>

<!-- موج بالای فوتر -->
<div class="footer-wave">
  <svg viewBox="0 0 1440 80" preserveAspectRatio="none">
    <path d="M0,40 C200,80 400,0 600,40 C800,80 1000,0 1200,40 C1350,65 1400,50 1440,40 L1440,80 L0,80 Z"
          fill="#1A1A2E"/>
  </svg>
</div>

<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-grid">

      <!-- ستون ۱: لوگو + اطلاعات -->
      <div class="footer-brand">
        <div class="footer-logo-box">
          <?php $__footerLogo = site_logo('footer') ?? site_logo('main'); ?>
          <?php if ($__footerLogo): ?>
            <img src="<?= e($__footerLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>">
          <?php else: ?>
            <span class="material-icons footer-logo-icon">account_balance</span>
          <?php endif; ?>
        </div>
        <div>
          <div class="footer-brand-title"><?= e(strtoupper(setting('site_name','CHORTKE'))) ?></div>
          <div class="footer-brand-subtitle"><?= e(setting('site_name','چرتکه')) ?></div>
          <div class="footer-brand-meta">
            <?php $__addr = setting('site_address'); if ($__addr): ?>
              <?= e($__addr) ?><br>
            <?php endif; ?>
            <?php $__phone = setting('contact_phone') ?: setting('phone_support'); if ($__phone): ?>
              <span class="material-icons" class="icon-sm">call</span>
              <?= e($__phone) ?><br>
            <?php endif; ?>
            <?php $__email = setting('contact_email'); if ($__email): ?>
              <span class="material-icons" class="icon-sm">email</span>
              <?= e($__email) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ستون ۲: لینک‌ها -->
      <div>
        <div class="footer-link-title">لینک‌های سریع</div>
        <ul class="footer-link-list">
          <li><a href="<?= url('/') ?>">خانه</a></li>
          <li><a href="<?= url('/terms') ?>">قوانین و مقررات</a></li>
          <li><a href="<?= url('/privacy') ?>">حریم خصوصی</a></li>
          <li><a href="<?= url('/contact') ?>">تماس با ما</a></li>
        </ul>
      </div>

      <!-- ستون ۳: Connect + آیکون‌ها -->
      <div>
        <div class="footer-link-title">ما را دنبال کنید</div>
        <div class="footer-socials">
          <?php $__tg = setting('telegram_support'); if ($__tg): ?>
          <a href="https://t.me/<?= e($__tg) ?>" target="_blank" class="footer-social-btn">
            <span class="material-icons">send</span>
          </a>
          <?php endif; ?>
          <?php $__ig = setting('instagram_support'); if ($__ig): ?>
          <a href="https://instagram.com/<?= e($__ig) ?>" target="_blank" class="footer-social-btn">
            <span class="material-icons">camera_alt</span>
          </a>
          <?php endif; ?>
          <?php $__tw = setting('twitter_support'); if ($__tw): ?>
          <a href="https://twitter.com/<?= e($__tw) ?>" target="_blank" class="footer-social-btn">
            <span class="material-icons">tag</span>
          </a>
          <?php endif; ?>
          <?php $__site = setting('site_url') ?: url('/'); ?>
          <a href="<?= e($__site) ?>" class="footer-social-btn">
            <span class="material-icons">language</span>
          </a>
        </div>
      </div>

    </div>
  </div>

  <!-- خط پایین -->
  <div class="footer-bottom">
    <span class="footer-copy">© <?= to_jalali(date('Y-m-d'), 'Y') ?> <?= e(setting('site_name','چرتکه')) ?> — تمامی حقوق محفوظ است</span>
    <?php $__phone = setting('contact_phone') ?: setting('phone_support'); if ($__phone): ?>
    <span class="footer-phone">
      <span class="material-icons footer-phone-icon">call</span>
      <?= e($__phone) ?>
    </span>
    <?php endif; ?>
  </div>
</footer>
