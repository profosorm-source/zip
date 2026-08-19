<?php
$title = 'تنظیمات امنیتی';
$hideSidebar = true;
$settings = $settings ?? [];
$user = $user ?? auth();

$sessionTimeout = (int)($settings['session_timeout'] ?? 30);
$loginAlerts = (bool)($settings['login_alerts'] ?? true);
$suspiciousAlerts = (bool)($settings['suspicious_activity_alerts'] ?? true);
$emailVerified = !empty($user->email_verified_at);
$kycStatus = strtolower((string)($user->kyc_status ?? 'unverified'));
$kycLevel = (int)($user->kyc_level ?? 0);
$isKycComplete = ($kycStatus === 'verified') || ($kycLevel > 0);

ob_start();
?>

<div id="accountSecurityRoot" class="acc-wrap" data-update-url="<?= e(url('/settings/security/update')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">security</i></div>
            <div>
                <div class="acc-hero__eyebrow">Security Spoke</div>
                <h1 class="acc-hero__title">تنظیمات امنیتی</h1>
                <p class="acc-hero__sub">مدیریت هشدارهای امنیتی، خروج خودکار، نشست‌ها، رمز عبور و احراز دو مرحله‌ای.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/profile') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز حساب</a>
            <a href="<?= url('/sessions') ?>" class="acc-btn acc-btn-ghost"><i class="material-icons">devices</i> جلسات فعال</a>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'security'; include view_path('user.account._account-nav'); ?>

        <main class="acc-hub-main">
            <section class="acc-stats">
                <div class="acc-stat <?= $emailVerified ? 'acc-stat--green' : 'acc-stat--gold' ?>">
                    <div class="acc-stat__icon"><i class="material-icons"><?= $emailVerified ? 'mark_email_read' : 'mark_email_unread' ?></i></div>
                    <div><span class="acc-stat__lbl">ایمیل</span><span class="acc-stat__val"><?= $emailVerified ? 'تأیید شده' : 'تأیید نشده' ?></span><span class="acc-stat__unit">هشدارهای امنیتی</span></div>
                </div>
                <div class="acc-stat <?= $isKycComplete ? 'acc-stat--green' : 'acc-stat--red' ?>">
                    <div class="acc-stat__icon"><i class="material-icons">verified_user</i></div>
                    <div><span class="acc-stat__lbl">KYC</span><span class="acc-stat__val"><?= $isKycComplete ? 'تأیید شده' : 'نیازمند تکمیل' ?></span><span class="acc-stat__unit">سطح <?= e((string)$kycLevel) ?></span></div>
                </div>
                <div class="acc-stat acc-stat--blue">
                    <div class="acc-stat__icon"><i class="material-icons">timer</i></div>
                    <div><span class="acc-stat__lbl">خروج خودکار</span><span class="acc-stat__val acc-num"><?= number_format($sessionTimeout) ?></span><span class="acc-stat__unit">دقیقه</span></div>
                </div>
                <div class="acc-stat <?= $suspiciousAlerts ? 'acc-stat--green' : 'acc-stat--gold' ?>">
                    <div class="acc-stat__icon"><i class="material-icons">notifications_active</i></div>
                    <div><span class="acc-stat__lbl">هشدار فعالیت مشکوک</span><span class="acc-stat__val"><?= $suspiciousAlerts ? 'فعال' : 'غیرفعال' ?></span><span class="acc-stat__unit">پایش رفتار غیرعادی</span></div>
                </div>
            </section>

            <section class="acc-form-card">
                <div class="acc-form-card__head">
                    <div class="acc-form-card__title"><i class="material-icons">shield</i> تنظیمات امنیتی حساب</div>
                </div>
                <div class="acc-form-card__body">
                    <form method="POST" action="<?= url('/settings/security/update') ?>" id="securitySettingsForm">
                        <?= csrf_field() ?>

                        <div class="acc-form-row one">
                            <div class="acc-form-group">
                                <label for="session_timeout">زمان خروج خودکار پس از عدم فعالیت</label>
                                <input type="number" id="session_timeout" name="session_timeout" min="5" max="480" value="<?= e((string)$sessionTimeout) ?>">
                                <small class="acc-form-text">اگر برای این مدت غیرفعال باشید، نشست شما به‌صورت خودکار منقضی می‌شود.</small>
                            </div>
                        </div>

                        <div class="acc-form-row">
                            <label class="acc-alert acc-alert-info" style="cursor:pointer;margin:0;">
                                <input type="checkbox" id="login_alerts" name="login_alerts" <?= $loginAlerts ? 'checked' : '' ?> style="margin-top:6px;">
                                <span><strong>هشدار ورود جدید</strong><br>هنگام ورود از دستگاه یا مرورگر جدید اعلان دریافت کنید.</span>
                            </label>
                            <label class="acc-alert acc-alert-warning" style="cursor:pointer;margin:0;">
                                <input type="checkbox" id="suspicious_activity_alerts" name="suspicious_activity_alerts" <?= $suspiciousAlerts ? 'checked' : '' ?> style="margin-top:6px;">
                                <span><strong>هشدار فعالیت مشکوک</strong><br>برای رفتارهای غیرعادی و ریسک بالا اعلان دریافت کنید.</span>
                            </label>
                        </div>

                        <div class="acc-actions">
                            <button type="submit" class="acc-btn acc-btn-primary"><i class="material-icons">save</i> ذخیره تنظیمات امنیتی</button>
                            <a href="<?= url('/profile') ?>" class="acc-btn acc-btn-secondary">انصراف</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="acc-spoke-grid" style="margin-top:16px;">
                <a href="<?= url('/sessions') ?>" class="acc-spoke-card">
                    <span class="acc-spoke-card__icon"><i class="material-icons">devices</i></span>
                    <span class="acc-spoke-card__body"><strong>جلسات فعال</strong><small>خروج از دستگاه‌های ناشناس</small></span>
                    <i class="material-icons">chevron_left</i>
                </a>
                <a href="<?= url('/profile') ?>" class="acc-spoke-card">
                    <span class="acc-spoke-card__icon"><i class="material-icons">password</i></span>
                    <span class="acc-spoke-card__body"><strong>تغییر رمز عبور</strong><small>از فرم مرکز حساب کاربری</small></span>
                    <i class="material-icons">chevron_left</i>
                </a>
                <a href="<?= url('/two-factor') ?>" class="acc-spoke-card">
                    <span class="acc-spoke-card__icon"><i class="material-icons">phonelink_lock</i></span>
                    <span class="acc-spoke-card__body"><strong>احراز دو مرحله‌ای</strong><small>افزایش امنیت ورود</small></span>
                    <i class="material-icons">chevron_left</i>
                </a>
                <a href="<?= url('/api-tokens') ?>" class="acc-spoke-card">
                    <span class="acc-spoke-card__icon"><i class="material-icons">vpn_key</i></span>
                    <span class="acc-spoke-card__body"><strong>توکن‌های API</strong><small>ابطال دسترسی‌های مشکوک</small></span>
                    <i class="material-icons">chevron_left</i>
                </a>
            </section>

            <section class="acc-section" style="margin-top:16px;">
                <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">tips_and_updates</i> نکات امنیتی</div></div>
                <div class="acc-section__body">
                    <div class="acc-spoke-grid" style="margin-bottom:0;">
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">check_circle</i></span><span class="acc-spoke-card__body"><strong>رمز قوی</strong><small>حروف بزرگ، کوچک، عدد و نماد</small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">sync_lock</i></span><span class="acc-spoke-card__body"><strong>تعویض دوره‌ای</strong><small>حداقل هر سه ماه یک‌بار</small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">devices_other</i></span><span class="acc-spoke-card__body"><strong>کنترل نشست‌ها</strong><small>دستگاه ناشناس را خارج کنید</small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">wifi_off</i></span><span class="acc-spoke-card__body"><strong>شبکه امن</strong><small>از وای‌فای عمومی برای امور حساس استفاده نکنید</small></span></div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersecuritysettings.js') . '"></script>';
include view_path('layouts.user');
?>
