<?php
$title = 'ثبت درآمد محتوا';
$activeAdminContent = 'revenues';
$settings = $settings ?? [];
$h = static fn($value): string => e((string)($value ?? ''));
$platformLabel = static fn($platform): string => match ((string)$platform) {
    'aparat' => 'آپارات',
    'youtube' => 'یوتیوب',
    'upload_center' => 'آپلود سنتر',
    default => (string)$platform ?: 'نامشخص',
};
$sitePercent = (float)($settings['site_share_percent'] ?? 40);
$taxPercent = (float)($settings['tax_percent'] ?? 9);
$userPercent = max(0, 100 - $sitePercent);
$isPublished = (($submission->status ?? '') === 'published');
$activeMonths = (int)($activeMonths ?? 0);
$minMonths = (int)($settings['min_months'] ?? 2);
$idempotencyKey = 'content_revenue_' . (int)$submission->id . '_' . bin2hex(random_bytes(8));

ob_start();
?>
<div id="contentRoot" class="ac-page"
     data-base="<?= e(url('/admin/content')) ?>"
     data-revenue-base="<?= e(url('/admin/content/revenue')) ?>"
     data-site-percent="<?= e((string)$sitePercent) ?>"
     data-tax-percent="<?= e((string)$taxPercent) ?>"
     data-revenue-store="<?= e(url('/admin/content/' . (int)$submission->id . '/revenue/store')) ?>"
     data-revenue-redirect="<?= e(url('/admin/content/' . (int)$submission->id)) ?>">

    <section class="ac-hero compact">
        <div>
            <div class="ac-kicker"><span class="material-icons">add_chart</span> درآمد دوره‌ای</div>
            <h1>ثبت درآمد برای محتوا</h1>
            <p>مبلغ کل درآمد دوره را وارد کنید؛ سهم سایت، سهم کاربر، مالیات و مبلغ خالص به‌صورت خودکار محاسبه می‌شود.</p>
        </div>
        <div class="ac-hero-actions">
            <a href="<?= e(url('/admin/content/' . (int)$submission->id)) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">arrow_back</span> بازگشت به محتوا</a>
        </div>
    </section>

    <?php include view_path('admin.content._admin-nav'); ?>

    <section class="ac-detail-grid revenue-create">
        <article class="ac-card">
            <div class="ac-card-head">
                <div>
                    <h2><?= $h($submission->title ?? 'بدون عنوان') ?></h2>
                    <p><?= e($platformLabel($submission->platform ?? '')) ?> · کاربر: <?= $h($submission->user_name ?? 'نامشخص') ?></p>
                </div>
                <span class="ac-badge <?= $isPublished ? 'primary' : 'warning' ?>"><i class="material-icons"><?= $isPublished ? 'public' : 'warning' ?></i><?= $isPublished ? 'منتشر شده' : 'غیرقابل ثبت درآمد' ?></span>
            </div>

            <?php if (!$isPublished): ?>
                <div class="ac-note warning"><strong>ثبت درآمد فعال نیست</strong><p>درآمد فقط برای محتوایی ثبت می‌شود که وضعیت آن «منتشر شده» باشد.</p></div>
            <?php endif; ?>

            <form id="revenueForm" class="ac-form">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="submission_id" value="<?= (int)$submission->id ?>">
                <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">

                <div class="ac-form-grid">
                    <label>
                        <span>دوره درآمد <b>*</b></span>
                        <input type="text" name="period" id="period" dir="ltr" placeholder="1404-01" maxlength="7" required <?= !$isPublished ? 'disabled' : '' ?>>
                        <small>فرمت پیشنهادی: سال-ماه شمسی، مثل 1404-01</small>
                    </label>
                    <label>
                        <span>تعداد بازدید <b>*</b></span>
                        <input type="number" name="views" id="views" min="0" step="1" required <?= !$isPublished ? 'disabled' : '' ?>>
                        <small>تعداد بازدید معتبر همان دوره</small>
                    </label>
                    <label>
                        <span>درآمد کل (<?= setting('currency_mode', 'irt') === 'usdt' ? 'تتر' : 'تومان' ?>) <b>*</b></span>
                        <input type="number" name="total_revenue" id="total_revenue" min="0" step="0.01" required <?= !$isPublished ? 'disabled' : '' ?>>
                        <small>مبلغ کل درآمد قبل از تقسیم سهم‌ها</small>
                    </label>
                </div>

                <div id="calcPreview" class="calc-preview">
                    <div class="calc-head"><span class="material-icons">calculate</span><strong>پیش‌نمایش محاسبه</strong></div>
                    <div class="calc-grid">
                        <div class="calc-item"><small>سهم سایت (<span id="prevSitePercent"><?= e((string)$sitePercent) ?></span>٪)</small><strong id="prevSiteAmount">0</strong></div>
                        <div class="calc-item"><small>سهم کاربر (<span id="prevUserPercent"><?= e((string)$userPercent) ?></span>٪)</small><strong id="prevUserAmount">0</strong></div>
                        <div class="calc-item"><small>مالیات (<span id="prevTaxPercent"><?= e((string)$taxPercent) ?></span>٪)</small><strong id="prevTaxAmount">0</strong></div>
                        <div class="calc-item highlight"><small>خالص قابل پرداخت</small><strong id="prevNetAmount">0</strong></div>
                    </div>
                </div>

                <div class="ac-action-bar">
                    <button type="submit" id="submitBtn" class="ac-btn ac-btn-primary" <?= !$isPublished ? 'disabled' : '' ?>><span class="material-icons">save</span> ثبت درآمد</button>
                    <a href="<?= e(url('/admin/content/' . (int)$submission->id)) ?>" class="ac-btn ac-btn-ghost">انصراف</a>
                </div>
            </form>
        </article>

        <aside class="ac-side-stack">
            <article class="ac-card ac-mini-card">
                <span class="material-icons">percent</span>
                <h3>تقسیم درآمد</h3>
                <p>سهم پایه سایت <?= e((string)$sitePercent) ?>٪ و مالیات <?= e((string)$taxPercent) ?>٪ است. سهم کاربر بر اساس سابقه فعالیت می‌تواند در سرویس افزایش یابد.</p>
            </article>
            <article class="ac-card ac-mini-card">
                <span class="material-icons">event_available</span>
                <h3>قانون شروع درآمد</h3>
                <p>مبنای قانون دو ماه، اولین تاریخ تأیید محتوای کاربر است. سابقه فعلی این کاربر: <?= e((string)$activeMonths) ?> ماه از حداقل <?= e((string)$minMonths) ?> ماه.</p>
            </article>
            <article class="ac-card ac-mini-card">
                <span class="material-icons">verified_user</span>
                <h3>جلوگیری از ثبت تکراری</h3>
                <p>برای هر محتوا و دوره فقط یک رکورد درآمد فعال ثبت می‌شود و فرم با کلید یکتایی ارسال می‌شود.</p>
            </article>
        </aside>
    </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admincontent.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/content.js') . '"></script>';
include view_path('layouts.admin');
?>
