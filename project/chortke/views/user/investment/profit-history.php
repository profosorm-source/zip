<?php
$title = $title ?? 'تاریخچه سود و زیان';
$hideSidebar = true;
$profits      = $profits ?? [];
$total        = $total ?? 0;
$totalPages   = $totalPages ?? 1;
$currentPage  = $currentPage ?? 1;
$settings     = $settings ?? [];
$activeInvestment = $activeInvestment ?? null;
$isDepositLocked = (bool)($isDepositLocked ?? false);

$totalProfit = 0.0;
$totalLoss = 0.0;
$profitCount = 0;
$lossCount = 0;
foreach ($profits as $p) {
    $amt = (float)($p->net_amount ?? $p->amount ?? 0);
    if ($amt >= 0) {
        $totalProfit += $amt;
        $profitCount++;
    } else {
        $totalLoss += $amt;
        $lossCount++;
    }
}
$netResult = $totalProfit + $totalLoss;

ob_start();
?>

<div class="inv-wrap">
    <section class="inv-hub-hero inv-hub-hero--compact">
        <div class="inv-hub-hero__main">
            <div class="inv-hub-hero__icon"><i class="material-icons">show_chart</i></div>
            <div>
                <div class="inv-hub-hero__eyebrow">Performance Spoke</div>
                <h1 class="inv-hub-hero__title">تاریخچه سود و زیان</h1>
                <p class="inv-hub-hero__sub">گزارش کامل عملکرد سرمایه‌گذاری و نتیجه هر دوره.</p>
            </div>
        </div>
        <a href="<?= url('/investment') ?>" class="inv-btn inv-btn-ghost">
            <i class="material-icons">arrow_forward</i>
            بازگشت به مرکز
        </a>
    </section>

    <div class="inv-hub-layout">
        <?php $activeSpoke = 'profit'; include view_path('user.investment._hub-nav'); ?>

        <main class="inv-hub-main">
            <section class="inv-stats" aria-label="آمار تاریخچه سود و زیان">
                <div class="inv-stat inv-stat--gold">
                    <div class="inv-stat__icon"><i class="material-icons">receipt_long</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">کل رکوردها</span>
                        <span class="inv-stat__val inv-num"><?= number_format((int)$total) ?></span>
                        <span class="inv-stat__unit">دوره ثبت‌شده</span>
                    </div>
                </div>
                <div class="inv-stat inv-stat--green">
                    <div class="inv-stat__icon"><i class="material-icons">trending_up</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">مجموع سود</span>
                        <span class="inv-stat__val inv-num">+<?= number_format($totalProfit, 4) ?></span>
                        <span class="inv-stat__unit">USDT</span>
                    </div>
                </div>
                <div class="inv-stat inv-stat--red">
                    <div class="inv-stat__icon"><i class="material-icons">trending_down</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">مجموع ضرر</span>
                        <span class="inv-stat__val inv-num"><?= number_format($totalLoss, 4) ?></span>
                        <span class="inv-stat__unit">USDT</span>
                    </div>
                </div>
                <div class="inv-stat <?= $netResult >= 0 ? 'inv-stat--green' : 'inv-stat--red' ?>">
                    <div class="inv-stat__icon"><i class="material-icons"><?= $netResult >= 0 ? 'account_balance_wallet' : 'money_off' ?></i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">خالص نتیجه</span>
                        <span class="inv-stat__val inv-num"><?= $netResult >= 0 ? '+' : '' ?><?= number_format($netResult, 4) ?></span>
                        <span class="inv-stat__unit">USDT</span>
                    </div>
                </div>
            </section>

            <section class="inv-section">
                <div class="inv-section__header inv-section__header--toolbar">
                    <div class="inv-section__title-group">
                        <i class="material-icons">table_rows</i>
                        <h2>گزارش کامل عملکرد</h2>
                    </div>
                    <div class="inv-toolbar-meta">
                        <span class="inv-text-up">↑ سود: <?= number_format($profitCount) ?></span>
                        <span class="inv-text-down">↓ ضرر: <?= number_format($lossCount) ?></span>
                    </div>
                </div>

                <?php if (empty($profits)): ?>
                    <div class="inv-empty inv-empty--inside">
                        <div class="inv-empty__icon"><i class="material-icons">show_chart</i></div>
                        <h3>تاریخچه‌ای وجود ندارد</h3>
                        <p>پس از فعال‌سازی سرمایه‌گذاری، سود و زیان دوره‌ای شما اینجا نمایش داده می‌شود.</p>
                        <a href="<?= url('/investment') ?>" class="inv-btn inv-btn-primary">
                            <i class="material-icons">hub</i>
                            رفتن به مرکز سرمایه‌گذاری
                        </a>
                    </div>
                <?php else: ?>
                    <div class="inv-table-wrap">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>تاریخ / دوره</th>
                                    <th>سرمایه مبنا</th>
                                    <th>سود/زیان</th>
                                    <th>نرخ دوره</th>
                                    <th>وضعیت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profits as $p): ?>
                                    <?php
                                        $amt = (float)($p->net_amount ?? $p->amount ?? 0);
                                        $rate = (float)($p->profit_loss_percent ?? $p->daily_rate ?? 0);
                                        $capital = (float)($p->capital ?? $p->investment_amount ?? $p->balance_before ?? 0);
                                        $date = $p->period_date ?? $p->date ?? $p->created_at ?? '';
                                        $periodLabel = $date ? to_jalali($date) : e((string)($p->period ?? '—'));
                                    ?>
                                    <tr>
                                        <td><?= $periodLabel ?></td>
                                        <td class="inv-num"><?= number_format($capital, 4) ?> USDT</td>
                                        <td class="inv-num <?= $amt >= 0 ? 'inv-text-up' : 'inv-text-down' ?>">
                                            <?= $amt >= 0 ? '+' : '' ?><?= number_format($amt, 4) ?> USDT
                                        </td>
                                        <td class="inv-num">
                                            <span class="inv-pct inv-pct--<?= $rate >= 0 ? 'up' : 'down' ?>"><?= $rate >= 0 ? '+' : '' ?><?= number_format($rate, 2) ?>%</span>
                                        </td>
                                        <td>
                                            <span class="inv-badge <?= $amt >= 0 ? 'inv-badge--active' : 'inv-badge--danger' ?>">
                                                <?= $amt >= 0 ? 'سود' : 'زیان' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="inv-pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="<?= url('/investment/profit-history?page=' . $i) ?>" class="inv-page <?= $i == $currentPage ? 'active' : '' ?>"><?= e((string)$i) ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userinvestment.css') . '">';
include view_path('layouts.user');
?>
