<?php
// فقط برای کاربر لاگین‌شده
if (!auth()) return;
$count = (int)($notifCount ?? 0);
if ($count <= 0 && function_exists('user_sidebar_badges')) {
    $badges = user_sidebar_badges((int)auth());
    $count = (int)($badges['unread_notifications'] ?? 0);
}
?>
<div class="topbar-icon" id="notifBell">
    <a href="<?= url('/notifications') ?>" class="btn btn-light position-relative">
        <i class="fas fa-bell"></i>
        <span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-danger <?= $count > 0 ? '' : 'd-none' ?>" id="notifBadge" style="background:#f6465d !important; color:#fff !important; font-size:10px; font-weight:800; border-radius:10px; padding:2px 6px;">
            <?= $count > 99 ? '99+' : $count ?>
        </span>
    </a>
</div>