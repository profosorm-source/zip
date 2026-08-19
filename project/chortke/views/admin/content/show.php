<?php
/** @var object $submission */
/** @var array $revenues */
/** @var object|null $agreement */
$title = 'جزئیات محتوا #' . (int)$submission->id;
$activeAdminContent = 'submissions';
$revenues = $revenues ?? [];

$h = static fn($value): string => e((string)($value ?? ''));
$int = static fn($value): string => function_exists('fa_number') ? fa_number((int)$value) : number_format((int)$value);
$money = static fn($value): string => number_format((float)($value ?? 0));
$platformLabel = static fn($platform): string => match ((string)$platform) {
    'aparat' => 'آپارات',
    'youtube' => 'یوتیوب',
    'upload_center' => 'آپلود سنتر',
    default => (string)$platform ?: 'نامشخص',
};
$statusMeta = static fn($status): array => match ((string)$status) {
    'pending' => ['در انتظار بررسی', 'warning', 'hourglass_empty'],
    'under_review' => ['در حال بررسی', 'info', 'rate_review'],
    'approved' => ['تأیید شده', 'success', 'check_circle'],
    'published' => ['منتشر شده', 'primary', 'public'],
    'rejected' => ['رد شده', 'danger', 'cancel'],
    'suspended' => ['تعلیق شده', 'dark', 'block'],
    default => ['نامشخص', 'muted', 'help'],
};
$revenueMeta = static fn($status): array => match ((string)$status) {
    'pending' => ['در انتظار تأیید', 'warning', 'hourglass_empty'],
    'approved' => ['آماده پرداخت', 'info', 'verified'],
    'paid' => ['پرداخت شده', 'success', 'paid'],
    'cancelled' => ['لغو شده', 'danger', 'cancel'],
    default => ['نامشخص', 'muted', 'help'],
};
$status = (string)($submission->status ?? '');
$meta = $statusMeta($status);

ob_start();
?>
<div id="contentRoot" class="ac-page"
     data-base="<?= e(url('/admin/content')) ?>"
     data-revenue-base="<?= e(url('/admin/content/revenue')) ?>">

    <section class="ac-hero compact">
        <div>
            <div class="ac-kicker"><span class="material-icons">movie</span> جزئیات محتوا</div>
            <h1><?= $h($submission->title ?? 'بدون عنوان') ?></h1>
            <p>وضعیت، تعهدنامه، لینک انتشار و تاریخچه درآمد این محتوا را از این صفحه مدیریت کنید.</p>
        </div>
        <div class="ac-hero-actions">
            <a href="<?= e(url('/admin/content')) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">arrow_back</span> بازگشت</a>
            <?php if ($status === 'published'): ?>
                <a href="<?= e(url('/admin/content/' . (int)$submission->id . '/revenue/create')) ?>" class="ac-btn ac-btn-primary"><span class="material-icons">add_chart</span> ثبت درآمد</a>
            <?php endif; ?>
        </div>
    </section>

    <?php include view_path('admin.content._admin-nav'); ?>

    <section class="ac-detail-grid">
        <article class="ac-card ac-detail-main">
            <div class="ac-card-head">
                <div>
                    <h2>اطلاعات محتوا</h2>
                    <p>شناسه #<?= $int($submission->id ?? 0) ?> · <?= $h(!empty($submission->created_at) ? to_jalali($submission->created_at) : '—') ?></p>
                </div>
                <span class="ac-badge <?= e($meta[1]) ?>"><i class="material-icons"><?= e($meta[2]) ?></i><?= e($meta[0]) ?></span>
            </div>

            <div class="ac-info-grid">
                <div><small>کاربر</small><strong><a href="<?= e(url('/admin/users/' . (int)($submission->user_id ?? 0) . '/edit')) ?>"><?= $h($submission->user_name ?? 'نامشخص') ?></a></strong></div>
                <div><small>ایمیل</small><strong><?= $h($submission->user_email ?? '—') ?></strong></div>
                <div><small>پلتفرم</small><strong><?= e($platformLabel($submission->platform ?? '')) ?></strong></div>
                <div><small>دسته‌بندی</small><strong><?= $h($submission->category ?? '—') ?></strong></div>
                <div><small>تاریخ تأیید</small><strong><?= $h(!empty($submission->approved_at) ? to_jalali($submission->approved_at) : '—') ?></strong></div>
                <div><small>تاریخ انتشار</small><strong><?= $h(!empty($submission->published_at) ? to_jalali($submission->published_at) : '—') ?></strong></div>
                <div class="wide"><small>لینک ارسالی کاربر</small><strong><a href="<?= $h($submission->video_url ?? $submission->url ?? '#') ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= $h($submission->video_url ?? $submission->url ?? '—') ?></a></strong></div>
                <?php if (!empty($submission->published_url)): ?>
                    <div class="wide"><small>لینک منتشرشده</small><strong><a href="<?= $h($submission->published_url) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= $h($submission->published_url) ?></a></strong></div>
                <?php endif; ?>
                <?php if (!empty($submission->channel_name)): ?>
                    <div><small>نام کانال</small><strong><?= $h($submission->channel_name) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($submission->description)): ?>
                <div class="ac-note"><strong>توضیحات کاربر</strong><p><?= nl2br($h($submission->description)) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($submission->rejection_reason)): ?>
                <div class="ac-note danger"><strong>دلیل رد یا تعلیق</strong><p><?= nl2br($h($submission->rejection_reason)) ?></p></div>
            <?php endif; ?>

            <div class="ac-action-bar">
                <?php if (in_array($status, ['pending', 'under_review'], true)): ?>
                    <button type="button" class="ac-btn ac-btn-success" data-click="approveContent" data-args="<?= (int)$submission->id ?>"><span class="material-icons">check</span> تأیید محتوا</button>
                    <button type="button" class="ac-btn ac-btn-danger" data-click="rejectContent" data-args="<?= (int)$submission->id ?>"><span class="material-icons">close</span> رد محتوا</button>
                <?php endif; ?>
                <?php if ($status === 'approved'): ?>
                    <button type="button" class="ac-btn ac-btn-primary" data-click="publishContent" data-args="<?= (int)$submission->id ?>"><span class="material-icons">public</span> ثبت انتشار</button>
                <?php endif; ?>
                <?php if (in_array($status, ['approved', 'published'], true)): ?>
                    <button type="button" class="ac-btn ac-btn-dark" data-click="suspendContent" data-args="<?= (int)$submission->id ?>"><span class="material-icons">block</span> تعلیق محتوا</button>
                <?php endif; ?>
            </div>
        </article>

        <aside class="ac-side-stack">
            <article class="ac-card ac-mini-card">
                <span class="material-icons">policy</span>
                <h3>قانون درآمد</h3>
                <p>درآمد فقط برای محتوای منتشرشده و پس از گذشت حداقل ۲ ماه از تأیید کاربر قابل ثبت است.</p>
            </article>
            <?php if ($agreement): ?>
                <article class="ac-card ac-agreement-card">
                    <div class="ac-card-head small"><h2><span class="material-icons">gavel</span> تعهدنامه</h2></div>
                    <div class="ac-agreement-text"><?= nl2br($h($agreement->agreement_text ?? '')) ?></div>
                    <div class="ac-agreement-meta">
                        <span>IP: <?= $h($agreement->ip_address ?? '—') ?></span>
                        <span>تاریخ: <?= $h(!empty($agreement->accepted_at) ? to_jalali($agreement->accepted_at) : '—') ?></span>
                    </div>
                </article>
            <?php endif; ?>
        </aside>
    </section>

    <section class="ac-card">
        <div class="ac-card-head">
            <div>
                <h2>تاریخچه درآمد</h2>
                <p>هر دوره ابتدا ثبت، سپس تأیید و در پایان به کیف پول کاربر پرداخت می‌شود.</p>
            </div>
            <?php if ($status === 'published'): ?>
                <a href="<?= e(url('/admin/content/' . (int)$submission->id . '/revenue/create')) ?>" class="ac-btn ac-btn-primary"><span class="material-icons">add</span> ثبت درآمد جدید</a>
            <?php endif; ?>
        </div>

        <?php if (empty($revenues)): ?>
            <div class="ac-empty compact"><span class="material-icons">payments</span><p>هنوز درآمدی برای این محتوا ثبت نشده است.</p></div>
        <?php else: ?>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>دوره</th><th>بازدید</th><th>درآمد کل</th><th>سهم سایت</th><th>سهم کاربر</th><th>مالیات</th><th>خالص</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($revenues as $rev): $rm = $revenueMeta($rev->status ?? ''); ?>
                        <tr>
                            <td><?= $h($rev->period ?? '—') ?></td>
                            <td><?= $int($rev->views ?? 0) ?></td>
                            <td><?= $money($rev->total_revenue ?? 0) ?></td>
                            <td><?= $money($rev->site_share_amount ?? 0) ?> <small>(<?= $h($rev->site_share_percent ?? 0) ?>٪)</small></td>
                            <td><?= $money($rev->user_share_amount ?? 0) ?> <small>(<?= $h($rev->user_share_percent ?? 0) ?>٪)</small></td>
                            <td><?= $money($rev->tax_amount ?? 0) ?></td>
                            <td><strong><?= $money($rev->net_user_amount ?? 0) ?></strong></td>
                            <td><span class="ac-badge <?= e($rm[1]) ?>"><i class="material-icons"><?= e($rm[2]) ?></i><?= e($rm[0]) ?></span></td>
                            <td><div class="ac-actions">
                                <?php if (($rev->status ?? '') === 'pending'): ?>
                                    <button type="button" class="ac-btn ac-btn-success sm" data-click="approveRevenue" data-args="<?= (int)$rev->id ?>">تأیید</button>
                                <?php elseif (($rev->status ?? '') === 'approved'): ?>
                                    <button type="button" class="ac-btn ac-btn-primary sm" data-click="payRevenue" data-args="<?= (int)$rev->id ?>">پرداخت</button>
                                <?php else: ?>
                                    <span class="ac-muted">—</span>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admincontent.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/content.js') . '"></script>';
include view_path('layouts.admin');
?>
