<?php
// views/admin/task-rechecks/index.php
$title = 'بررسی مجدد تسک‌ها';

ob_start();
?>


<div class="page-header">
    <h4><i class="material-icons">verified</i> بررسی مجدد تسک‌ها</h4>
</div>

<div class="alert-box alert-info mb-15">
    <i class="material-icons">info</i>
    <div>
        این بخش تسک‌هایی را نشان می‌دهد که هر ۷ روز بررسی می‌شوند.
        اگر کاربر هنوز فالو/سابسکرایب دارد → <strong>تایید</strong>.
        اگر آنفالو کرده → <strong>جریمه</strong> و بازگشت پول به سفارش‌دهنده.
    </div>
</div>

<div class="filter-card">
    <form method="GET" action="<?= url('/admin/task-rechecks') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه</option>
            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
            <option value="passed" <?= ($filters['status'] ?? '') === 'passed' ? 'selected' : '' ?>>تایید</option>
            <option value="failed" <?= ($filters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>شکست</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تسک</th>
                <th>انجام‌دهنده</th>
                <th>جریمه</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rechecks as $rc): ?>
                <tr>
                    <td><?= e($rc->id) ?></td>
                    <td><?= e($rc->ad_title ?? '—') ?></td>
                    <td><?= e($rc->executor_name ?? '—') ?></td>
                    <td><?= $rc->penalty_amount > 0 ? number_format($rc->penalty_amount) : '—' ?></td>
                    <td>
                        <?php
                        $rcLabels = ['pending' => 'در انتظار', 'passed' => 'تایید', 'failed' => 'شکست'];
                        $rcBadges = ['pending' => 'warning', 'passed' => 'success', 'failed' => 'danger'];
                        ?>
                        <span class="badge badge-<?= $rcBadges[$rc->status] ?? 'secondary' ?>"><?= e($rcLabels[$rc->status] ?? $rc->status) ?></span>
                    </td>
                    <td><?= to_jalali($rc->created_at) ?></td>
                    <td>
                        <?php if ($rc->status === 'pending'): ?>
                            <button class="btn btn-xs btn-success btn-rc-pass" data-id="<?= e($rc->id) ?>">
                                <i class="material-icons">check</i> هنوز فالو دارد
                            </button>
                            <button class="btn btn-xs btn-danger btn-rc-fail" data-id="<?= e($rc->id) ?>">
                                <i class="material-icons">close</i> آنفالو کرده
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>




<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
