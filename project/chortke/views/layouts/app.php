<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <title><?= e($title ?? 'چرتکه') ?> - چرتکه</title>
    
    <meta name="description" content="پلتفرم کسب درآمد آنلاین چرتکه">
    <meta name="keywords" content="کسب درآمد, تسک, تبلیغات, سرمایه گذاری">
    <meta name="author" content="چرتکه">
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <!-- CSP is emitted centrally by SecurityHeadersMiddleware. Do not duplicate it in meta tags. -->
    
    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='24' font-size='24'>چ</text></svg>">
    <?php endif; ?>
    
    <!-- Bootstrap 5 RTL -->
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>" rel="stylesheet">
    
    <!-- Shared Layout CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/shared/layout.css') ?>">
    
    <!-- Page Specific CSS -->
    <?= $styles ?? '' ?>
</head>
<body>

    <?php if (isset($content)): ?>
        <?= e($content) ?>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    
    <!-- Shared Core JS -->
    <script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/js/shared/csrf.js') ?>"></script>
    <script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/js/app.js') ?>"></script>
    
    <!-- Page Specific JS -->
    <?= $scripts ?? '' ?>
    
    <?= captcha_refresh_script() ?>
</body>
</html>
