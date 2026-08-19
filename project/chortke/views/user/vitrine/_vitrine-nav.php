<?php
$activeSpoke = $activeSpoke ?? 'market';
$spokes = [
    ['key'=>'market',    'href'=>url('/vitrine'),              'icon'=>'store',         'label'=>'بازار فروش',    'desc'=>'خرید پیج، کانال و ابزارهای دیجیتال'],
    ['key'=>'wanted',    'href'=>url('/vitrine/wanted'),       'icon'=>'search',        'label'=>'خریداران',     'desc'=>'آگهی‌های درخواست خرید'],
    ['key'=>'listings',  'href'=>url('/vitrine/my-listings'),  'icon'=>'list_alt',      'label'=>'آگهی‌های من',   'desc'=>'مدیریت آگهی‌های ثبت‌شده'],
    ['key'=>'purchases', 'href'=>url('/vitrine/my-purchases'), 'icon'=>'shopping_cart', 'label'=>'خریدهای من',    'desc'=>'پیگیری وضعیت معامله و Escrow'],
    ['key'=>'create',    'href'=>url('/vitrine/sell/create'),  'icon'=>'add_circle',    'label'=>'ثبت آگهی فروش', 'desc'=>'فروش سریع با تضمین چرتکه'],
];
?>
<aside class="fin-hub-sidebar" aria-label="منوی داخلی ویترین">
    <div class="fin-side-card fin-module-card">
        <div class="fin-module-card__top">
            <div class="fin-module-card__icon"><i class="material-icons">storefront</i></div>
            <div>
                <div class="fin-module-card__eyebrow">Vitrine Hub</div>
                <h2 class="fin-module-card__title">ویترین (بازار دیجیتال)</h2>
                <p class="fin-module-card__desc">خرید و فروش امن پیج، کانال، گروه، VPS و ابزارها با تضمین Escrow.</p>
            </div>
        </div>
        <nav class="fin-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="fin-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="fin-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="fin-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
                    <i class="material-icons fin-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</aside>
