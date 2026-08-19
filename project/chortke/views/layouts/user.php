<?php
// ─── User Panel Layout ─────────────────────────────────────
// این فایل لایوت اصلی پنل کاربری است
// navbar, sidebar و footer از فایل‌های جداگانه در partials/user/ بارگذاری می‌شوند

$isLoggedIn = $isLoggedIn ?? false;
$currentUser = $currentUser ?? null;
$hideSidebar = (bool)($hideSidebar ?? false);
$bodyClass = trim((string)($bodyClass ?? '') . ($hideSidebar ? ' layout-no-sidebar' : ''));

// Flash messages
$flashSuccess = $flashSuccess ?? null;
$flashError   = $flashError   ?? null;
$flashWarning = $flashWarning ?? null;

$flashData = [
    'success' => $flashSuccess ? (string)$flashSuccess : null,
    'error'   => $flashError ? (string)$flashError : null,
    'warning' => $flashWarning ? (string)$flashWarning : null,
];

// اطلاعات کاربر
$fullName   = 'کاربر';
$firstLetter = 'ک';
if ($currentUser && !empty($currentUser->full_name)) {
    $fullName    = (string)$currentUser->full_name;
    $firstLetter = mb_substr(trim($fullName), 0, 1, 'UTF-8');
}

$tier      = $tier ?? ($currentUser->tier ?? 'SILVER');
$kycStatus = strtolower((string)($currentUser->kyc_status ?? 'unverified'));
$kycLevel  = (int)($currentUser->kyc_level ?? 0);
$isKycComplete = ($kycStatus === 'verified') || ($kycLevel > 0);

$notifCount      = $notifCount ?? 0;
$topNotifications = $topNotifications ?? [];
$openTicketCount  = $openTicketCount ?? 0;

if ($isLoggedIn && $currentUser && function_exists('user_sidebar_badges')) {
    $badges = user_sidebar_badges((int)($currentUser->id ?? 0));
    if ($notifCount <= 0) {
        $notifCount = (int)($badges['unread_notifications'] ?? 0);
    }
    if (empty($topNotifications)) {
        $topNotifications = $badges['top_notifications'] ?? [];
    }
}

$avatarFile = ($currentUser && !empty($currentUser->avatar)) ? $currentUser->avatar : 'default-avatar.png';
$avatarPath = rtrim((string)BASE_PATH, '/\\') . '/public/uploads/avatars/' . $avatarFile;
$avatarUrl  = is_file($avatarPath)
    ? asset('uploads/avatars/' . $avatarFile)
    : asset('assets/images/default-avatar.png');

$showEmailNotice = false;
if ($isLoggedIn && $currentUser && empty($currentUser->email_verified_at)) {
    $session = app()->session;
    if ($session->get('show_email_verify_notice')) {
        $showEmailNotice = true;
        $session->remove('show_email_verify_notice');
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'پنل کاربری') ?> | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Vendor Styles -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/materialicons.css') ?>?v=1.0.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>?v=5.1.3">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>?v=3.10.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>?v=1.0.0">
    <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>?v=11.0.0">

    <!-- Shared & Panel Styles -->
    <link rel="stylesheet" href="<?= asset('assets/css/shared/layout.css') ?>?v=<?= e(config('app.version','1.0.0')) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/panel.css') ?>?v=<?= e(config('app.version','1.0.0')) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/panel-binance.css') ?>?v=<?= e(config('app.version','1.0.0')) ?>">

    <?= $styles ?? '' ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>

<?php include view_path('partials.user.navbar'); ?>
<?php if (!$hideSidebar): ?>
<?php include view_path('partials.user.sidebar'); ?>
<?php endif; ?>

<!-- Main Content -->
<div class="main-content">
    <div class="content-wrapper">

        <!-- KYC Warning -->
        <?php if ($isLoggedIn && !$isKycComplete): ?>
            <div class="alert alert-warning d-flex align-items-center mb-4">
                <span class="material-icons me-2">warning</span>
                <span>
                    لطفاً برای استفاده کامل از امکانات،
                    <a href="<?= url('/kyc') ?>" class="alert-link fw-bold">احراز هویت</a>
                    خود را تکمیل کنید.
                </span>
            </div>
        <?php endif; ?>

        <!-- Email Verification Warning -->
        <?php if ($showEmailNotice): ?>
            <div class="alert alert-info d-flex align-items-center justify-content-between mb-4">
                <div class="d-flex align-items-center">
                    <span class="material-icons me-2">mark_email_unread</span>
                    <span>
                        ایمیل شما هنوز تأیید نشده است.
                        <a href="<?= url('/profile#verify-email') ?>" class="alert-link fw-bold">از تنظیمات پروفایل</a>
                        می‌توانید آن را تأیید کنید.
                    </span>
                </div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>

    <?php include view_path('partials.user.footer'); ?>
</div>

<!-- دکمه شب/روز ثابت گوشه چپ پایین -->
<button class="theme-fab" id="themeToggleBtn" title="تغییر تم">
  <span class="material-icons" id="themeIcon">dark_mode</span>
</button>

<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="flash-messages"><?= json_encode($flashData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>?v=5.1.3"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>?v=3.10.0"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>?v=11.0.0"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/shared/csrf.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/shared/flash.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/shared/theme.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/core.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/app.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/swal.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/user.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>
<script nonce="<?= e($cspNonce) ?>" src="<?= asset('assets/js/shared/user/navbar.js') ?>?v=<?= e(config('app.version','1.0.0')) ?>"></script>

<?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>
