<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'چرتکه' ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    
    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='24' font-size='24'>چ</text></svg>">
    <?php endif; ?>
    
    <!-- Material Icons -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/materialicons.css') ?>">
    <!-- Vazirmatn Font -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <!-- Bootstrap RTL -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    
    <?= $styles ?? '' ?>
</head>
<body>
    <div class="auth-container">
        <?= $content ?? '' ?>
    </div>
    
    <script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
    <script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/shared/csrf.js') ?>"></script>
    <script nonce="<?= e($cspNonce ?? csp_nonce()) ?>" src="<?= asset('assets/js/app.js') ?>"></script>
    <?= $scripts ?? '' ?>
    <?= captcha_refresh_script() ?>
</body>
</html>
