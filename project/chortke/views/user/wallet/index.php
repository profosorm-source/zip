<?php
$title = $title ?? 'مرکز مالی';
$hideSidebar = true;
$summary = $summary ?? null;
$siteCurrency = $siteCurrency ?? 'both';

$balanceIrt = $summary ? (float)($summary->balance_irt ?? 0) : 0.0;
$lockedIrt  = $summary ? (float)($summary->locked_irt ?? 0) : 0.0;
$totalIrt   = $summary ? (float)($summary->total_irt ?? ($balanceIrt + $lockedIrt)) : 0.0;
$balanceUsdt = $summary ? (float)($summary->balance_usdt ?? 0) : 0.0;
$lockedUsdt  = $summary ? (float)($summary->locked_usdt ?? 0) : 0.0;
$totalUsdt   = $summary ? (float)($summary->total_usdt ?? ($balanceUsdt + $lockedUsdt)) : 0.0;
$canWithdrawToday = $summary ? (bool)($summary->can_withdraw_today ?? true) : false;

$statsIrt = $summary->stats->irt ?? (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0];
$statsUsdt = $summary->stats->usdt ?? (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0];

ob_start();
?>

<div class="fin-wrap">
    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">account_balance_wallet</i></div>
            <div>
                <div class="fin-hero__eyebrow">Finance Hub</div>
                <h1 class="fin-hero__title">مرکز کیف پول و مالی</h1>
                <p class="fin-hero__sub">مدیریت موجودی تومان و USDT، واریز، برداشت، کارت‌های بانکی و تاریخچه تراکنش‌ها از یک مرکز واحد.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">dashboard</i> بازگشت به پنل کاربری</a>
            <a href="<?= url('/wallet/deposit') ?>" class="fin-btn fin-btn-primary"><i class="material-icons">add</i> افزایش موجودی</a>
            <a href="<?= url('/wallet/withdraw') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">payments</i> برداشت وجه</a>
        </div>
    </section>

    <div class="fin-hub-layout">
        <?php $activeSpoke = 'overview'; include view_path('user.wallet._finance-nav'); ?>

        <main class="fin-hub-main">
            <?php if ($summary): ?>
                <?php if (!$canWithdrawToday): ?>
                    <div class="fin-alert fin-alert-warning">
                        <i class="material-icons">info</i>
                        <div>
                            <strong>محدودیت برداشت:</strong>
                            شما امروز یکبار برداشت انجام داده‌اید. برداشت بعدی از فردا امکان‌پذیر است.
                            <?php if (!empty($summary->last_withdrawal_at)): ?>
                                <br><small>آخرین برداشت: <?= to_jalali($summary->last_withdrawal_at) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="fin-spoke-grid" aria-label="میانبرهای مالی">
                    <a href="<?= url('/wallet/deposit') ?>" class="fin-spoke-card">
                        <span class="fin-spoke-card__icon"><i class="material-icons">add_card</i></span>
                        <span class="fin-spoke-card__body"><strong>افزایش موجودی</strong><small>انتخاب واریز آنلاین، دستی یا USDT</small></span>
                        <i class="material-icons">chevron_left</i>
                    </a>
                    <a href="<?= url('/wallet/withdraw') ?>" class="fin-spoke-card">
                        <span class="fin-spoke-card__icon"><i class="material-icons">payments</i></span>
                        <span class="fin-spoke-card__body"><strong>برداشت وجه</strong><small>ثبت برداشت امن و قابل پیگیری</small></span>
                        <i class="material-icons">chevron_left</i>
                    </a>
                    <a href="<?= url('/wallet/history') ?>" class="fin-spoke-card">
                        <span class="fin-spoke-card__icon"><i class="material-icons">receipt_long</i></span>
                        <span class="fin-spoke-card__body"><strong>تاریخچه تراکنش‌ها</strong><small>گزارش و فیلتر همه تراکنش‌ها</small></span>
                        <i class="material-icons">chevron_left</i>
                    </a>
                    <a href="<?= url('/bank-cards') ?>" class="fin-spoke-card">
                        <span class="fin-spoke-card__icon"><i class="material-icons">credit_card</i></span>
                        <span class="fin-spoke-card__body"><strong>کارت‌های بانکی</strong><small>مدیریت مقصدهای برداشت تومانی</small></span>
                        <i class="material-icons">chevron_left</i>
                    </a>
                </section>

                <section class="fin-stats" aria-label="خلاصه مالی">
                    <div class="fin-stat fin-stat--gold">
                        <div class="fin-stat__icon"><i class="material-icons">account_balance</i></div>
                        <div><span class="fin-stat__lbl">موجودی آزاد تومان</span><span class="fin-stat__val fin-num"><?= number_format($balanceIrt) ?></span><span class="fin-stat__unit">IRT</span></div>
                    </div>
                    <div class="fin-stat fin-stat--green">
                        <div class="fin-stat__icon"><i class="material-icons">currency_bitcoin</i></div>
                        <div><span class="fin-stat__lbl">موجودی آزاد USDT</span><span class="fin-stat__val fin-num"><?= number_format($balanceUsdt, 4) ?></span><span class="fin-stat__unit">USDT</span></div>
                    </div>
                    <div class="fin-stat fin-stat--blue">
                        <div class="fin-stat__icon"><i class="material-icons">lock</i></div>
                        <div><span class="fin-stat__lbl">موجودی قفل‌شده تومان</span><span class="fin-stat__val fin-num"><?= number_format($lockedIrt) ?></span><span class="fin-stat__unit">IRT</span></div>
                    </div>
                    <div class="fin-stat fin-stat--red">
                        <div class="fin-stat__icon"><i class="material-icons">lock_clock</i></div>
                        <div><span class="fin-stat__lbl">موجودی قفل‌شده USDT</span><span class="fin-stat__val fin-num"><?= number_format($lockedUsdt, 4) ?></span><span class="fin-stat__unit">USDT</span></div>
                    </div>
                </section>

                <section class="fin-wallet-grid">
                    <article class="fin-wallet-card">
                        <div class="fin-wallet-card__head">
                            <div class="fin-wallet-card__title"><i class="material-icons">account_balance_wallet</i> کیف پول تومانی</div>
                            <span class="fin-badge fin-badge--warning">IRT</span>
                        </div>
                        <div class="fin-wallet-card__body">
                            <div class="fin-balance-row"><span>موجودی آزاد</span><strong class="fin-num"><?= number_format($balanceIrt) ?></strong></div>
                            <div class="fin-balance-row"><span>موجودی قفل‌شده</span><strong class="fin-num"><?= number_format($lockedIrt) ?></strong></div>
                            <div class="fin-balance-row total"><span>مجموع</span><strong class="fin-num"><?= number_format($totalIrt) ?></strong></div>
                        </div>
                        <div class="fin-wallet-card__foot">
                            <a href="<?= url('/wallet/history?currency=irt') ?>" class="fin-btn fin-btn-secondary"><i class="material-icons">history</i> تاریخچه</a>
                            <a href="<?= url('/wallet/deposit') ?>" class="fin-btn fin-btn-primary"><i class="material-icons">add</i> واریز تومان</a>
                        </div>
                    </article>

                    <article class="fin-wallet-card">
                        <div class="fin-wallet-card__head">
                            <div class="fin-wallet-card__title"><i class="material-icons">currency_bitcoin</i> کیف پول تتری</div>
                            <span class="fin-badge fin-badge--success">USDT</span>
                        </div>
                        <div class="fin-wallet-card__body">
                            <div class="fin-balance-row"><span>موجودی آزاد</span><strong class="fin-num"><?= number_format($balanceUsdt, 4) ?></strong></div>
                            <div class="fin-balance-row"><span>موجودی قفل‌شده</span><strong class="fin-num"><?= number_format($lockedUsdt, 4) ?></strong></div>
                            <div class="fin-balance-row total"><span>مجموع</span><strong class="fin-num"><?= number_format($totalUsdt, 4) ?></strong></div>
                        </div>
                        <div class="fin-wallet-card__foot">
                            <a href="<?= url('/wallet/history?currency=usdt') ?>" class="fin-btn fin-btn-secondary"><i class="material-icons">history</i> تاریخچه</a>
                            <a href="<?= url('/wallet/deposit/crypto') ?>" class="fin-btn fin-btn-primary"><i class="material-icons">add</i> واریز USDT</a>
                        </div>
                    </article>
                </section>

                <section class="fin-section">
                    <div class="fin-section__header">
                        <div class="fin-section__title"><i class="material-icons">analytics</i> آمار تراکنش‌ها</div>
                        <a href="<?= url('/wallet/history') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">receipt_long</i> گزارش کامل</a>
                    </div>
                    <div class="fin-section__body">
                        <div class="fin-stats" style="margin-bottom:0;">
                            <div class="fin-stat fin-stat--green"><div class="fin-stat__icon"><i class="material-icons">trending_up</i></div><div><span class="fin-stat__lbl">واریزهای تومانی</span><span class="fin-stat__val fin-num"><?= number_format((float)($statsIrt->total_deposits ?? 0)) ?></span><span class="fin-stat__unit"><?= number_format((int)($statsIrt->deposit_count ?? 0)) ?> تراکنش</span></div></div>
                            <div class="fin-stat fin-stat--red"><div class="fin-stat__icon"><i class="material-icons">trending_down</i></div><div><span class="fin-stat__lbl">برداشت‌های تومانی</span><span class="fin-stat__val fin-num"><?= number_format((float)($statsIrt->total_withdrawals ?? 0)) ?></span><span class="fin-stat__unit"><?= number_format((int)($statsIrt->withdrawal_count ?? 0)) ?> تراکنش</span></div></div>
                            <div class="fin-stat fin-stat--green"><div class="fin-stat__icon"><i class="material-icons">trending_up</i></div><div><span class="fin-stat__lbl">واریزهای USDT</span><span class="fin-stat__val fin-num"><?= number_format((float)($statsUsdt->total_deposits ?? 0), 2) ?></span><span class="fin-stat__unit"><?= number_format((int)($statsUsdt->deposit_count ?? 0)) ?> تراکنش</span></div></div>
                            <div class="fin-stat fin-stat--red"><div class="fin-stat__icon"><i class="material-icons">trending_down</i></div><div><span class="fin-stat__lbl">برداشت‌های USDT</span><span class="fin-stat__val fin-num"><?= number_format((float)($statsUsdt->total_withdrawals ?? 0), 2) ?></span><span class="fin-stat__unit"><?= number_format((int)($statsUsdt->withdrawal_count ?? 0)) ?> تراکنش</span></div></div>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <div class="fin-alert fin-alert-danger"><i class="material-icons">error</i> خطا در دریافت اطلاعات کیف پول</div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">';
include view_path('layouts.user');
?>
