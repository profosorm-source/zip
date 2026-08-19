<?php
$title = 'ورود به حساب کاربری';
$errors = $errors ?? [];
$old    = $old    ?? [];
ob_start();
?>
    <div class="auth-card">
        <div class="auth-header">
            <?php $__authLogo = site_logo('main'); ?>
            <?php if ($__authLogo): ?>
                <a href="<?= url('/') ?>">
                    <img src="<?= e($__authLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" class="auth-logo">
                </a>
            <?php else: ?>
                <h3>🎯 <?= e(setting('site_name','چرتکه')) ?></h3>
            <?php endif; ?>
            <p>ورود به حساب کاربری</p>
        </div>
        
        <div class="auth-body">
            <?php if (!empty($flashSuccess)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <span class="material-icons">check_circle</span>
                    <?= e($flashSuccess) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($flashError)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <span class="material-icons">error</span>
                    <?= e($flashError) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($showResendVerification)): ?>
                <div class="alert alert-warning rounded">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="material-icons text-warning">mark_email_unread</span>
                        <strong>ایمیل تأیید نشده</strong>
                    </div>
                    <p class="small mb-2">
                        برای ورود ابتدا باید ایمیل خود را تأیید کنید.
                    </p>
                    <a href="<?= url('/email/verify-code?email=' . urlencode($resendEmail ?? '')) ?>"
                       class="btn btn-warning btn-sm small">
                        <span class="material-icons icon-sm align-middle">verified</span>
                        تأیید ایمیل
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($flashWarning)): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <span class="material-icons">warning</span>
                    <?= e($flashWarning) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="<?= url('/login') ?>">
                <?= csrf_field() ?>
                
                <div class="form-group">
                    <label class="form-label">ایمیل یا موبایل</label>
                    <input type="text" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                        value="<?= e($old['email'] ?? '') ?>" required autofocus>
                    <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback d-block">
                            <?= e($errors['email'][0]) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">رمز عبور</label>
                    <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" required>
                    <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback d-block">
                            <?= e($errors['password'][0]) ?>
                        </div>
                    <?php endif; ?>
                </div>
<?php if (!empty($captchaType)): ?>
                <?= captcha_field($captchaType) ?>
                <?php if (!empty($errors['captcha'])): ?>
                    <div class="text-danger small mt-1">
                        <?= e($errors['captcha']) ?>
                    </div>
                <?php endif; ?>
<?php endif; ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">
                        مرا به خاطر بسپار
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary mt-3">ورود</button>
        
        <div class="divider"><span>یا</span></div>
        
        <a href="<?= url('login/google') ?>" class="btn btn-outline-secondary w-100">
            <img src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20viewBox%3D%220%200%2048%2048%22%3E%3Cpath%20fill%3D%22#FFC107%22%20d%3D%22M43.6%2020.5H42V20H24v8h11.3C33.7%2032.7%2029.2%2036%2024%2036c-6.6%200-12-5.4-12-12s5.4-12%2012-12c3.1%200%205.9%201.2%208%203.1l5.7-5.7C34.1%206.1%2029.3%204%2024%204%2013%204%204%2013%204%2024s9%2020%2020%2020%2020-9%2020-20c0-1.3-.1-2.4-.4-3.5z%22/%3E%3Cpath%20fill%3D%22#FF3D00%22%20d%3D%22M6.3%2014.7l6.6%204.8C14.7%2015.1%2019%2012%2024%2012c3.1%200%205.9%201.2%208%203.1l5.7-5.7C34.1%206.1%2029.3%204%2024%204%2016.3%204%209.6%208.3%206.3%2014.7z%22/%3E%3Cpath%20fill%3D%22#4CAF50%22%20d%3D%22M24%2044c5.1%200%209.8-2%2013.3-5.2l-6.1-5.2C29.3%2035.1%2026.8%2036%2024%2036c-5.2%200-9.6-3.3-11.3-7.9l-6.5%205C9.5%2039.6%2016.2%2044%2024%2044z%22/%3E%3Cpath%20fill%3D%22#1976D2%22%20d%3D%22M43.6%2020.5H42V20H24v8h11.3c-.8%202.4-2.3%204.4-4.1%205.6l6.1%205.2C36.9%2039.1%2044%2034%2044%2024c0-1.3-.1-2.4-.4-3.5z%22/%3E%3C/svg%3E" alt="Google" class="google-icon">
            ورود با گوگل
        </a>
    </form>
</div>
            
            <div class="text-center">
                <a href="<?= url('/forgot-password') ?>" class="link-primary">رمز عبور خود را فراموش کرده‌اید؟</a>
            </div>
        </div>
        
        <div class="auth-footer">
            حساب کاربری ندارید؟ <a href="<?= url('/register') ?>">ثبت‌نام کنید</a>
        </div>
    </div>
<?php
$content = ob_get_clean();
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userlogin.js') . '"></script>';
include view_path('layouts.auth');
?>