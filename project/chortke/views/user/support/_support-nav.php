<?php
$activeSpoke = $activeSpoke ?? 'tickets';
$openTickets = (int)($openTicketCount ?? 0);

$spokes = [
    ['key'=>'tickets', 'href'=>url('/tickets'), 'icon'=>'support_agent', 'label'=>'مرکز پشتیبانی', 'desc'=>'تیکت‌ها، پیام‌ها و پیگیری‌ها'],
    ['key'=>'create', 'href'=>url('/tickets/create'), 'icon'=>'add_comment', 'label'=>'تیکت جدید', 'desc'=>'ثبت درخواست پشتیبانی'],
    ['key'=>'bug', 'href'=>url('/bug-reports'), 'icon'=>'bug_report', 'label'=>'گزارش مشکل', 'desc'=>'ثبت و پیگیری خطاهای فنی'],
    ['key'=>'search', 'href'=>url('/search'), 'icon'=>'search', 'label'=>'جستجو', 'desc'=>'جستجوی سریع در داده‌ها'],
];
?>
<aside class="sup-hub-sidebar" aria-label="منوی داخلی پشتیبانی">
    <div class="sup-side-card sup-module-card">
        <div class="sup-module-card__top">
            <div class="sup-module-card__icon"><i class="material-icons">hub</i></div>
            <div>
                <div class="sup-module-card__eyebrow">Support Hub</div>
                <h2 class="sup-module-card__title">پشتیبانی</h2>
                <p class="sup-module-card__desc">تیکت، گزارش مشکل و جستجو از یک مرکز واحد.</p>
            </div>
        </div>
        <nav class="sup-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="sup-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="sup-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="sup-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
                    <i class="material-icons sup-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="sup-side-card sup-mini">
        <div class="sup-mini__head"><i class="material-icons">support_agent</i><span>خلاصه سریع</span></div>
        <div class="sup-mini-row"><span>تیکت‌های باز</span><strong class="sup-num"><?= number_format($openTickets) ?></strong></div>
        <div class="sup-mini-row"><span>دسترسی سریع</span><strong>Navbar</strong></div>
    </div>
</aside>
