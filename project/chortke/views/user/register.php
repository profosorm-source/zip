<?php
$errors = $errors ?? [];
$old = $old ?? [];
$flashError = $flashError ?? null;
$flashSuccess = $flashSuccess ?? null;
$flashWarning = $flashWarning ?? null;

$refVal = old('referral_code');
if ($refVal === null || $refVal === '') {
    $refVal = $referralCode ?? '';
}
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
        <p>ایجاد حساب کاربری جدید</p>
    </div>
    
    <div class="auth-body">
	<?php if ($flashError): ?>
  <div class="alert alert-danger d-flex align-items-center mb-3">
    <span class="material-icons me-2">error</span>
    <span><?= e($flashError) ?></span>
  </div>
<?php endif; ?>
        <form method="POST" action="<?= url('/register') ?>">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label class="form-label">نام و نام خانوادگی *</label>
                <input type="text" name="full_name" class="form-control" value="<?= e($old['full_name'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">ایمیل *</label>
                <input type="email" name="email" class="form-control" value="<?= e($old['email'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">موبایل *</label>
                <input type="text" name="mobile" class="form-control" value="<?= e($old['mobile'] ?? '') ?>" placeholder="09123456789" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">رمز عبور *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">تکرار رمز عبور *</label>
                <input type="password" name="password_confirmation" class="form-control" required>
            </div>
            <div class="mb-3">
  <label class="form-label">کد معرف (اختیاری)</label>
  <input
  type="text"
  name="referral_code"
  class="form-control"
  value="<?= e($refVal) ?>"
  placeholder="کد معرف (اختیاری)"
  maxlength="32"
  autocomplete="off"
/>
  <small class="text-muted">اگر با لینک دعوت وارد شده باشید این بخش به صورت خودکار پر می‌شود.</small>
</div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                <label class="form-check-label" for="terms">
                    <a href="/terms" target="_blank" class="link-primary">قوانین و مقررات</a> را می‌پذیرم
                </label>
            </div>
            
<?php if (!empty($captchaType)): ?>
            <div class="captcha-wrapper">
                <?= captcha_field($captchaType) ?>
                <?php if ($captchaType === 'recaptcha_v2'): ?>
                    
                <?php endif; ?>
            </div>
<?php endif; ?>
           
            <button type="submit" class="btn btn-primary mt-3">ثبت نام</button>
            
            <div class="divider"><span>یا</span></div>
            
            <a href="<?= url('login/google') ?>" class="btn btn-outline-secondary w-100" class="btn-google">
                <img src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20viewBox%3D%220%200%2048%2048%22%3E%3Cpath%20fill%3D%22#FFC107%22%20d%3D%22M43.6%2020.5H42V20H24v8h11.3C33.7%2032.7%2029.2%2036%2024%2036c-6.6%200-12-5.4-12-12s5.4-12%2012-12c3.1%200%205.9%201.2%208%203.1l5.7-5.7C34.1%206.1%2029.3%204%2024%204%2013%204%204%2013%204%2024s9%2020%2020%2020%2020-9%2020-20c0-1.3-.1-2.4-.4-3.5z%22/%3E%3Cpath%20fill%3D%22#FF3D00%22%20d%3D%22M6.3%2014.7l6.6%204.8C14.7%2015.1%2019%2012%2024%2012c3.1%200%205.9%201.2%208%203.1l5.7-5.7C34.1%206.1%2029.3%204%2024%204%2016.3%204%209.6%208.3%206.3%2014.7z%22/%3E%3Cpath%20fill%3D%22#4CAF50%22%20d%3D%22M24%2044c5.1%200%209.8-2%2013.3-5.2l-6.1-5.2C29.3%2035.1%2026.8%2036%2024%2036c-5.2%200-9.6-3.3-11.3-7.9l-6.5%205C9.5%2039.6%2016.2%2044%2024%2044z%22/%3E%3Cpath%20fill%3D%22#1976D2%22%20d%3D%22M43.6%2020.5H42V20H24v8h11.3c-.8%202.4-2.3%204.4-4.1%205.6l6.1%205.2C36.9%2039.1%2044%2034%2044%2024c0-1.3-.1-2.4-.4-3.5z%22/%3E%3C/svg%3E" alt="Google" class="google-icon">
                ثبت نام با گوگل
            </a>
        </form>
    </div>
    
    <div class="auth-footer">
        حساب کاربری دارید؟ <a href="<?= url('/login') ?>">وارد شوید</a>
    </div>
</div>

<div id="toast-container"></div>

<?php
$content = ob_get_clean();
require_once view_path('layouts.auth');
?>