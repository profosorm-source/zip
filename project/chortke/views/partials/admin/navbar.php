<?php
// ═══════════════════════════════════════════════════════════════
// Admin Top Navbar (Header)
// ═══════════════════════════════════════════════════════════════
// - شامل: جستجو، اعلان‌ها، تیکت، لاگ، تم، ساعت، اطلاعات کاربر
// - تمام متغیرها از Layout اصلی (admin.php) دریافت می‌شوند

$fullName = $fullName ?? 'مدیر';
$userRole = $userRole ?? 'مدیر کل';
?>
<!-- ═══════════════════════════════════════════════════════════════
     ADMIN TOP NAVIGATION BAR
     ═══════════════════════════════════════════════════════════════ -->
<header class="topbar" id="adminTopbar" role="banner">

    <!-- Mobile Hamburger Menu -->
    <button 
        class="topbar-hamburger" 
        id="adminSidebarToggle"
        aria-label="باز کردن منو"
        title="منو">
        <span class="material-icons" aria-hidden="true">menu</span>
    </button>

    <!-- Breadcrumb / Page Title -->
    <div class="topbar-breadcrumb">
        <span><?= e(setting('site_name', 'چورتکه')) ?></span>
        <span class="material-icons sep" aria-hidden="true">chevron_left</span>
        <span class="current"><?= e($title ?? 'پنل مدیریت') ?></span>
    </div>

    <!-- Actions -->
    <div class="topbar-actions">

        <!-- Global Quick Search -->
        <div class="topbar-search-wrap" id="adminSearchWrap">
            <span class="material-icons topbar-search-icon" aria-hidden="true">search</span>
            <input 
                class="topbar-search" 
                type="text" 
                id="adminSearchInput"
                placeholder="جستجوی سریع..." 
                autocomplete="off"
                aria-label="جستجوی سریع در پنل مدیریت">
            <div id="adminSearchResults" class="d-none" role="listbox"></div>
        </div>

        <!-- Notifications Bell -->
        <div class="notif-bell-wrap" id="notifBellWrap">
            <button 
                class="topbar-btn" 
                id="notifBellBtn" 
                title="اعلان‌ها" 
                aria-label="اعلان‌ها">
                <span class="material-icons" aria-hidden="true">notifications</span>
                <span class="notif-badge d-none" id="notifBadge">0</span>
            </button>

            <!-- Dropdown -->
            <div class="notif-dropdown d-none" id="notifDropdown" role="dialog" aria-label="لیست اعلان‌ها">
                <div class="notif-dropdown-header">
                    <span>اعلان‌ها</span>
                    <button 
                        class="notif-mark-all-btn" 
                        id="notifMarkAllBtn" 
                        title="همه را خوانده کن"
                        aria-label="علامت‌گذاری همه به عنوان خوانده شده">
                        <span class="material-icons icon-sm" aria-hidden="true">done_all</span>
                    </button>
                </div>
                <div class="notif-dropdown-list" id="notifDropdownList" role="list">
                    <div class="notif-empty">در حال بارگذاری...</div>
                </div>
                <a href="<?= url('/admin/notifications') ?>" class="notif-dropdown-footer">
                    مشاهده همه اعلان‌ها
                    <span class="material-icons icon-sm" aria-hidden="true">chevron_left</span>
                </a>
            </div>
        </div>

        <!-- Tickets -->
        <a class="topbar-btn" href="<?= url('/admin/tickets') ?>" title="تیکت‌های پشتیبانی" aria-label="تیکت‌ها">
            <span class="material-icons" aria-hidden="true">support_agent</span>
        </a>

        <!-- System Logs -->
        <a class="topbar-btn" href="<?= url('/admin/logs') ?>" title="لاگ‌های سیستم" aria-label="لاگ‌ها">
            <span class="material-icons" aria-hidden="true">terminal</span>
        </a>

        <!-- Theme Toggle (Dark/Light) -->
        <button 
            class="topbar-btn theme-toggle-btn" 
            id="themeToggleBtn" 
            title="تغییر تم"
            aria-label="تغییر تم">
            <span class="material-icons" id="themeIcon" aria-hidden="true">light_mode</span>
        </button>

        <!-- Live Clock -->
        <div class="topbar-time" id="adminClock" aria-label="ساعت فعلی">--:--</div>

        <!-- User Profile -->
        <div class="user-info" aria-label="اطلاعات کاربر">
            <div class="user-details">
                <p class="user-name"><?= e($fullName) ?></p>
                <p class="user-role"><?= e($userRole) ?></p>
            </div>
            <div class="user-avatar" aria-hidden="true"><?= strtoupper($firstLetter) ?></div>
        </div>

    </div>
</header>
