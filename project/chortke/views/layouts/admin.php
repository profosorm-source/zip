<?php
// ═══════════════════════════════════════════════════════════════
// Admin Panel Layout - Main Layout for all Admin Pages
// ═══════════════════════════════════════════════════════════════
// - Sidebar و Navbar از partials/admin بارگذاری می‌شوند
// - متغیرهای مورد نیاز: $currentUser, $flashSuccess, $flashError, $systemAlert, $sidebarBadges

$currentUser   = $currentUser ?? null;
$flashSuccess  = $flashSuccess ?? null;
$flashError    = $flashError ?? null;
$systemAlert   = $systemAlert ?? null;

// مقدار پیش‌فرض امن برای badgeهای سایدبار
$sidebarBadges = $sidebarBadges ?? [
    'withdrawals_pending'     => 0,
    'kyc_pending'             => 0,
    'account_deletions'       => 0,
    'payment_logs_pending'    => 0,
    'tickets_open'            => 0,
    'bug_reports'             => 0,
    'sentry_unresolved'       => 0,
    'system_alerts_active'    => 0,
];

// اطلاعات کاربر
$fullName    = 'مدیر';
$firstLetter = 'م';
if ($currentUser && isset($currentUser->full_name) && !empty($currentUser->full_name)) {
    $fullName    = $currentUser->full_name;
    $firstLetter = mb_substr($fullName, 0, 1, 'UTF-8');
}

$roleNames = [
    'admin'   => 'مدیر کل',
    'support' => 'پشتیبان',
    'user'    => 'کاربر',
];
$userRole = isset($currentUser->role) ? ($roleNames[$currentUser->role] ?? 'کاربر') : 'مدیر';

$flashData = [
    'success' => $flashSuccess ? (string)$flashSuccess : null,
    'error'   => $flashError ? (string)$flashError : null,
    'warning' => null,
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($metaDescription ?? 'پنل مدیریت سیستم - حرفه‌ای و امن') ?>">
    <title><?= e($title ?? 'پنل مدیریت') ?> | <?= e(setting('site_name', 'چرتکه')) ?></title>

    <!-- Security & Meta -->
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <!-- CSP is emitted centrally by SecurityHeadersMiddleware. Do not duplicate it in meta tags. -->

    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='24' font-size='24'>چ</text></svg>">
    <?php endif; ?>

    <!-- Vendor Styles -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/materialicons.css') ?>?v=1.0.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>?v=1.0.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>?v=5.1.3">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>?v=3.10.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>?v=11.0.0">

    <!-- Shared & Panel Styles -->
    <link rel="stylesheet" href="<?= asset('assets/css/shared/layout.css') ?>?v=<?= e(config('app.version','1.0.0')) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>?v=<?= e(config('app.version','1.0.0')) ?>">

    <?= $styles ?? '' ?>
</head>
<body class="admin-layout">

<?php include view_path('partials.admin.sidebar'); ?>

<!-- Main Content -->
<div class="main-content">

    <?php include view_path('partials.admin.navbar'); ?>

    <!-- Content -->
    <div class="content-wrapper">
        <div id="toast-container"></div>

        <!-- System Alert -->
        <?php if (!empty($systemAlert)): ?>
            <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
                <span class="material-icons me-2">warning_amber</span>
                <div>
                    <strong>هشدار سیستم:</strong> <?= e($systemAlert) ?>
                </div>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="flash-messages"><?= json_encode($flashData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>?v=5.1.3"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>?v=3.10.0"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>?v=11.0.0"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/shared/csrf.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/shared/flash.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/shared/theme.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/core.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/swal.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/app.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/admin.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/shared/navbar.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/admin/inlineactions.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>

<script nonce="<?= e($cspNonce ?? csp_nonce()) ?>">
window.adminNavbarUrls = {
    notificationsFetch: <?= json_encode(url('/admin/notifications/fetch')) ?>,
    notificationsCount: <?= json_encode(url('/admin/notifications/unread-count')) ?>,
    notificationsMark: <?= json_encode(url('/admin/notifications/mark-read/')) ?>,
    notificationsMarkAll: <?= json_encode(url('/admin/notifications/mark-all-read')) ?>,
    search: <?= json_encode(url('/admin/search')) ?>
};
</script>

<?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>
