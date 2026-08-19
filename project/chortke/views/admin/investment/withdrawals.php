<?php
$title = 'برداشت‌های سرمایه‌گذاری';

ob_start();
?>
<div id="investmentRoot" data-base="<?= url('/admin/investment') ?>" data-withdrawals-base="<?= url('/admin/investment/withdrawals') ?>" data-apply-url="<?= url('/admin/investment/apply-profit') ?>" data-trades-base="<?= url('/admin/investment/trades') ?>" data-trades-store="<?= url('/admin/investment/trades/store') ?>" data-trades-list="<?= url('/admin/investment/trades') ?>"></div>
<?php
$withdrawals = $withdrawals ?? [];
$total = $total ?? 0;
$totalPages = $totalPages ?? 1;
$currentPage = $currentPage ?? 1;
$filters = $filters ?? [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">برداشت‌های سرمایه‌گذاری <span class="badge bg-secondary ms-2"><?= number_format($total) ?></span></h4>
    <a href="<?= url('/admin/investment') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons align-middle">arrow_forward</i> بازگشت
    </a>
</div>

<!-- فیلتر -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm">
                <option value="">همه وضعیت‌ها</option>
                <option value="pending"  <?= ($filters['status'] ?? '') === 'pending'  ? 'selected' : '' ?>>در انتظار</option>
                <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>تأیید شده</option>
                <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد شده</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <?php if (!empty($filters['status'])): ?>
                <a href="<?= url('/admin/investment/withdrawals') ?>" class="btn btn-outline-secondary btn-sm">حذف فیلتر</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- جدول -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>کاربر</th>
                    <th>مبلغ (USDT)</th>
                    <th>تاریخ درخواست</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($withdrawals as $w): ?>
                <tr>
                    <td><?= (int)$w->id ?></td>
                    <td>
                        <a href="<?= url('/admin/users/' . (int)($w->user_id ?? 0)) ?>">
                            <?= e($w->full_name ?? $w->email ?? 'کاربر #' . (int)$w->user_id) ?>
                        </a>
                    </td>
                    <td class="fw-bold font-monospace"><?= number_format((float)($w->amount ?? 0), 4) ?></td>
                    <td><?= to_jalali($w->created_at) ?></td>
                    <td>
                        <?php
                        $stMap = [
                            'pending'  => ['در انتظار', 'warning'],
                            'approved' => ['تأیید شده', 'success'],
                            'rejected' => ['رد شده',    'danger'],
                        ];
                        $si = $stMap[$w->status ?? ''] ?? [e($w->status ?? '—'), 'secondary'];
                        ?>
                        <span class="badge bg-<?= e($si[1]) ?>"><?= e($si[0]) ?></span>
                    </td>
                    <td>
                        <?php if (($w->status ?? '') === 'pending'): ?>
                        <button class="btn btn-success btn-sm" data-click="doApprove" data-args="<?= (int)$w->id ?>">
                            <i class="material-icons align-middle">check</i> تأیید
                        </button>
                        <button class="btn btn-danger btn-sm" data-click="doReject" data-args="<?= (int)$w->id ?>">
                            <i class="material-icons align-middle">close</i> رد
                        </button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($withdrawals)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">هیچ درخواستی یافت نشد</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex gap-1 justify-content-center">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= e($i) ?><?= !empty($filters['status']) ? '&status=' . e($filters['status']) : '' ?>"
               class="btn btn-sm <?= $i === $currentPage ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= e($i) ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>



<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>

