<?php
// ─── User Navbar (Binance Style) ───
// متغیرها: $fullName, $tier, $kycLabel, $notifCount, $topNotifications, $avatarUrl, $title, $openTicketCount
?>
<div class="bn-navbar">

  <!-- Left Side: Page Title -->
  <div class="bn-nav-left">
    <h5 class="mb-0 d-none d-md-block" style="color: var(--bn-text-main); font-size: 14px; font-weight: 700; opacity: 0.8;">
        <?= e($title ?? 'داشبورد') ?>
    </h5>
  </div>

  <!-- Right Side: Actions & User -->
  <div class="bn-nav-right">
    
    <!-- Action Icons -->
    <div class="bn-nav-actions">
      <!-- Search Toggle & Bar — کنار سایر آیکن‌های سمت چپ تا زیر سایدبار نیفتد -->
      <div class="bn-search-container" id="topbarSearch">
        <button id="searchToggle" class="bn-search-toggle" type="button" title="جستجوی سریع">
          <span class="material-icons">search</span>
        </button>
        <div id="searchBar" class="bn-search-bar">
          <input type="text" class="bn-search-input" id="tsInput" placeholder="جستجوی سریع..." autocomplete="off" />
          <div class="ts-results" id="tsResults"></div>
        </div>
      </div>

      <!-- Support Hub / Tickets -->
      <a href="<?= url('/tickets') ?>" class="bn-nav-icon" title="مرکز پشتیبانی">
        <span class="material-icons">support_agent</span>
        <?php if ((int)($openTicketCount ?? 0) > 0): ?>
          <span class="bn-nav-badge"></span>
        <?php endif; ?>
      </a>

      <!-- Notifications -->
      <div class="bn-nav-icon" data-dd-toggle="notif" title="اعلان‌ها" style="cursor: pointer; position: relative;">
        <span class="material-icons">notifications</span>
        <?php if ((int)($notifCount ?? 0) > 0): ?>
          <span class="bn-nav-badge" style="background:#f6465d !important; color:#fff !important; font-size:10px; font-weight:800; border-radius:10px; padding:1px 5px; position:absolute; top:2px; right:2px; min-width:16px; height:16px; display:flex; align-items:center; justify-content:center; border:1px solid #181a20; line-height:1; z-index:10;"><?= (int)$notifCount > 99 ? '99+' : (int)$notifCount ?></span>
        <?php endif; ?>
      </div>

      <!-- Settings / Account Hub: مستقیم وارد مرکز حساب و تنظیمات می‌شود -->
      <a href="<?= url('/profile') ?>" class="bn-nav-icon" title="مرکز حساب و تنظیمات">
        <span class="material-icons">settings</span>
      </a>

      <!-- Logout -->
      <div class="bn-nav-icon" data-submit-form="lf" title="خروج" role="button" style="cursor: pointer;">
        <span class="material-icons">logout</span>
      </div>
      <form id="lf" method="POST" action="<?= url('/logout') ?>" class="d-none"><?= csrf_field() ?></form>
    </div>

    <!-- User Profile -->
    <div class="bn-user-profile" id="userProfileDropdown" style="cursor: pointer;">
      <div class="bn-user-info">
        <p class="bn-user-name"><?= e($fullName) ?></p>
        
        <div class="bn-user-status">
            <span class="bn-kyc-badge"><?= e($kycLabel ?? 'در انتظار') ?></span>
            <span class="bn-xp-label" style="font-size: 9px; color: var(--bn-text-muted); margin-right: 4px;">
                <?= e(strtoupper((string)($tier ?? 'SILVER'))) ?>
            </span>
        </div>

        <!-- XP Progress Bar (Dynamic) -->
        <div class="bn-xp-container">
            <div class="bn-xp-bar-bg">
                <?php 
                $xpPercent = $userXpPercent ?? 0; 
                if ($xpPercent === 0 && isset($tier)) {
                    $tierMap = ['BRONZE' => 25, 'SILVER' => 50, 'GOLD' => 75, 'VIP' => 90];
                    $xpPercent = $tierMap[strtoupper($tier)] ?? 0;
                }
                ?>
                <div class="bn-xp-bar-fill" style="width: <?= (int)$xpPercent ?>%;"></div>
            </div>
            <span class="bn-xp-percent"><?= (int)$xpPercent ?>٪</span>
        </div>
      </div>
      <img src="<?= e($avatarUrl ?? asset('uploads/avatars/default-avatar.png')) ?>" class="bn-user-avatar" alt="avatar">
    </div>
  </div>

  <!-- Dropdowns -->
  <!-- Notification Dropdown -->
  <div class="bn-dropdown" data-dd-menu="notif" style="right: 80px;">
    <div class="bn-dropdown-header">
      <span>اعلان‌ها</span>
      <a href="<?= url('/notifications') ?>" class="text-yellow-400" style="font-size: 11px; color: var(--bn-accent); text-decoration: none;">مشاهده همه</a>
    </div>
    <div class="bn-dropdown-content">
        <?php if (empty($topNotifications)): ?>
          <div class="p-3 text-center text-muted" style="font-size: 12px; color: var(--bn-text-muted);">اعلان جدیدی ندارید.</div>
        <?php else: ?>
          <?php foreach ($topNotifications as $n): ?>
            <a href="<?= url('/notifications') ?>" class="bn-dropdown-item">
              <span class="material-icons">notifications</span>
              <div style="display: flex; flex-direction: column;">
                <span style="font-weight: 700; font-size: 12px; color: var(--bn-text-main);"><?= e($n->title ?? 'اعلان') ?></span>
                <span style="font-size: 11px; color: var(--bn-text-muted);"><?= e($n->message ?? '') ?></span>
                <span style="font-size: 9px; color: var(--bn-text-muted); margin-top: 2px;"><?= e($n->created_at ? jdate($n->created_at) : '') ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
    </div>
  </div>

  <!-- Settings icon is a direct link to Account Hub; no dropdown here. -->

</div>

<script nonce="<?= e($cspNonce) ?>">
    document.addEventListener('DOMContentLoaded', function() {
        // --- Search Toggle Logic ---
        const searchToggle = document.getElementById('searchToggle');
        const searchBar = document.getElementById('searchBar');
        if (searchToggle && searchBar) {
            searchToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                searchBar.classList.toggle('open');
                if (searchBar.classList.contains('open')) {
                    document.getElementById('tsInput').focus();
                }
            });
        }

        // --- Dropdown Logic ---
        let notifHoverTimer = null;
        const notifToggle = document.querySelector('[data-dd-toggle="notif"]');
        const notifMenu = document.querySelector('[data-dd-menu="notif"]');
        if (notifToggle && notifMenu) {
            const showNotifMenu = () => {
                clearTimeout(notifHoverTimer);
                document.querySelectorAll('.bn-dropdown').forEach(d => { if (d !== notifMenu) d.classList.remove('show'); });
                notifMenu.classList.add('show');
            };
            const hideNotifMenu = () => {
                notifHoverTimer = setTimeout(() => { notifMenu.classList.remove('show'); }, 350);
            };
            notifToggle.addEventListener('mouseenter', showNotifMenu);
            notifToggle.addEventListener('mouseleave', hideNotifMenu);
            notifMenu.addEventListener('mouseenter', () => clearTimeout(notifHoverTimer));
            notifMenu.addEventListener('mouseleave', hideNotifMenu);
        }

        document.querySelectorAll('[data-dd-toggle]').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const menuId = toggle.getAttribute('data-dd-toggle');
                const menu = document.querySelector(`[data-dd-menu="${menuId}"]`);
                
                document.querySelectorAll('.bn-dropdown').forEach(d => {
                    if (d !== menu) d.classList.remove('show');
                });

                if (menu) menu.classList.toggle('show');
            });
        });

        document.addEventListener('click', () => {
            document.querySelectorAll('.bn-dropdown').forEach(d => d.classList.remove('show'));
        });
    });
</script>
