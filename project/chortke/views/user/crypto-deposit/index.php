<?php
$pageTitle = $pageTitle ?? 'واریزهای USDT';
$deposits = $deposits ?? [];
ob_start();
?>

<div class="main-content">
    <div class="content-header">
        <h1>واریزهای USDT</h1>
        <a href="<?= url('/crypto-deposit/create') ?>" class="btn btn-primary">
            <i class="material-icons">add_circle</i>
            واریز جدید
        </a>
    </div>

    <?php if (empty($deposits)): ?>
    <div class="empty-state-card">
        <i class="material-icons">currency_bitcoin</i>
        <h3>واریز رمزارزی ندارید</h3>
        <p>برای افزایش موجودی USDT واریز جدید ثبت کنید.</p>
        <a href="<?= url('/crypto-deposit/create') ?>" class="btn btn-primary">واریز USDT</a>
    </div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مقدار (USDT)</th>
                        <th>شبکه</th>
                        <th>TxHash</th>
                        <th>تاریخ</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deposits as $d): ?>
                    <tr>
                        <td><?= (int)$d->id ?></td>
                        <td><strong><?= number_format((float)($d->amount ?? $d->declared_amount ?? 0), 4) ?></strong></td>
                        <td>
                            <span class="badge badge-info"><?= strtoupper(e($d->network ?? '—')) ?></span>
                        </td>
                        <td class="ltr-text">
                            <?php if (!empty($d->tx_hash)): ?>
                                <?= e(substr($d->tx_hash, 0, 16)) ?>...
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= to_jalali($d->created_at) ?></td>
                        <td>
                            <?php
                            $statusMap = [
                                'pending'       => ['label' => 'در انتظار',         'class' => 'badge-warning'],
                                'pending_review'=> ['label' => 'در بررسی',          'class' => 'badge-info'],
                                'confirmed'     => ['label' => 'تأیید شده',         'class' => 'badge-success'],
                                'rejected'      => ['label' => 'رد شده',            'class' => 'badge-danger'],
                                'expired'       => ['label' => 'منقضی شده',         'class' => 'badge-secondary'],
                            ];
                            $st = $statusMap[$d->status ?? ''] ?? ['label' => e($d->status ?? '—'), 'class' => 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>



$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercryptodepositindex.css') . '">';
$content = ob_get_clean(); include view_path('layouts.user');?>