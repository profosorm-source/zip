<?php
$title = 'مرکز سرمایه‌گذاری';
$hideSidebar = true;

$activeInvestment = $activeInvestment ?? null;
$canWithdraw      = $canWithdraw ?? ['allowed' => false, 'reason' => ''];
$profitHistory    = $profitHistory ?? [];
$recentTrades     = $recentTrades ?? [];
$withdrawals      = $withdrawals ?? [];
$settings         = $settings ?? [];
$isDepositLocked  = (bool)($isDepositLocked ?? false);

$hasActiveInvestment = !empty($activeInvestment);
$initialAmount = $hasActiveInvestment ? (float)($activeInvestment->amount ?? 0) : 0.0;
$currentBalance = $hasActiveInvestment ? (float)($activeInvestment->current_balance ?? 0) : 0.0;
$profit = $hasActiveInvestment ? ($currentBalance - $initialAmount) : 0.0;
$profitPct = $initialAmount > 0 ? round(($profit / $initialAmount) * 100, 2) : 0;
$isPositive = $profit >= 0;
$balancePct = $initialAmount > 0 ? min(200, max(0, ($currentBalance / $initialAmount) * 100)) : 0;
$canCreateInvestment = !$hasActiveInvestment && !$isDepositLocked;
$pendingWithdrawals = 0;
foreach ($withdrawals as $w) {
    if (($w->status ?? '') === 'pending') {
        $pendingWithdrawals++;
    }
}

ob_start();
?>

<div id="investmentRoot"
     class="inv-wrap"
     data-withdraw-url="<?= e(url('/investment/withdraw')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">

    <section class="inv-hub-hero">
        <div class="inv-hub-hero__main">
            <div class="inv-hub-hero__icon"><i class="material-icons">trending_up</i></div>
            <div>
                <div class="inv-hub-hero__eyebrow">Hub &amp; Spoke Investment Console</div>
                <h1 class="inv-hub-hero__title">مرکز سرمایه‌گذاری</h1>
                <p class="inv-hub-hero__sub">مدیریت پلن فعال، برداشت سود، تاریخچه عملکرد و آخرین معاملات از یک مرکز واحد.</p>
            </div>
        </div>
        <div class="inv-hub-hero__side">
            <a href="<?= url('/dashboard') ?>" class="inv-btn inv-btn-panel">
                <i class="material-icons">dashboard</i>
                بازگشت به پنل کاربری
            </a>
            <div class="inv-status-pill <?= $hasActiveInvestment ? 'is-live' : 'is-idle' ?>">
                <span class="inv-status-pill__dot"></span>
                <?= $hasActiveInvestment ? 'پلن فعال' : 'بدون پلن فعال' ?>
            </div>
            <?php if ($canCreateInvestment): ?>
                <a href="<?= url('/investment/create') ?>" class="inv-btn inv-btn-primary">
                    <i class="material-icons">add</i>
                    سرمایه‌گذاری جدید
                </a>
            <?php else: ?>
                <a href="<?= url('/investment/profit-history') ?>" class="inv-btn inv-btn-ghost">
                    <i class="material-icons">receipt_long</i>
                    مشاهده تاریخچه
                </a>
            <?php endif; ?>
        </div>
    </section>

    <div class="inv-hub-layout">
        <?php $activeSpoke = 'overview'; include view_path('user.investment._hub-nav'); ?>

        <main class="inv-hub-main">
            <div class="inv-risk-banner">
                <i class="material-icons">warning_amber</i>
                <span><strong>هشدار ریسک:</strong> سرمایه‌گذاری در بازارهای مالی دارای ریسک بالاست و احتمال ضرر تا ۱۰۰٪ وجود دارد. فقط با سرمایه‌ای وارد شوید که توان از دست دادنش را دارید.</span>
            </div>

            <?php if ($isDepositLocked): ?>
                <div class="inv-alert inv-alert--info">
                    <i class="material-icons">lock_clock</i>
                    <span>به دلیل برداشت اخیر، تا پایان دوره قفل امکان سرمایه‌گذاری جدید ندارید.</span>
                </div>
            <?php endif; ?>

            <section class="inv-spoke-grid" aria-label="میانبرهای ماژول سرمایه‌گذاری">
                <a href="<?= url('/investment/create') ?>" class="inv-spoke-card <?= !$canCreateInvestment ? 'is-muted' : '' ?>">
                    <span class="inv-spoke-card__icon"><i class="material-icons">add_chart</i></span>
                    <span class="inv-spoke-card__body">
                        <strong>شروع سرمایه‌گذاری</strong>
                        <small><?= $canCreateInvestment ? 'ثبت پلن جدید با فرم مرحله‌ای' : 'به‌دلیل پلن فعال یا قفل برداشت غیرفعال است' ?></small>
                    </span>
                    <i class="material-icons">chevron_left</i>
                </a>
                <a href="#withdrawals" class="inv-spoke-card">
                    <span class="inv-spoke-card__icon"><i class="material-icons">payments</i></span>
                    <span class="inv-spoke-card__body">
                        <strong>برداشت‌ها</strong>
                        <small><?= $pendingWithdrawals > 0 ? $pendingWithdrawals . ' درخواست در انتظار' : 'پیگیری درخواست‌های برداشت' ?></small>
                    </span>
                    <i class="material-icons">chevron_left</i>
                </a>
                <a href="<?= url('/investment/profit-history') ?>" class="inv-spoke-card">
                    <span class="inv-spoke-card__icon"><i class="material-icons">receipt_long</i></span>
                    <span class="inv-spoke-card__body">
                        <strong>تاریخچه سود</strong>
                        <small>گزارش کامل سود و زیان</small>
                    </span>
                    <i class="material-icons">chevron_left</i>
                </a>
            </section>

            <section class="inv-stats" aria-label="آمار سرمایه‌گذاری">
                <div class="inv-stat inv-stat--gold">
                    <div class="inv-stat__icon"><i class="material-icons">account_balance</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">سرمایه اولیه</span>
                        <span class="inv-stat__val inv-num"><?= number_format($initialAmount, 2) ?></span>
                        <span class="inv-stat__unit">USDT</span>
                    </div>
                </div>
                <div class="inv-stat <?= $isPositive ? 'inv-stat--green' : 'inv-stat--red' ?>">
                    <div class="inv-stat__icon"><i class="material-icons">account_balance_wallet</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">موجودی فعلی</span>
                        <span class="inv-stat__val inv-num"><?= number_format($currentBalance, 2) ?></span>
                        <span class="inv-stat__unit">USDT</span>
                    </div>
                </div>
                <div class="inv-stat <?= $isPositive ? 'inv-stat--green' : 'inv-stat--red' ?>">
                    <div class="inv-stat__icon"><i class="material-icons"><?= $isPositive ? 'trending_up' : 'trending_down' ?></i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">سود / زیان</span>
                        <span class="inv-stat__val inv-num"><?= $profit >= 0 ? '+' : '' ?><?= number_format($profit, 2) ?></span>
                        <span class="inv-stat__pct <?= $isPositive ? 'inv-stat__pct--up' : 'inv-stat__pct--down' ?>"><?= $profitPct >= 0 ? '+' : '' ?><?= e((string)$profitPct) ?>%</span>
                    </div>
                </div>
                <div class="inv-stat inv-stat--blue">
                    <div class="inv-stat__icon"><i class="material-icons">pending_actions</i></div>
                    <div class="inv-stat__body">
                        <span class="inv-stat__lbl">برداشت‌های اخیر</span>
                        <span class="inv-stat__val inv-num"><?= number_format(count($withdrawals)) ?></span>
                        <span class="inv-stat__unit"><?= $pendingWithdrawals > 0 ? $pendingWithdrawals . ' در انتظار بررسی' : 'بدون درخواست معلق' ?></span>
                    </div>
                </div>
            </section>

            <?php if ($hasActiveInvestment): ?>
                <section class="inv-plan-card">
                    <div class="inv-plan-card__header">
                        <div class="inv-plan-card__title">
                            <i class="material-icons">verified</i>
                            پلن فعال شما
                        </div>
                        <span class="inv-badge inv-badge--active"><span class="inv-badge__dot"></span> فعال</span>
                    </div>
                    <div class="inv-plan-card__body">
                        <div class="inv-plan-grid">
                            <div class="inv-plan-metric">
                                <span>شناسه پلن</span>
                                <strong class="inv-num">#<?= e((string)($activeInvestment->id ?? '—')) ?></strong>
                            </div>
                            <div class="inv-plan-metric">
                                <span>تاریخ شروع</span>
                                <strong><?= to_jalali($activeInvestment->start_date ?? $activeInvestment->created_at ?? '') ?></strong>
                            </div>
                            <div class="inv-plan-metric">
                                <span>آخرین محاسبه</span>
                                <strong><?= !empty($activeInvestment->last_profit_date) ? to_jalali($activeInvestment->last_profit_date) : 'هنوز ثبت نشده' ?></strong>
                            </div>
                        </div>

                        <div class="inv-progress-wrap">
                            <div class="inv-progress-label">
                                <span>نسبت موجودی فعلی به سرمایه اولیه</span>
                                <span class="inv-num"><?= number_format($balancePct, 1) ?>%</span>
                            </div>
                            <div class="inv-progress">
                                <div class="inv-progress__bar <?= $isPositive ? 'inv-progress__bar--up' : 'inv-progress__bar--down' ?>" data-width="<?= e((string)min(100, $balancePct)) ?>%"></div>
                            </div>
                        </div>

                        <div class="inv-actions">
                            <?php if (!empty($canWithdraw['allowed'])): ?>
                                <?php if ($profit > 0): ?>
                                    <button class="inv-action-btn inv-action-btn--profit" type="button" data-action="request-withdrawal" data-type="profit_only">
                                        <i class="material-icons">savings</i>
                                        <span>
                                            <strong>برداشت سود</strong>
                                            <small class="inv-num"><?= number_format($profit, 2) ?> USDT</small>
                                        </span>
                                    </button>
                                <?php endif; ?>
                                <button class="inv-action-btn inv-action-btn--close" type="button" data-action="request-withdrawal" data-type="full_close">
                                    <i class="material-icons">exit_to_app</i>
                                    <span>
                                        <strong>بستن و برداشت کامل</strong>
                                        <small class="inv-num"><?= number_format($currentBalance, 2) ?> USDT</small>
                                    </span>
                                </button>
                            <?php else: ?>
                                <div class="inv-cooldown-notice">
                                    <i class="material-icons">schedule</i>
                                    <span><?= e($canWithdraw['reason'] ?? 'در حال حاضر امکان برداشت وجود ندارد.') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section class="inv-empty inv-empty--hub">
                    <div class="inv-empty__icon"><i class="material-icons">trending_up</i></div>
                    <h3>هنوز پلن سرمایه‌گذاری فعال ندارید</h3>
                    <p>از همین مرکز، سرمایه‌گذاری جدید را با فرم مرحله‌ای و کنترل ریسک شروع کنید.</p>
                    <?php if ($canCreateInvestment): ?>
                        <a href="<?= url('/investment/create') ?>" class="inv-btn inv-btn-primary mt-2">
                            <i class="material-icons">add</i>
                            شروع سرمایه‌گذاری
                        </a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section id="withdrawals" class="inv-section">
                <div class="inv-section__header">
                    <i class="material-icons">payments</i>
                    <h2>درخواست‌های برداشت</h2>
                </div>
                <?php if (empty($withdrawals)): ?>
                    <div class="inv-section-empty">
                        <i class="material-icons">inbox</i>
                        <strong>درخواستی ثبت نشده است</strong>
                        <span>پس از برداشت سود یا تسویه کامل، وضعیت درخواست‌ها در این بخش نمایش داده می‌شود.</span>
                    </div>
                <?php else: ?>
                    <div class="inv-table-wrap">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>مبلغ</th>
                                    <th>نوع</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $wStatusMap = [
                                    'pending'   => ['در انتظار',  'inv-badge--pending'],
                                    'approved'  => ['تأیید شده',  'inv-badge--info'],
                                    'completed' => ['واریز شده',  'inv-badge--active'],
                                    'rejected'  => ['رد شده',     'inv-badge--danger'],
                                ];
                                foreach ($withdrawals as $w):
                                    [$wLabel, $wClass] = $wStatusMap[$w->status ?? ''] ?? ['نامشخص', 'inv-badge--muted'];
                                ?>
                                    <tr>
                                        <td class="inv-num"><strong><?= number_format((float)($w->amount ?? 0), 2) ?></strong> <small>USDT</small></td>
                                        <td><?= ($w->withdrawal_type ?? '') === 'profit_only' ? 'برداشت سود' : 'بستن کامل' ?></td>
                                        <td><span class="inv-badge <?= e($wClass) ?>"><?= e($wLabel) ?></span></td>
                                        <td><?= to_jalali($w->created_at ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section id="performance" class="inv-section">
                <div class="inv-section__header">
                    <i class="material-icons">history</i>
                    <h2>خلاصه تاریخچه سود و زیان</h2>
                    <a href="<?= url('/investment/profit-history') ?>" class="inv-section__more">مشاهده کامل <i class="material-icons">chevron_left</i></a>
                </div>
                <?php if (empty($profitHistory)): ?>
                    <div class="inv-section-empty">
                        <i class="material-icons">show_chart</i>
                        <strong>هنوز رکوردی وجود ندارد</strong>
                        <span>پس از محاسبه سود/زیان، آخرین رکوردها اینجا نمایش داده می‌شود.</span>
                    </div>
                <?php else: ?>
                    <div class="inv-table-wrap">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>دوره</th>
                                    <th>درصد</th>
                                    <th>سود/زیان خالص</th>
                                    <th>موجودی بعد</th>
                                    <th>نوع</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profitHistory as $p): ?>
                                    <?php
                                        $pct = (float)($p->profit_loss_percent ?? $p->daily_rate ?? 0);
                                        $net = (float)($p->net_amount ?? $p->amount ?? 0);
                                        $balanceAfter = (float)($p->balance_after ?? 0);
                                        $type = (string)($p->type ?? $p->profit_type ?? ($net >= 0 ? 'profit' : 'loss'));
                                    ?>
                                    <tr>
                                        <td><?= e((string)($p->period ?? $p->period_date ?? $p->date ?? '—')) ?></td>
                                        <td class="inv-num"><span class="inv-pct inv-pct--<?= $pct >= 0 ? 'up' : 'down' ?>"><?= $pct >= 0 ? '+' : '' ?><?= e((string)$pct) ?>%</span></td>
                                        <td class="inv-num <?= $net >= 0 ? 'inv-text-up' : 'inv-text-down' ?>"><?= $net >= 0 ? '+' : '' ?><?= number_format($net, 2) ?> USDT</td>
                                        <td class="inv-num"><?= number_format($balanceAfter, 2) ?></td>
                                        <td><span class="inv-badge <?= $type === 'profit' ? 'inv-badge--active' : 'inv-badge--danger' ?>"><?= $type === 'profit' ? 'سود' : 'زیان' ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section id="market" class="inv-section">
                <div class="inv-section__header">
                    <i class="material-icons">candlestick_chart</i>
                    <h2>آخرین معاملات بازار</h2>
                </div>
                <?php if (empty($recentTrades)): ?>
                    <div class="inv-section-empty">
                        <i class="material-icons">query_stats</i>
                        <strong>معامله‌ای برای نمایش وجود ندارد</strong>
                        <span>پس از بسته‌شدن معاملات بازار، آخرین داده‌ها در این بخش قرار می‌گیرد.</span>
                    </div>
                <?php else: ?>
                    <div class="inv-table-wrap">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>جفت ارز</th>
                                    <th>جهت</th>
                                    <th>قیمت باز</th>
                                    <th>قیمت بسته</th>
                                    <th>سود/زیان</th>
                                    <th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTrades as $t): ?>
                                    <?php
                                        $pair = $t->pair ?? $t->symbol ?? '—';
                                        $direction = $t->direction ?? $t->type ?? 'buy';
                                        $openPrice = $t->open_price ?? $t->price ?? 0;
                                        $closePrice = $t->close_price ?? $t->price ?? 0;
                                        $profitLossPercent = (float)($t->profit_loss_percent ?? $t->profit_loss ?? 0);
                                    ?>
                                    <tr>
                                        <td><strong><?= e((string)$pair) ?></strong></td>
                                        <td>
                                            <span class="inv-badge <?= $direction === 'buy' ? 'inv-badge--active' : 'inv-badge--danger' ?>">
                                                <i class="material-icons icon-xs"><?= $direction === 'buy' ? 'arrow_upward' : 'arrow_downward' ?></i>
                                                <?= $direction === 'buy' ? 'خرید' : 'فروش' ?>
                                            </span>
                                        </td>
                                        <td class="inv-num"><?= number_format((float)$openPrice, 2) ?></td>
                                        <td class="inv-num"><?= number_format((float)$closePrice, 2) ?></td>
                                        <td class="inv-num"><span class="inv-pct inv-pct--<?= $profitLossPercent >= 0 ? 'up' : 'down' ?>"><?= $profitLossPercent >= 0 ? '+' : '' ?><?= e((string)$profitLossPercent) ?>%</span></td>
                                        <td><?= to_jalali($t->close_time ?? $t->created_at ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userinvestment.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userinvestmentindex.js') . '"></script>';
include view_path('layouts.user');
?>
