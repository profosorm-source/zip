<?php
$title = 'درخواست‌های برداشت';
$hideSidebar = true;
$withdrawals = $withdrawals ?? [];
ob_start();
?>

<div class="fin-wrap">
    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">pending_actions</i></div>
            <div>
                <div class="fin-hero__eyebrow">Withdrawal Requests</div>
                <h1 class="fin-hero__title">درخواست‌های برداشت</h1>
                <p class="fin-hero__sub">وضعیت برداشت‌های تومانی و رمزارزی خود را از این بخش پیگیری کنید.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز مالی</a>
            <a href="<?= url('/wallet/withdraw') ?>" class="fin-btn fin-btn-primary"><i class="material-icons">add_circle</i> درخواست جدید</a>
        </div>
    </section>

    <div class="fin-hub-layout">
        <?php $activeSpoke = 'withdrawals'; include view_path('user.wallet._finance-nav'); ?>
        <main class="fin-hub-main">
            <section class="fin-section">
                <div class="fin-section__header">
                    <div class="fin-section__title"><i class="material-icons">payments</i> لیست برداشت‌ها</div>
                    <span class="fin-badge fin-badge--info"><?= number_format(count($withdrawals)) ?> درخواست</span>
                </div>

                <?php if (empty($withdrawals)): ?>
                    <div class="fin-empty">
                        <i class="material-icons">payments</i>
                        <h3>هنوز درخواست برداشتی ندارید</h3>
                        <p>برای برداشت وجه از کیف پول خود، درخواست جدید ثبت کنید.</p>
                        <a href="<?= url('/wallet/withdraw') ?>" class="fin-btn fin-btn-primary">درخواست برداشت</a>
                    </div>
                <?php else: ?>
                    <div class="fin-table-wrap">
                        <table class="fin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>مبلغ</th>
                                    <th>ارز</th>
                                    <th>مقصد</th>
                                    <th>تاریخ</th>
                                    <th>وضعیت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($withdrawals as $w): ?>
                                    <?php
                                    $statusMap = [
                                        'pending'    => ['در انتظار', 'fin-badge--warning'],
                                        'processing' => ['در حال پردازش', 'fin-badge--info'],
                                        'completed'  => ['تکمیل شده', 'fin-badge--success'],
                                        'rejected'   => ['رد شده', 'fin-badge--danger'],
                                    ];
                                    [$statusLabel, $statusClass] = $statusMap[$w->status ?? ''] ?? [($w->status ?? '—'), 'fin-badge--muted'];
                                    $currency = strtolower((string)($w->currency ?? 'irt'));
                                    ?>
                                    <tr>
                                        <td class="fin-num"><?= (int)($w->id ?? 0) ?></td>
                                        <td class="fin-num fin-text-down"><?= $currency === 'usdt' ? number_format((float)($w->amount ?? 0), 4) : number_format((float)($w->amount ?? 0)) ?></td>
                                        <td><span class="fin-badge <?= $currency === 'usdt' ? 'fin-badge--success' : 'fin-badge--warning' ?>"><?= $currency === 'usdt' ? 'USDT' : 'تومان' ?></span></td>
                                        <td>
                                            <?php if (!empty($w->card_number)): ?>
                                                <i class="material-icons" style="font-size:15px;vertical-align:middle;">credit_card</i>
                                                <?= e(strlen($w->card_number) >= 8 ? substr($w->card_number,0,4).'  ****  ****  '.substr($w->card_number,-4) : $w->card_number) ?> <?= !empty($w->bank_name) ? '(' . e($w->bank_name) . ')' : '' ?>
                                            <?php elseif (!empty($w->wallet_address)): ?>
                                                <i class="material-icons" style="font-size:15px;vertical-align:middle;">currency_bitcoin</i>
                                                <?= e(substr($w->wallet_address, 0, 12)) ?>...
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= to_jalali($w->created_at ?? '') ?></td>
                                        <td>
                                            <span class="fin-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                            <?php if (!empty($w->reject_reason) && ($w->status ?? '') === 'rejected'): ?>
                                                <small class="fin-text-down" style="display:block;margin-top:5px;"><?= e($w->reject_reason) ?></small>
                                            <?php endif; ?>
                                        </td>
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
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">';
include view_path('layouts.user');
?>
