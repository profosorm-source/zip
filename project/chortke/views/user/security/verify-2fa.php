<?php
$title = 'تأیید هویت دو مرحله‌ای';
ob_start();
?>
<div class="verify-card p-4 p-md-5 mx-auto max-w-420">

    <!-- آیکون و عنوان -->
    <div class="text-center mb-4">
        <span class="material-icons text-primary icon-64">security</span>
        <h4 class="mt-2 fw-bold">تأیید هویت دو مرحله‌ای</h4>
        <p class="text-muted small">کد ۶ رقمی از Google Authenticator را وارد کنید</p>
    </div>

    <!-- فرم OTP با ورودی‌های جداگانه -->
    <form id="verify-form">
        <?= csrf_field() ?>
        <input type="hidden" name="code" id="code-hidden">

        <div class="otp-digits mb-4" id="otp-digits">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" class="otp-digit" maxlength="1"
                   inputmode="numeric" pattern="[0-9]"
                   autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                   <?= $i === 0 ? 'autofocus' : '' ?>>
            <?php endfor; ?>
        </div>

        <div id="error-msg" class="alert alert-danger d-none mb-3 text-center"></div>

        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3" id="submit-btn" disabled>
            <span class="material-icons align-middle">check_circle</span>
            تأیید ورود
        </button>
    </form>

    <!-- کد بازیابی -->
    <div class="text-center mb-3">
        <button class="btn btn-link btn-sm text-muted" type="button"
                data-bs-toggle="collapse" data-bs-target="#recovery-section">
            دسترسی به Google Authenticator ندارید؟
        </button>
        <div class="collapse mt-2" id="recovery-section">
            <p class="small text-muted">کد بازیابی خود را وارد کنید:</p>
            <input type="text" id="recovery-code" class="form-control text-center font-monospace"
                   placeholder="XXXXXXXXXXXXXXXXXXXXXXXX" maxlength="24" autocomplete="off">
            <button class="btn btn-outline-secondary btn-sm mt-2 w-100" data-action="use-recovery">
                استفاده از کد بازیابی
            </button>
        </div>
    </div>

    <!-- خروج -->
    <div class="text-center">
        <a href="<?= url('/logout') ?>" class="text-muted small text-decoration-none">
            <span class="material-icons align-middle icon-sm">logout</span>
            انصراف و خروج از سیستم
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersecurityverify2fa.js') . '" data-verify-url="' . e(url('/verify-2fa')) . '" data-dashboard-url="' . e(url('/dashboard')) . '"></script>';
include view_path('layouts.auth');
?>