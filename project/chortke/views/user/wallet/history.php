<?php
$title = $title ?? 'تاریخچه تراکنش‌ها';
$hideSidebar = true;
$transactions = $transactions ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
$type = $type ?? '';
$currency = $currency ?? '';
$siteCurrency = $siteCurrency ?? 'both';

ob_start();
?>

<div class="fin-wrap">
    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">receipt_long</i></div>
            <div>
                <div class="fin-hero__eyebrow">Transaction History</div>
                <h1 class="fin-hero__title">تاریخچه تراکنش‌ها</h1>
                <p class="fin-hero__sub">همه واریزها، برداشت‌ها، پاداش‌ها و تراکنش‌های مالی خود را فیلتر و بررسی کنید.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز مالی</a>
            <a href="<?= url('/wallet/deposit') ?>" class="fin-btn fin-btn-primary"><i class="material-icons">add</i> افزایش موجودی</a>
        </div>
    </section>

    <div class="fin-hub-layout">
        <?php $activeSpoke = 'history'; include view_path('user.wallet._finance-nav'); ?>
        <main class="fin-hub-main">
            <section class="fin-form-card" style="margin-bottom:16px;">
                <div class="fin-form-card__head"><span><i class="material-icons">filter_alt</i> فیلتر تراکنش‌ها</span></div>
                <div class="fin-form-card__body">
                    <form method="GET" action="<?= url('/wallet/history') ?>">
                        <div class="fin-form-row">
                            <div class="fin-form-group">
                                <label>نوع تراکنش</label>
                                <select name="type" class="fin-form-control">
                                    <option value="">همه</option>
                                    <option value="deposit" <?= $type === 'deposit' ? 'selected' : '' ?>>واریز</option>
                                    <option value="withdraw" <?= $type === 'withdraw' ? 'selected' : '' ?>>برداشت</option>
                                    <option value="commission" <?= $type === 'commission' ? 'selected' : '' ?>>کمیسیون</option>
                                    <option value="task_reward" <?= $type === 'task_reward' ? 'selected' : '' ?>>پاداش تسک</option>
                                </select>
                            </div>
                            <div class="fin-form-group">
                                <label>نوع ارز</label>
                                <select name="currency" class="fin-form-control">
                                    <option value="">همه</option>
                                    <option value="irt" <?= $currency === 'irt' ? 'selected' : '' ?>>تومان</option>
                                    <option value="usdt" <?= $currency === 'usdt' ? 'selected' : '' ?>>USDT</option>
                                </select>
                            </div>
                        </div>
                        <div class="fin-actions">
                            <button type="submit" class="fin-btn fin-btn-primary"><i class="material-icons">search</i> اعمال فیلتر</button>
                            <?php if ($type || $currency): ?>
                                <a href="<?= url('/wallet/history') ?>" class="fin-btn fin-btn-secondary"><i class="material-icons">clear</i> حذف فیلتر</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>

            <section class="fin-section">
                <div class="fin-section__header">
                    <div class="fin-section__title"><i class="material-icons">table_rows</i> لیست تراکنش‌ها</div>
                    <span class="fin-badge fin-badge--info"><?= number_format(count($transactions)) ?> رکورد این صفحه</span>
                </div>

                <?php if (empty($transactions)): ?>
                    <div class="fin-empty">
                        <i class="material-icons">receipt_long</i>
                        <h3>تراکنشی یافت نشد</h3>
                        <p>هنوز تراکنشی مطابق فیلترهای انتخابی ثبت نشده است.</p>
                        <a href="<?= url('/wallet/deposit') ?>" class="fin-btn fin-btn-primary">افزایش موجودی</a>
                    </div>
                <?php else: ?>
                    <div class="fin-table-wrap">
                        <table class="fin-table">
                            <thead>
                                <tr>
                                    <th>شناسه</th>
                                    <th>نوع</th>
                                    <th>مبلغ</th>
                                    <th>ارز</th>
                                    <th>وضعیت</th>
                                    <th>درگاه</th>
                                    <th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $tx): ?>
                                    <?php
                                    $typeLabels = [
                                        'deposit' => 'واریز', 'withdraw' => 'برداشت', 'transfer' => 'انتقال',
                                        'commission' => 'کمیسیون', 'investment' => 'سرمایه‌گذاری',
                                        'task_reward' => 'پاداش تسک', 'penalty' => 'جریمه', 'refund' => 'بازگشت وجه',
                                    ];
                                    $statusMap = [
                                        'pending' => ['در انتظار', 'fin-badge--warning'],
                                        'processing' => ['در حال پردازش', 'fin-badge--info'],
                                        'completed' => ['تکمیل شده', 'fin-badge--success'],
                                        'failed' => ['ناموفق', 'fin-badge--danger'],
                                        'cancelled' => ['لغو شده', 'fin-badge--muted'],
                                        'refunded' => ['بازگشت داده شده', 'fin-badge--info'],
                                    ];
                                    [$statusLabel, $statusClass] = $statusMap[$tx->status ?? ''] ?? [($tx->status ?? '—'), 'fin-badge--muted'];
                                    $isNegative = in_array($tx->type ?? '', ['withdraw', 'penalty', 'investment'], true);
                                    $amount = (float)($tx->amount ?? 0);
                                    ?>
                                    <tr>
                                        <td><code><?= e(substr((string)($tx->transaction_id ?? '—'), 0, 10)) ?></code></td>
                                        <td><?= e($typeLabels[$tx->type ?? ''] ?? ($tx->type ?? '—')) ?></td>
                                        <td class="fin-num <?= $isNegative ? 'fin-text-down' : 'fin-text-up' ?>"><?= $isNegative ? '-' : '+' ?><?= ($tx->currency ?? '') === 'usdt' ? number_format(abs($amount), 4) : number_format(abs($amount)) ?></td>
                                        <td><span class="fin-badge <?= ($tx->currency ?? '') === 'usdt' ? 'fin-badge--success' : 'fin-badge--warning' ?>"><?= ($tx->currency ?? '') === 'usdt' ? 'USDT' : 'تومان' ?></span></td>
                                        <td><span class="fin-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                        <td><?= !empty($tx->gateway) ? e($tx->gateway) : '—' ?></td>
                                        <td><?= to_jalali($tx->created_at ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="fin-actions" style="padding:16px; justify-content:center;">
                            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                                <a href="?page=<?= e($i) ?><?= $type ? '&type=' . e($type) : '' ?><?= $currency ? '&currency=' . e($currency) : '' ?>" class="fin-btn <?= $i === $currentPage ? 'fin-btn-primary' : 'fin-btn-secondary' ?>"><?= e((string)$i) ?></a>
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
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">';
include view_path('layouts.user');
?>
