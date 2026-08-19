<?php
$pageTitle = $pageTitle ?? 'بررسی پرداخت‌های آنلاین معلق';
$payments = $payments ?? [];

ob_start();
?>

<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>مدیریت پرداخت‌های آنلاین معلق (Pending Verification)</h1>
        <div class="header-stats">
            <div class="stat-badge pending">
                <i class="material-icons">schedule</i>
                <span><?= count($payments) ?> تراکنش در انتظار بررسی</span>
            </div>
        </div>
    </div>

    <!-- جدول -->
    <div class="table-card">
        <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="material-icons">credit_card</i>
            <h3>پرداخت معلقی یافت نشد</h3>
            <p>همه تراکنش‌های آنلاین با موفقیت تایید شده‌اند و هیچ موردی در صف بررسی دستی نیست.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>شناسه پرداخت</th>
                        <th>کاربر</th>
                        <th>درگاه</th>
                        <th>کارت بانکی</th>
                        <th>مبلغ تراکنش</th>
                        <th>شناسه مرجع (Authority)</th>
                        <th>تاریخ ایجاد</th>
                        <th>خطای درگاه (آخرین وضعیت)</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <?php 
                        $resData = @json_decode($pay->response_data ?? '', true) ?: [];
                        $attempts = $resData['verification_attempts'] ?? 0;
                        $lastError = $resData['verification_error'] ?? 'خطای ناشناخته در اتصال درگاه';
                    ?>
                    <tr>
                        <td>
                            <code>#<?= e($pay->id) ?></code>
                        </td>
                        <td>
                            <div class="user-info">
                                <strong>شناسه کاربر: <?= e($pay->user_id) ?></strong>
                                <small ><?= e($pay->email ?? $pay->mobile ?? '') ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge">
                                <?= strtoupper(e($pay->gateway)) ?>
                            </span>
                        </td>
                        <td>
                            <code class="card-number">
                                ****-****-****-<?= e($pay->card_last4 ?? '****') ?>
                            </code>
                        </td>
                        <td>
                            <span class="amount-badge">
                                <?= number_format((float)$pay->amount) ?> تومان
                            </span>
                        </td>
                        <td>
                            <code class="tracking-code"><?= e($pay->authority) ?></code>
                        </td>
                        <td>
                            <span class="date-badge" dir="ltr"><?= e($pay->created_at) ?></span>
                        </td>
                        <td>
                            <div title="<?= e($lastError) ?>">
                                <span class="text-danger">
                                    <i class="material-icons">error_outline</i>
                                    <?= e($lastError) ?>
                                </span>
                                <?php if ($attempts > 0): ?>
                                <small class="text-muted">(تعداد تلاش: <?= $attempts ?>)</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-success" data-click="verifyPayment" data-args="<?= e($pay->id) ?>|<?= e($pay->authority) ?>">
                                    <i class="material-icons">check_circle</i>
                                    استعلام و تأیید
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>




<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>

