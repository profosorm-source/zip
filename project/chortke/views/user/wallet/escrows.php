<?php
$title = $title ?? 'صندوق امانات مالی (اسکرو)';

ob_start();
?>
<div class="content-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="page-title mb-1">
            <span class="material-icons text-success align-middle">handshake</span> 
            صندوق امانات مالی و معاملات امن (Escrow)
        </h4>
        <p class="text-muted mb-0 small">قفل‌گذاری دوطرفه وجه تا زمان رضایت کامل خریدار و فروشنده</p>
    </div>
    <a href="<?= url('/wallet/escrow/create') ?>" class="btn btn-success btn-sm fw-bold px-3 shadow-sm">
        <span class="material-icons align-middle small me-1">add_security</span> ایجاد معامله امن جدید
    </a>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
        <h6 class="card-title fw-bold text-dark mb-0">معاملات امانی فعال و گذشته شما</h6>
    </div>
    <div class="card-body p-4">
        <?php if (empty($escrows)): ?>
            <div class="text-center py-5">
                <span class="material-icons text-muted fs-1 mb-3">lock_outline</span>
                <h6 class="text-muted fw-bold">هیچ معامله امنی در صندوق امانات شما یافت نشد</h6>
                <p class="text-muted small mb-0">با ایجاد معامله امن، وجه تا تحویل نهایی کالا یا پروژه در صندوق امانات سایت قفل می‌ماند.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-hover text-center">
                    <thead class="table-light small">
                        <tr>
                            <th>شناسه اسکرو</th>
                            <th>نوع معامله</th>
                            <th>طرف معامله</th>
                            <th>مبلغ امانی</th>
                            <th>ارز</th>
                            <th>وضعیت اتمیک</th>
                            <th>تاریخ ثبت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="small fw-medium text-dark">
                        <?php foreach ($escrows as $escrow): 
                            $isBuyer = (int)$escrow->buyer_id === (int)$user->id;
                            $otherName = $isBuyer ? ($escrow->seller_name ?? 'فروشنده') : ($escrow->buyer_name ?? 'خریدار');
                            $roleLabel = $isBuyer ? '<span class="badge bg-primary">خریدار (شما)</span>' : '<span class="badge bg-warning text-dark">فروشنده (شما)</span>';
                            
                            $stBadge = match($escrow->status ?? '') {
                                'in_escrow', 'pending' => '<span class="badge bg-warning text-dark"><i class="material-icons small align-middle">lock</i> قفل در صندوق</span>',
                                'released' => '<span class="badge bg-success"><i class="material-icons small align-middle">done_all</i> واریز به فروشنده</span>',
                                'refunded' => '<span class="badge bg-danger"><i class="material-icons small align-middle">assignment_return</i> استرداد به خریدار</span>',
                                'disputed' => '<span class="badge bg-dark"><i class="material-icons small align-middle">gavel</i> درحال داوری</span>',
                                default => '<span class="badge bg-secondary">' . e($escrow->status) . '</span>'
                            };
                        ?>
                            <tr>
                                <td class="fw-bold text-secondary">ESC-<?= e($escrow->id) ?></td>
                                <td><?= e($escrow->order_type) ?></td>
                                <td><?= e($otherName) ?> <?= $roleLabel ?></td>
                                <td class="fw-bold fs-6 text-success"><?= number_format((float)($escrow->amount ?? 0)) ?></td>
                                <td class="text-uppercase"><?= e($escrow->currency) ?></td>
                                <td><?= $stBadge ?></td>
                                <td class="text-muted" dir="ltr"><?= e(substr((string)($escrow->created_at ?? ''), 0, 16)) ?></td>
                                <td>
                                    <?php if ($isBuyer && in_array($escrow->status, ['in_escrow', 'pending'], true)): ?>
                                        <button class="btn btn-success btn-sm fw-bold px-3 shadow-sm btn-release-escrow" data-id="<?= e($escrow->id) ?>">
                                            <span class="material-icons align-middle small">key</span> آزادسازی وجه
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">نهایی</span>
                                    <?php endif; ?>
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
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userwalletescrows.js') . '"></script>';
include view_path('layouts.user');
?>