<?php
$title = 'اعلان‌ها';
$hideSidebar = true;
$notifications = $notifications ?? [];
$unread_count = (int)($unread_count ?? 0);
$total_count = (int)($total_count ?? count($notifications));
$current_page = (int)($current_page ?? 1);
$total_pages = (int)($total_pages ?? 1);

$typeIcon = [
    'system'=>'settings','task'=>'assignment','deposit'=>'south_west','withdrawal'=>'north_east','investment'=>'trending_up','lottery'=>'redeem','referral'=>'group_add','kyc'=>'badge','security'=>'shield','info'=>'notifications','marketing'=>'campaign'
];
$priorityClass = ['urgent'=>'sup-badge--danger','high'=>'sup-badge--warning','normal'=>'sup-badge--info','low'=>'sup-badge--muted'];

ob_start();
?>

<div id="supportNotificationsRoot"
     class="sup-wrap"
     data-csrf="<?= e(csrf_token()) ?>"
     data-mark-read-url="<?= e(url('/notifications/mark-read')) ?>"
     data-mark-all-url="<?= e(url('/notifications/mark-all-read')) ?>"
     data-archive-url="<?= e(url('/notifications/archive')) ?>"
     data-delete-url="<?= e(url('/notifications/delete')) ?>">

    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">notifications</i></div>
            <div>
                <div class="sup-hero__eyebrow">Notification Center</div>
                <h1 class="sup-hero__title">مرکز اعلان‌ها</h1>
                <p class="sup-hero__sub">اعلان‌ها مستقل از پشتیبانی و همیشه از آیکن زنگوله در Navbar در دسترس هستند.</p>
            </div>
        </div>
        <div class="sup-hero__side">
            <a href="<?= url('/dashboard') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">dashboard</i> بازگشت به پنل کاربری</a>
            <button type="button" class="sup-btn sup-btn-primary" id="markAllReadBtn"><i class="material-icons">done_all</i> خواندن همه</button>
        </div>
    </section>

    <main class="sup-hub-main">
        <section class="sup-stats">
            <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">notifications</i></div><div><span class="sup-stat__lbl">کل اعلان‌ها</span><span class="sup-stat__val sup-num"><?= number_format($total_count) ?></span><span class="sup-stat__unit">ثبت‌شده</span></div></div>
            <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">mark_email_unread</i></div><div><span class="sup-stat__lbl">خوانده‌نشده</span><span class="sup-stat__val sup-num" id="unreadCountBadge"><?= number_format($unread_count) ?></span><span class="sup-stat__unit">نیازمند بررسی</span></div></div>
            <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">visibility</i></div><div><span class="sup-stat__lbl">نمایش سریع</span><span class="sup-stat__val">Navbar</span><span class="sup-stat__unit">آیکن زنگوله</span></div></div>
            <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">done_all</i></div><div><span class="sup-stat__lbl">اقدام سریع</span><span class="sup-stat__val">خواندن همه</span><span class="sup-stat__unit">با یک کلیک</span></div></div>
        </section>

        <div class="sup-filter">
            <button type="button" class="sup-btn sup-btn-primary filter-btn" data-filter="all">همه</button>
            <button type="button" class="sup-btn sup-btn-secondary filter-btn" data-filter="unread">نخوانده</button>
        </div>

        <section class="sup-section">
            <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">notifications</i> لیست اعلان‌ها</div><span class="sup-badge sup-badge--info"><?= number_format(count($notifications)) ?> مورد این صفحه</span></div>
            <div class="sup-section__body">
                <div id="notificationsList" class="sup-notif-list">
                    <?php if (empty($notifications)): ?>
                        <div class="sup-empty" id="emptyState"><i class="material-icons">notifications_off</i><h3>اعلانی وجود ندارد</h3><p>اعلان‌های جدید اینجا نمایش داده می‌شود.</p></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                            $icon = $typeIcon[$notif->type ?? 'system'] ?? 'notifications';
                            $unread = empty($notif->is_read);
                            $pClass = $priorityClass[$notif->priority ?? 'normal'] ?? 'sup-badge--info';
                            ?>
                            <article class="sup-notif-item <?= $unread ? 'unread' : 'read' ?>" data-id="<?= (int)$notif->id ?>" data-type="<?= e($notif->type ?? 'system') ?>">
                                <div class="sup-notif-icon"><i class="material-icons"><?= e($icon) ?></i></div>
                                <div>
                                    <div class="sup-notif-title"><?= e($notif->title ?? 'اعلان') ?> <span class="sup-badge <?= e($pClass) ?>"><?= e($notif->priority ?? 'normal') ?></span></div>
                                    <p class="sup-notif-msg"><?= e($notif->message ?? '') ?></p>
                                    <?php if (!empty($notif->action_url)): ?><a href="<?= url('/notifications/click?notification_id=' . (int)$notif->id) ?>" class="sup-badge sup-badge--warning"><?= e($notif->action_text ?? 'مشاهده') ?></a><?php endif; ?>
                                    <div style="margin-top:7px;color:var(--sup-text-faint);font-size:11px;"><?= to_jalali($notif->created_at ?? '') ?></div>
                                </div>
                                <div class="sup-notif-actions">
                                    <?php if ($unread): ?><button type="button" class="sup-icon-btn mark-read-btn" data-id="<?= (int)$notif->id ?>" title="خوانده شد"><i class="material-icons">done</i></button><?php endif; ?>
                                    <button type="button" class="sup-icon-btn archive-btn" data-id="<?= (int)$notif->id ?>" title="آرشیو"><i class="material-icons">archive</i></button>
                                    <button type="button" class="sup-icon-btn delete-btn" data-id="<?= (int)$notif->id ?>" title="حذف"><i class="material-icons">delete</i></button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="sup-actions" style="padding:16px;justify-content:center;">
                    <?php for ($p=1; $p <= $total_pages; $p++): ?><a class="sup-btn <?= $p === $current_page ? 'sup-btn-primary' : 'sup-btn-secondary' ?>" href="<?= url('/notifications?page=' . $p) ?>"><?= e((string)$p) ?></a><?php endfor; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usernotificationsindex.js') . '"></script>';
include view_path('layouts.user');
?>
