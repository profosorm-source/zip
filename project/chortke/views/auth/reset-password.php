<?php
$title = 'تنظیم مجدد رمز عبور';
ob_start();
?>
<div class="auth-card">
    <div class="auth-header">
        <h3>🎯 <?= e(setting('site_name', 'چرتکه')) ?></h3>
        <p>تنظیم مجدد رمز عبور</p>
    </div>

    <div class="auth-body">
        <?php
        use Core\Session;
        $session = Session::getInstance();
        ?>
        <?php if ($session->hasFlash('error')): ?>
            <div class="alert alert-danger"><?= e($session->getFlash('error')) ?></div>
        <?php endif; ?>
        <?php if ($session->hasFlash('success')): ?>
            <div class="alert alert-success"><?= e($session->getFlash('success')) ?></div>
        <?php endif; ?>

        <div class="alert alert-info small">
            <span class="material-icons align-middle icon-sm">info</span>
            رمز عبور جدید باید حداقل ۸ کاراکتر باشد.
        </div>

        <form method="POST" action="<?= url('/reset-password') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

            <div class="form-group mb-3">
                <label class="form-label">
                    <span class="material-icons align-middle icon-sm">lock</span>
                    رمز عبور جدید
                </label>
                <div class="input-group">
                    <input type="password" id="password" name="password"
                           class="form-control"
                           placeholder="حداقل ۸ کاراکتر"
                           required autofocus>
                    <button type="button" class="btn btn-outline-secondary"
                            data-action="toggle-password" data-target="'password'" title="نمایش/مخفی">
                        <span class="material-icons icon-md">visibility</span>
                    </button>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">
                    <span class="material-icons align-middle icon-sm">lock_outline</span>
                    تکرار رمز عبور
                </label>
                <div class="input-group">
                    <input type="password" id="password_confirm" name="password_confirm"
                           class="form-control"
                           placeholder="رمز عبور را مجدداً وارد کنید"
                           required>
                    <button type="button" class="btn btn-outline-secondary"
                            data-action="toggle-password" data-target="'password_confirm'" title="نمایش/مخفی">
                        <span class="material-icons icon-md">visibility</span>
                    </button>
                </div>
            </div>

            <!-- strength indicator -->
            <div class="mb-3">
                <div class="progress progress-thin">
                    <div id="strength-bar" class="progress-bar" ></div>
                </div>
                <small id="strength-label" class="text-muted"></small>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <span class="material-icons align-middle">check_circle</span>
                تنظیم رمز عبور
            </button>
        </form>
    </div>

    <div class="auth-footer">
        <a href="<?= url('/login') ?>">
            <span class="material-icons align-middle icon-xs">arrow_back</span>
            بازگشت به صفحه ورود
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/authresetpassword.js') . '"></script>';
include view_path('layouts.auth');
?>
