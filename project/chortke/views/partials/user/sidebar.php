<?php
// ─── User Sidebar — Unified Architecture v2.0 ───
// Compatible with: Unified Ad System (ads table), Separate Executors, Independent Modules

$uri       = $_SERVER['REQUEST_URI'] ?? '/';
$active    = fn(string $p) => str_contains($uri, $p);
$exact     = fn(string $p) => rtrim(strtok($uri,'?'),'/') === rtrim($p,'/');
$anyActive = fn(array $ps) => array_reduce($ps, fn($c,$p) => $c || str_contains($uri,$p), false);

// BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06: badge counters are resolved once per
// request in helpers/view_helper.php::view() (via user_sidebar_badges()) and
// exposed here as $userSidebarBadges — this view no longer touches the DB.
$__userBadges = $userSidebarBadges ?? ['disputes_open' => 0, 'influencer_orders_pending' => 0];
$__disputeBadge = (int)($__userBadges['disputes_open'] ?? 0);
$__influencerBadge = (int)($__userBadges['influencer_orders_pending'] ?? 0);
?>
<div class="sidebar" id="mainSidebar">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <a href="<?= url('/dashboard') ?>" class="sb-logo">
    <?php $__logo = site_logo('main'); ?>
    <?php if ($__logo): ?>
      <img src="<?= e($__logo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" class="sb-logo-img">
    <?php else: ?>
      <div class="sb-logo-icon"><span class="material-icons">account_balance</span></div>
      <span class="sb-logo-text"><?= e(setting('site_name','چرتکه')) ?></span>
    <?php endif; ?>
  </a>

  <ul class="sidebar-menu">
    <li>
      <a href="<?= url('/dashboard') ?>" class="<?= $active('/dashboard')?'active':'' ?>">
        <span class="material-icons">dashboard</span><span class="menu-title">داشبورد</span>
      </a>
    </li>

    <!-- ═══ کسب درآمد (انجام‌دهنده) — Hub & Spoke ═══ -->
    <li class="menu-section-title text-info"><i class="material-icons small align-middle">engineering</i> کسب درآمد</li>

    <?php $earnActive = $anyActive(['/tasks','/custom-tasks','/seo','/social-tasks']); ?>
    <li>
      <a href="<?= url('/tasks') ?>" class="<?= $earnActive?'active':'' ?>">
        <span class="material-icons">monetization_on</span><span class="menu-title">بازار تسک‌ها</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/adtube') ?>" class="<?= ($active('/adtube') && !$active('/adtube/ads'))?'active':'' ?>">
        <span class="material-icons">play_circle</span><span class="menu-title">AdTube</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/content') ?>" class="<?= $active('/content')?'active':'' ?>">
        <span class="material-icons">video_library</span><span class="menu-title">درآمد از محتوا</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/influencer') ?>" class="<?= $active('/influencer')?'active':'' ?>">
        <span class="material-icons">stars</span><span class="menu-title">اینفلوئنسر</span>
        <?php if(($__influencerBadge ?? 0) > 0): ?>
           <span class="badge bg-danger rounded-pill ms-auto me-2 sidebar-badge"><?= $__influencerBadge ?></span>
        <?php endif; ?>
      </a>
    </li>

    <!-- ═══ تبلیغات من (تبلیغ‌دهنده) — Unified ═══ -->
    <li class="menu-section-title text-warning"><i class="material-icons small align-middle">campaign</i> تبلیغات و کمپین‌ها</li>

    <!-- Unified Ads Hub: one entry; management/create switch happens inside /ads without reload -->
    <li>
      <a href="<?= url('/ads') ?>" class="<?= $active('/ads')?'active':'' ?>">
        <span class="material-icons">campaign</span><span class="menu-title">تبلیغات</span>
      </a>
    </li>

    <!-- Vitrine — Hub & Spoke: فقط یک ورودی در سایدبار -->
    <li>
      <a href="<?= url('/vitrine') ?>" class="<?= $active('/vitrine') ? 'active' : '' ?>">
        <span class="material-icons">storefront</span><span class="menu-title">ویترین (بازار دیجیتال)</span>
      </a>
    </li>

    <!-- ═══ ماژول‌های مستقل ═══ -->
    <li class="menu-section-title">ماژول‌های مستقل</li>

    <!-- Investment — Hub & Spoke: فقط یک ورودی در سایدبار، جزئیات داخل Module Hub -->
    <li>
      <a href="<?= url('/investment') ?>" class="<?= $active('/investment')?'active':'' ?>">
        <span class="material-icons">trending_up</span><span class="menu-title">سرمایه‌گذاری</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/lottery') ?>" class="<?= $active('/lottery')?'active':'' ?>">
        <span class="material-icons">card_giftcard</span><span class="menu-title">قرعه‌کشی</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/prediction') ?>" class="<?= $active('/prediction')?'active':'' ?>">
        <span class="material-icons">sports_soccer</span><span class="menu-title">پیش‌بینی بازی‌ها</span>
      </a>
    </li>

    <!-- Referral — Hub & Spoke: فقط یک ورودی در سایدبار -->
    <li>
      <a href="<?= url('/referral') ?>" class="<?= $active('/referral') ? 'active' : '' ?>">
        <span class="material-icons">group_add</span><span class="menu-title">دعوت از دوستان</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/level') ?>" class="<?= $active('/level')?'active':'' ?>">
        <span class="material-icons">workspace_premium</span><span class="menu-title">سطح کاربری</span>
      </a>
    </li>

    <!-- ═══ مالی ═══ -->
    <li class="menu-section-title">مالی</li>

    <!-- Finance — Hub & Spoke: فقط یک ورودی در سایدبار، جزئیات داخل مرکز مالی -->
    <li>
      <a href="<?= url('/wallet') ?>" class="<?= $anyActive(['/wallet','/withdrawal','/withdrawals','/manual-deposit','/manual-deposits','/crypto-deposit','/crypto-deposits','/bank-cards'])?'active':'' ?>">
        <span class="material-icons">account_balance_wallet</span><span class="menu-title">کیف پول و مالی</span>
      </a>
    </li>

    <!-- ═══ حساب کاربری ═══ -->
    <li class="menu-section-title">حساب کاربری</li>

    <!-- Account — Hub & Spoke: فقط یک ورودی در سایدبار، جزئیات داخل مرکز حساب -->
    <li>
      <a href="<?= url('/profile') ?>" class="<?= $anyActive(['/profile','/kyc','/sessions','/api-tokens','/settings/security','/settings/notifications'])?'active':'' ?>">
        <span class="material-icons">manage_accounts</span><span class="menu-title">حساب کاربری</span>
      </a>
    </li>

    <li>
      <a href="<?= url('/disputes') ?>" class="<?= $active('/disputes')?'active':'' ?>">
        <span class="material-icons">gavel</span>
        <span class="menu-title">اختلافات و شکایات</span>
        <?php if($__disputeBadge > 0): ?>
           <span class="badge bg-danger rounded-pill ms-auto sidebar-badge-md"><?= $__disputeBadge ?></span>
        <?php endif; ?>
      </a>
    </li>

    <!-- پشتیبانی طبق Hub & Spoke از آیکن تیکت در Navbar قابل دسترسی است -->
    <li class="menu-section-title"></li>
    <li>
      <a href="#" data-submit-form="sidebarLogoutForm" class="text-danger">
        <span class="material-icons">logout</span><span class="menu-title">خروج</span>
      </a>
    </li>

    <form method="POST" action="<?= url('/logout') ?>" id="sidebarLogoutForm" class="d-none">
      <?= csrf_field() ?>
    </form>
  </ul>
</div>
