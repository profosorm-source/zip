<?php
$activeSpoke = $activeSpoke ?? 'overview';
$summary = $summary ?? null;
$siteCurrency = $siteCurrency ?? 'both';

$balanceIrt = $summary ? (float)($summary->balance_irt ?? 0) : 0.0;
$balanceUsdt = $summary ? (float)($summary->balance_usdt ?? 0) : 0.0;

$spokes = [
    ['key' => 'overview',    'href' => url('/wallet'),                'icon' => 'account_balance_wallet', 'label' => 'مرکز مالی',           'desc' => 'موجودی، آمار و عملیات سریع'],
    ['key' => 'deposit',     'href' => url('/wallet/deposit'),        'icon' => 'add_card',               'label' => 'افزایش موجودی',       'desc' => 'واریز تومان یا USDT'],
    ['key' => 'withdraw',    'href' => url('/wallet/withdraw'),       'icon' => 'payments',               'label' => 'برداشت وجه',          'desc' => 'ثبت درخواست برداشت امن'],
    ['key' => 'history',     'href' => url('/wallet/history'),        'icon' => 'receipt_long',           'label' => 'تاریخچه تراکنش‌ها',   'desc' => 'فیلتر و گزارش کامل'],
    ['key' => 'withdrawals', 'href' => url('/withdrawals'),           'icon' => 'pending_actions',        'label' => 'درخواست‌های برداشت',  'desc' => 'پیگیری وضعیت برداشت‌ها'],
    ['key' => 'cards',       'href' => url('/bank-cards'),            'icon' => 'credit_card',            'label' => 'کارت‌های بانکی',      'desc' => 'مدیریت مقصد برداشت'],
    ['key' => 'manual',      'href' => url('/manual-deposits'),       'icon' => 'account_balance',        'label' => 'واریزهای دستی',       'desc' => 'رسیدهای کارت‌به‌کارت'],
    ['key' => 'crypto',      'href' => url('/crypto-deposits'),       'icon' => 'currency_bitcoin',       'label' => 'واریزهای رمزارزی',    'desc' => 'پیگیری شارژ USDT'],
];
?>
<aside class="fin-hub-sidebar" aria-label="منوی داخلی ماژول مالی">
    <div class="fin-side-card fin-module-card">
        <div class="fin-module-card__top">
            <div class="fin-module-card__icon"><i class="material-icons">hub</i></div>
            <div>
                <div class="fin-module-card__eyebrow">Finance Hub</div>
                <h2 class="fin-module-card__title">کیف پول و مالی</h2>
                <p class="fin-module-card__desc">تمام عملیات مالی از یک مرکز واحد مدیریت می‌شود.</p>
            </div>
        </div>
        <nav class="fin-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="fin-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="fin-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="fin-module-nav__body">
                        <strong><?= e($spoke['label']) ?></strong>
                        <small><?= e($spoke['desc']) ?></small>
                    </span>
                    <i class="material-icons fin-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="fin-side-card fin-balance-mini">
        <div class="fin-balance-mini__head">
            <i class="material-icons">verified_user</i>
            <span>خلاصه سریع</span>
        </div>
        <div class="fin-mini-row">
            <span>موجودی تومان</span>
            <strong class="fin-num"><?= number_format($balanceIrt) ?></strong>
        </div>
        <div class="fin-mini-row">
            <span>موجودی USDT</span>
            <strong class="fin-num"><?= number_format($balanceUsdt, 4) ?></strong>
        </div>
    </div>
</aside>
