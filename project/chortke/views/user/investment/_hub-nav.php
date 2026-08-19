<?php
$activeSpoke = $activeSpoke ?? 'overview';
$settings = $settings ?? [];
$activeInvestment = $activeInvestment ?? null;
$isDepositLocked = (bool)($isDepositLocked ?? false);
$hasActiveInvestment = !empty($activeInvestment);
$canCreateInvestment = !$hasActiveInvestment && !$isDepositLocked;

$spokes = [
    [
        'key'  => 'overview',
        'href' => url('/investment'),
        'icon' => 'space_dashboard',
        'label'=> 'مرکز سرمایه‌گذاری',
        'desc' => 'نمای کلی، وضعیت پلن و عملکرد',
    ],
    [
        'key'  => 'create',
        'href' => url('/investment/create'),
        'icon' => 'add_chart',
        'label'=> 'سرمایه‌گذاری جدید',
        'desc' => $canCreateInvestment ? 'شروع پلن جدید بدون خروج از ماژول' : 'در حال حاضر غیرفعال است',
        'muted'=> !$canCreateInvestment && $activeSpoke !== 'create',
    ],
    [
        'key'  => 'profit',
        'href' => url('/investment/profit-history'),
        'icon' => 'receipt_long',
        'label'=> 'تاریخچه سود و زیان',
        'desc' => 'گزارش کامل عملکرد سرمایه',
    ],
    [
        'key'  => 'withdrawals',
        'href' => url('/investment#withdrawals'),
        'icon' => 'payments',
        'label'=> 'درخواست‌های برداشت',
        'desc' => 'پیگیری برداشت سود یا تسویه کامل',
    ],
    [
        'key'  => 'market',
        'href' => url('/investment#market'),
        'icon' => 'candlestick_chart',
        'label'=> 'معاملات بازار',
        'desc' => 'آخرین معاملات بسته‌شده',
    ],
];
?>
<aside class="inv-hub-sidebar" aria-label="منوی داخلی ماژول سرمایه‌گذاری">
    <div class="inv-hub-card inv-module-card">
        <div class="inv-module-card__top">
            <div class="inv-module-card__icon"><i class="material-icons">hub</i></div>
            <div>
                <div class="inv-module-card__eyebrow">Module Hub</div>
                <h2 class="inv-module-card__title">سرمایه‌گذاری</h2>
                <p class="inv-module-card__desc">همه بخش‌ها از همین مرکز مدیریت می‌شوند.</p>
            </div>
        </div>

        <nav class="inv-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <?php
                    $isActive = $activeSpoke === $spoke['key'];
                    $isMuted  = !empty($spoke['muted']);
                ?>
                <a class="inv-module-nav__item <?= $isActive ? 'active' : '' ?> <?= $isMuted ? 'is-muted' : '' ?>"
                   href="<?= e($spoke['href']) ?>"
                   <?= $isMuted ? 'aria-disabled="true"' : '' ?>>
                    <span class="inv-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="inv-module-nav__body">
                        <strong><?= e($spoke['label']) ?></strong>
                        <small><?= e($spoke['desc']) ?></small>
                    </span>
                    <i class="material-icons inv-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="inv-hub-card inv-risk-mini">
        <div class="inv-risk-mini__head">
            <i class="material-icons">shield</i>
            <span>کنترل ریسک</span>
        </div>
        <p>سرمایه‌گذاری تضمین سود ندارد. قبل از شروع، مبلغ، ریسک و دوره قفل برداشت را بررسی کنید.</p>
        <div class="inv-risk-mini__meta">
            <span>Cooldown برداشت</span>
            <strong><?= e((string)($settings['withdrawal_cooldown'] ?? 7)) ?> روز</strong>
        </div>
    </div>
</aside>
