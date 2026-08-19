<?php
/**
 * Guest Layout — صفحات عمومی
 * هوم، قوانین، تماس، راهنما، لاگین، ثبت‌نام
 */
$siteName = setting('site_name') ?? 'چرتکه';
$siteDesc = setting('site_description') ?? 'پلتفرم کسب درآمد آنلاین';
$isLoggedIn = auth();
$siteLogo = site_logo('main') ?? '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($siteDesc) ?>">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <!-- CSP is emitted centrally by SecurityHeadersMiddleware. Do not duplicate it in meta tags. -->
    <meta name="theme-color" content="#1565c0">
    <title><?= e($title ?? $siteName) ?></title>

    <!-- Favicon -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Vendor Styles -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/materialicons.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">

    <!-- Shared Layout CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/shared/layout.css') ?>">
    <!-- Page Specific CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">

    <?= $extra_css ?? '' ?>
</head>
<body>

<!-- لودینگ -->
<div class="page-loader" id="pageLoader">
    <div class="loader-spinner"></div>
    <div class="loader-text"><?= e($siteName) ?></div>
</div>
<script nonce="<?= e($cspNonce ?? '') ?>">
(function(){
  function hide(){
    var loader=document.getElementById('pageLoader');
    if(!loader) return;
    loader.classList.add('hide');
    setTimeout(function(){ if(loader && loader.parentNode) loader.parentNode.removeChild(loader); }, 500);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', hide, {once:true});
  else hide();
  setTimeout(hide, 2500);
})();
</script>

<!-- ═══ NAVBAR ═══ -->
<nav class="guest-navbar" id="guestNavbar">
    <div class="navbar-inner">
        <a href="<?= url('/') ?>" class="navbar-logo">
            <?php if ($siteLogo): ?>
                <img src="<?= url($siteLogo) ?>" alt="<?= e($siteName) ?>">
            <?php endif; ?>
            <span class="navbar-logo-text"><?= e($siteName) ?></span>
        </a>

        <button class="navbar-toggle" id="navbarToggle" aria-label="منو">
            <span class="material-icons" id="navbarToggleIcon">menu</span>
        </button>

        <ul class="navbar-links" id="navbarLinks">
            <li><a href="<?= url('/') ?>"><span class="material-icons">home</span> خانه</a></li>
            <li><a href="<?= url('/help') ?>"><span class="material-icons">menu_book</span> راهنما</a></li>
            <li><a href="<?= url('/terms') ?>"><span class="material-icons">gavel</span> قوانین</a></li>
            <li><a href="<?= url('/contact') ?>"><span class="material-icons">mail</span> تماس</a></li>
            <?php if ($isLoggedIn): ?>
                <li><a href="<?= url('/dashboard') ?>" class="btn-nav-primary">
                    <span class="material-icons">dashboard</span> داشبورد
                </a></li>
            <?php else: ?>
                <li><a href="<?= url('/login') ?>" class="btn-nav-outline">
                    <span class="material-icons">login</span> ورود
                </a></li>
                <li><a href="<?= url('/register') ?>" class="btn-nav-primary">
                    <span class="material-icons">person_add</span> ثبت‌نام رایگان
                </a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- ═══ محتوا ═══ -->
<main>
    <?= $content ?? '' ?>
</main>

<!-- دکمه اسکرول به بالا -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="بازگشت به بالا">
    <span class="material-icons">keyboard_arrow_up</span>
</button>

<!-- JS -->
<script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/js/shared/csrf.js') ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/js/shared/loader.js') ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= asset('assets/js/shared/guest.js') ?>"></script>
<script nonce="<?= e($cspNonce ?? '') ?>">
    window.BASE_URL = '<?= rtrim(url('/'), '/') ?>';
</script>

<?php include view_path('layouts.footer'); ?>

<?= $extra_js ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>
