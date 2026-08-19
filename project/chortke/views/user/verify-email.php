<?php
$title = 'تأیید ایمیل';
ob_start();
?>
<div class="auth-card">

    <div class="auth-header">
        <h3>🎯 چرتکه</h3>
        <p>تأیید ایمیل حساب کاربری</p>
    </div>

    <div class="auth-body">

        <!-- مراحل -->
        <div class="steps">
            <span class="step done">
                <span class="material-icons">check_circle</span> ثبت‌نام
            </span>
            <span class="sep">←</span>
            <span class="step done text-primary fw-bold">
                <span class="material-icons">mark_email_unread</span> تأیید ایمیل
            </span>
            <span class="sep">←</span>
            <span class="step">
                <span class="material-icons">login</span> ورود
            </span>
        </div>

        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success">
                <span class="material-icons">check_circle</span>
                <?= e($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger">
                <span class="material-icons">error</span>
                <?= e($flashError) ?>
            </div>
        <?php endif; ?>

        <!-- نشان ایمیل -->
        <?php if (!empty($email)): ?>
        <div class="email-badge">
            <span class="material-icons">email</span>
            ایمیل تأیید به <strong class="me-1"><?= e($email) ?></strong> ارسال شد
        </div>
        <?php endif; ?>

        <!-- فرم کد -->
        <form method="POST" action="<?= url('/email/verify-code') ?>" id="codeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($email ?? '') ?>">

            <div class="form-group">
                <label class="form-label">
                    <span class="material-icons icon-sm align-middle text-primary">pin</span>
                    کد تأیید
                    <small class="text-muted fw-normal small">(۶ کاراکتر از ایمیل)</small>
                </label>
                <input type="text"
                       name="code"
                       id="codeField"
                       class="form-control code-input"
                       placeholder="A1B2C3"
                       maxlength="6"
                       autocomplete="off"
                       autofocus
                       required>
            </div>

            <button type="submit" class="btn btn-primary">
                <span class="material-icons icon-lg align-middle">verified</span>
                تأیید ایمیل
            </button>
        </form>

        <div class="divider"><span>یا</span></div>

        <!-- لینک مستقیم -->
        <p class="text-center text-muted small">
            <span class="material-icons icon-sm align-middle">link</span>
            روی لینک داخل ایمیل کلیک کنید تا مستقیم تأیید شود
        </p>

        <hr class="border-secondary my-3">

        <!-- ارسال مجدد -->
        <div class="text-center">
            <form method="POST" action="<?= url('/email/resend-verification') ?>" id="resendForm">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($email ?? '') ?>">
                <span class="text-muted small">ایمیل نرسید؟ </span>
                <button type="submit" class="resend-btn" id="resendBtn">ارسال مجدد کد</button>
            </form>
        </div>

    </div>

    <div class="auth-footer">
        <a href="<?= url('login') ?>" class="auth-footer-link">
            <span class="material-icons icon-sm align-middle">arrow_forward</span>
            بازگشت به ورود
        </a>
        <span class="sep">|</span>
        <a href="<?= url('login') ?>" class="auth-footer-link">
            <span class="material-icons icon-sm align-middle">skip_next</span>
            بعداً تأیید می‌کنم
        </a>
    </div>

</div>
<?php
$content = ob_get_clean();
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userverifyemail.js') . '"></script>';
include view_path('layouts.auth');
?>