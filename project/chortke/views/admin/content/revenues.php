<?php
$title = 'درآمدهای محتوایی';
$activeAdminContent = 'revenues';
$revenues = $revenues ?? [];
$filters = $filters ?? [];
$total = (int)($total ?? 0);
$currentPage = (int)($currentPage ?? 1);
$totalPages = (int)($totalPages ?? 1);
$financialStats = $financialStats ?? (object)[];

$h = static fn($value): string => e((string)($value ?? ''));
$int = static fn($value): string => function_exists('fa_number') ? fa_number((int)$value) : number_format((int)$value);
$money = static fn($value): string => number_format((float)($value ?? 0));
$statusMeta = static fn($status): array => match ((string)$status) {
    'pending' => ['در انتظار تأیید', 'warning', 'hourglass_empty'],
    'approved' => ['آماده پرداخت', 'info', 'verified'],
    'paid' => ['پرداخت شده', 'success', 'paid'],
    'cancelled' => ['لغو شده', 'danger', 'cancel'],
    default => ['نامشخص', 'muted', 'help'],
};

ob_start();
?>
<div id="contentRoot" class="ac-page"
     data-base="<?= e(url('/admin/content')) ?>"
     data-revenue-base="<?= e(url('/admin/content/revenue')) ?>">

    <section class="ac-hero compact">
        <div>
            <div class="ac-kicker"><span class="material-icons">payments</span> درآمد محتوا</div>
            <h1>مدیریت درآمدها و پرداخت‌ها</h1>
            <p>درآمدهای ثبت‌شده را بررسی، تأیید و پس از تأیید به کیف پول کاربران پرداخت کنید.</p>
        </div>
        <div class="ac-hero-actions">
            <a href="<?= e(url('/admin/content')) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">video_library</span> محتواها</a>
        </div>
    </section>

    <?php include view_path('admin.content._admin-nav'); ?>

    <section class="ac-stats-grid four" aria-label="آمار درآمد محتوا">
        <article class="ac-stat-card"><span class="ac-stat-icon gold"><i class="material-icons">receipt_long</i></span><div><small>کل رکوردها</small><strong><?= $int($financialStats->total_records ?? $total) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon primary"><i class="material-icons">account_balance</i></span><div><small>درآمد کل</small><strong><?= $money($financialStats->total_revenue ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon warning"><i class="material-icons">pending_actions</i></span><div><small>در انتظار پرداخت</small><strong><?= $money($financialStats->pending_amount ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon success"><i class="material-icons">paid</i></span><div><small>پرداخت‌شده</small><strong><?= $money($financialStats->paid_amount ?? 0) ?></strong></div></article>
    </section>

    <section class="ac-card ac-filter-card">
        <form method="GET" class="ac-filter-form">
            <select name="status" aria-label="فیلتر وضعیت درآمد">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach (['pending' => 'در انتظار تأیید', 'approved' => 'آماده پرداخت', 'paid' => 'پرداخت شده'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="ac-input-wrap period"><span class="material-icons">calendar_month</span><input type="text" name="period" value="<?= $h($filters['period'] ?? '') ?>" placeholder="دوره مثل 1404-01" dir="ltr" aria-label="فیلتر دوره"></div>
            <button type="submit" class="ac-btn ac-btn-primary"><span class="material-icons">filter_alt</span> اعمال فیلتر</button>
            <a href="<?= e(url('/admin/content/revenues')) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">restart_alt</span> پاک‌سازی</a>
            <span class="ac-result-count"><?= $int($total) ?> رکورد</span>
        </form>
    </section>

    <section class="ac-card">
        <div class="ac-card-head">
            <div>
                <h2>فهرست درآمدها</h2>
                <p>برای پرداخت، ابتدا رکورد درآمد باید تأیید شود؛ پرداخت دوباره به‌صورت اتمیک کنترل می‌شود.</p>
            </div>
        </div>

        <?php if (empty($revenues)): ?>
            <div class="ac-empty"><span class="material-icons">payments</span><h3>درآمدی یافت نشد</h3><p>هنوز رکوردی با فیلترهای فعلی ثبت نشده است.</p></div>
        <?php else: ?>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>شناسه</th><th>محتوا و کاربر</th><th>دوره</th><th>بازدید</th><th>درآمد کل</th><th>خالص کاربر</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($revenues as $row):
                        $meta = $statusMeta($row->status ?? '');
                        $contentTitle = $row->title ?? $row->video_title ?? 'بدون عنوان';
                    ?>
                        <tr>
                            <td class="ac-id">#<?= $int($row->id ?? 0) ?></td>
                            <td>
                                <div class="ac-title-cell">
                                    <a href="<?= e(url('/admin/content/' . (int)($row->submission_id ?? 0))) ?>"><?= $h(mb_substr((string)$contentTitle, 0, 65)) ?><?= mb_strlen((string)$contentTitle) > 65 ? '…' : '' ?></a>
                                    <small><span class="material-icons">person</span> <?= $h($row->user_name ?? 'نامشخص') ?></small>
                                </div>
                            </td>
                            <td dir="ltr"><?= $h($row->period ?? '—') ?></td>
                            <td><?= $int($row->views ?? 0) ?></td>
                            <td><?= $money($row->total_revenue ?? 0) ?></td>
                            <td><strong><?= $money($row->net_user_amount ?? 0) ?></strong></td>
                            <td><span class="ac-badge <?= e($meta[1]) ?>"><i class="material-icons"><?= e($meta[2]) ?></i><?= e($meta[0]) ?></span></td>
                            <td>
                                <div class="ac-actions">
                                    <a href="<?= e(url('/admin/content/' . (int)($row->submission_id ?? 0))) ?>" class="ac-icon-btn" title="مشاهده محتوا"><span class="material-icons">visibility</span></a>
                                    <?php if (($row->status ?? '') === 'pending'): ?>
                                        <button type="button" class="ac-icon-btn success" data-click="approveRevenue" data-args="<?= (int)$row->id ?>" title="تأیید درآمد"><span class="material-icons">check_circle</span></button>
                                    <?php elseif (($row->status ?? '') === 'approved'): ?>
                                        <button type="button" class="ac-icon-btn primary" data-click="payRevenue" data-args="<?= (int)$row->id ?>" title="پرداخت"><span class="material-icons">paid</span></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="ac-pagination" aria-label="صفحه‌بندی درآمدها">
                    <?php for ($i = 1; $i <= $totalPages; $i++):
                        $query = array_filter([
                            'status' => $filters['status'] ?? '',
                            'period' => $filters['period'] ?? '',
                            'page' => $i,
                        ], static fn($v): bool => $v !== '' && $v !== null);
                    ?>
                        <a href="<?= e(url('/admin/content/revenues?' . http_build_query($query))) ?>" class="<?= $i === $currentPage ? 'active' : '' ?>" <?= $i === $currentPage ? 'aria-current="page"' : '' ?>><?= $int($i) ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admincontent.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/content.js') . '"></script>';
include view_path('layouts.admin');
?>
