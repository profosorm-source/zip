<?php
$title = 'تأیید رمز عبور';
$hideSidebar = true;
$redirect_to = $redirect_to ?? url('/two-factor');
ob_start();
?>

<div id="twoFactorConfirmRoot" class="acc-wrap" data-action="<?= e(url('/two-factor/authorize')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">password</i></div>
            <div>
                <div class="acc-hero__eyebrow">Sensitive Action</div>
                <h1 class="acc-hero__title">تأیید رمز عبور</h1>
                <p class="acc-hero__sub">برای مشاهده و تنظیم احراز هویت دو مرحله‌ای، ابتدا رمز عبور حساب خود را تأیید کنید.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/settings/security') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به امنیت</a>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'security'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <div class="acc-grid" style="grid-template-columns:minmax(0,1fr) 340px;">
                <section class="acc-form-card">
                    <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">lock</i> تأیید دسترسی حساس</div></div>
                    <div class="acc-form-card__body">
                        <div class="acc-alert acc-alert-warning"><i class="material-icons">shield</i><div>این مرحله برای جلوگیری از فعال‌سازی/تغییر 2FA توسط فردی است که فقط به نشست شما دسترسی دارد.</div></div>
                        <form id="confirmForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_to" value="<?= e($redirect_to) ?>">
                            <div class="acc-form-row one">
                                <div class="acc-form-group">
                                    <label>رمز عبور حساب</label>
                                    <input type="password" name="password" class="acc-input" required autocomplete="current-password" placeholder="رمز عبور فعلی را وارد کنید">
                                </div>
                            </div>
                            <div id="message" class="acc-alert acc-alert-danger" style="display:none;"></div>
                            <div class="acc-actions"><button type="submit" class="acc-btn acc-btn-primary" id="confirmBtn"><i class="material-icons">verified_user</i> تأیید و ادامه</button></div>
                        </form>
                    </div>
                </section>
                <aside class="acc-card">
                    <div class="acc-card__head"><div class="acc-card__title"><i class="material-icons">info_outline</i> چرا این مرحله؟</div></div>
                    <div class="acc-card__body">
                        <div class="acc-info-grid" style="grid-template-columns:1fr;">
                            <div class="acc-info-row">محافظت از نشست<strong>جلوگیری از سوءاستفاده در دستگاه‌های مشترک</strong></div>
                            <div class="acc-info-row">اعتبار تأیید<strong>۱۰ دقیقه</strong></div>
                            <div class="acc-info-row">پس از تأیید<strong>ورود به تنظیمات 2FA</strong></div>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersecurityconfirmpassword.js') . '"></script>';
include view_path('layouts.user');
?>
