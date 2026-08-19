<?php
use Core\Session;
$session = Session::getInstance();
$errors = $session->getFlash('errors') ?? [];
$old = $session->getFlash('old') ?? [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود مدیریت | <?= e(setting('site_name', 'چرتکه')) ?></title>
    
</head>
<body>
<div class="login-page">
    <div class="login-container">
        <div class="login-brand">
            <?php $adminLogo = site_logo('main'); ?>
            <?php if ($adminLogo): ?>
                <img src="<?= e($adminLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>">
            <?php else: ?>
                <div class="login-brand-icon">چ</div>
            <?php endif; ?>
            <h1><?= e(setting('site_name', 'چرتکه')) ?></h1>
            <p>پنل مدیریت سیستم</p>
        </div>
        <div class="login-card">
            <h2 class="login-card-title">ورود به پنل مدیریت</h2>
            <p class="login-card-sub">لطفاً اطلاعات حساب مدیریت خود را وارد کنید</p>
            <?php if (!empty($errors) && isset($errors['general'])): ?>
            <div class="alert-error">
                <span class="material-icons">error_outline</span>
                <span><?= e($errors['general']) ?></span>
            </div>
            <?php endif; ?>
            <form method="POST" action="<?= url('/admin/login') ?>">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label">ایمیل یا نام کاربری</label>
                    <div class="input-wrap">
                        <span class="material-icons input-icon">person_outline</span>
                        <input type="text" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" placeholder="admin@example.com" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" autofocus>
                    </div>
                    <?php if (isset($errors['email'])): ?>
                    <div class="form-error"><span class="material-icons">error</span><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">رمز عبور</label>
                    <div class="input-wrap">
                        <span class="material-icons input-icon">lock_outline</span>
                        <input type="password" name="password" id="passwordInput" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" placeholder="••••••••" autocomplete="current-password">
                        <button type="button" class="pass-toggle material-icons" id="passToggle" data-click="togglePass">visibility_off</button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                    <div class="form-error"><span class="material-icons">error</span><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-submit">
                    <span class="material-icons">login</span>
                    ورود به پنل مدیریت
                </button>
                <div class="security-notice">
                    <span class="material-icons">shield</span>
                    <span>اتصال امن · تمام فعالیت‌ها ثبت می‌شود</span>
                </div>
            </form>
        </div>
        <div class="login-footer">
            <a href="<?= url('/dashboard') ?>">← بازگشت به سایت</a>
            &nbsp;·&nbsp;
            <?= date('Y') ?> © <?= e(setting('site_name', 'چرتکه')) ?>
        </div>
    </div>
</div>

</body>
</html>

