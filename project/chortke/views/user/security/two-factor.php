<?php
$title = 'احراز هویت دو مرحله‌ای (2FA)';
$hideSidebar = true;
$is_enabled = (bool)($is_enabled ?? false);
$qr_code_url = $qr_code_url ?? url('/two-factor/qr');
ob_start();
?>

<div id="twoFactorRoot" class="acc-wrap" data-csrf="<?= e(csrf_token()) ?>" data-enable-url="<?= e(url('/two-factor/enable')) ?>" data-disable-url="<?= e(url('/two-factor/disable')) ?>">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">phonelink_lock</i></div>
            <div>
                <div class="acc-hero__eyebrow">Two-Factor Authentication</div>
                <h1 class="acc-hero__title">احراز هویت دو مرحله‌ای</h1>
                <p class="acc-hero__sub">با Google Authenticator یا Authy یک لایه امنیتی اضافه برای ورود به حساب خود فعال کنید.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/settings/security') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به تنظیمات امنیتی</a>
            <span class="acc-badge <?= $is_enabled ? 'acc-badge--success' : 'acc-badge--warning' ?>"><?= $is_enabled ? 'فعال' : 'غیرفعال' ?></span>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'security'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <section class="acc-stats">
                <div class="acc-stat <?= $is_enabled ? 'acc-stat--green' : 'acc-stat--gold' ?>"><div class="acc-stat__icon"><i class="material-icons"><?= $is_enabled ? 'verified_user' : 'shield' ?></i></div><div><span class="acc-stat__lbl">وضعیت 2FA</span><span class="acc-stat__val"><?= $is_enabled ? 'فعال' : 'غیرفعال' ?></span><span class="acc-stat__unit">امنیت ورود</span></div></div>
                <div class="acc-stat acc-stat--blue"><div class="acc-stat__icon"><i class="material-icons">app_shortcut</i></div><div><span class="acc-stat__lbl">اپلیکیشن</span><span class="acc-stat__val">Authenticator</span><span class="acc-stat__unit">Google Authenticator / Authy</span></div></div>
                <div class="acc-stat acc-stat--green"><div class="acc-stat__icon"><i class="material-icons">pin</i></div><div><span class="acc-stat__lbl">کد ورود</span><span class="acc-stat__val">۶ رقم</span><span class="acc-stat__unit">تغییر هر ۳۰ ثانیه</span></div></div>
                <div class="acc-stat acc-stat--red"><div class="acc-stat__icon"><i class="material-icons">vpn_key</i></div><div><span class="acc-stat__lbl">کدهای بازیابی</span><span class="acc-stat__val">یک‌بار مصرف</span><span class="acc-stat__unit">در جای امن نگهداری کنید</span></div></div>
            </section>

            <?php if ($is_enabled): ?>
                <section class="acc-form-card">
                    <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">verified_user</i> 2FA فعال است</div></div>
                    <div class="acc-form-card__body">
                        <div class="acc-alert acc-alert-success"><i class="material-icons">shield</i><div>حساب شما با احراز هویت دو مرحله‌ای محافظت می‌شود.</div></div>
                        <form id="disable-2fa-form">
                            <?= csrf_field() ?>
                            <div class="acc-form-row one"><div class="acc-form-group"><label>رمز عبور فعلی برای غیرفعال‌سازی</label><input type="password" name="password" id="disable-pass" required autocomplete="current-password" placeholder="رمز عبور فعلی"></div></div>
                            <div class="acc-actions"><button type="submit" class="acc-btn acc-btn-danger" id="disable-btn"><i class="material-icons">lock_open</i> غیرفعال کردن 2FA</button></div>
                        </form>
                    </div>
                </section>
            <?php else: ?>
                <div class="acc-grid" style="grid-template-columns:minmax(0,1fr) 360px;">
                    <section class="acc-form-card">
                        <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">qr_code_2</i> فعال‌سازی 2FA</div></div>
                        <div class="acc-form-card__body">
                            <div class="acc-alert acc-alert-info"><i class="material-icons">info</i><div><strong>مراحل:</strong> اپ Google Authenticator/Authy را باز کنید، QR را اسکن کنید و کد ۶ رقمی را وارد کنید.</div></div>
                            <div style="display:flex;justify-content:center;margin:18px 0;">
                                <div id="qrcode" data-qr-url="<?= e($qr_code_url) ?>" style="background:#fff;border-radius:18px;padding:14px;border:1px solid var(--acc-border-soft);min-width:228px;min-height:228px;display:flex;align-items:center;justify-content:center;"><img src="<?= e($qr_code_url) ?>" alt="QR Code" width="200" height="200" style="display:block;"></div>
                            </div>
                            <form id="enable-2fa-form">
                                <?= csrf_field() ?>
                                <div class="acc-form-row one"><div class="acc-form-group"><label>کد ۶ رقمی اپلیکیشن</label><input type="text" name="code" id="enable-code" class="acc-input acc-num" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" required></div></div>
                                <div class="acc-actions"><button type="submit" class="acc-btn acc-btn-primary" id="enable-btn"><i class="material-icons">check_circle</i> فعال‌سازی 2FA</button></div>
                            </form>
                        </div>
                    </section>
                    <aside class="acc-card">
                        <div class="acc-card__head"><div class="acc-card__title"><i class="material-icons">tips_and_updates</i> نکات مهم</div></div>
                        <div class="acc-card__body">
                            <div class="acc-info-grid" style="grid-template-columns:1fr;">
                                <div class="acc-info-row">اپ پیشنهادی<strong>Google Authenticator یا Authy</strong></div>
                                <div class="acc-info-row">کدها<strong>هر ۳۰ ثانیه تغییر می‌کنند</strong></div>
                                <div class="acc-info-row">بازیابی<strong>کدهای بازیابی را ذخیره کنید</strong></div>
                            </div>
                            <div class="acc-alert acc-alert-warning" style="margin-top:14px;"><i class="material-icons">warning</i><div>اگر گوشی خود را از دست بدهید، فقط با کدهای بازیابی می‌توانید وارد شوید.</div></div>
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<div class="modal fade acc-modal" id="recoveryModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><span class="material-icons align-middle text-warning">vpn_key</span> کدهای بازیابی</h5></div><div class="modal-body"><div class="acc-alert acc-alert-warning"><i class="material-icons">warning</i><div>این کدها را در جای امن ذخیره کنید. هر کد فقط یک بار قابل استفاده است.</div></div><div class="row g-2" id="recovery-codes-list"></div><div class="text-center mt-3"><button class="acc-btn acc-btn-secondary" data-action="download-recovery-codes"><span class="material-icons">download</span> دانلود کدها</button></div></div><div class="modal-footer"><button type="button" class="acc-btn acc-btn-primary" data-action="confirm-saved">ذخیره کردم</button></div></div></div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersecuritytwofactor.js') . '"></script>';
include view_path('layouts.user');
?>
