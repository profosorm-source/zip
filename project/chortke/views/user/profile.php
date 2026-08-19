<?php
$title = 'مرکز حساب کاربری';
$hideSidebar = true;
$user = $user ?? auth();
$avatar = $user->avatar ?: 'default-avatar.png';
$avatarUrl = asset('uploads/avatars/' . $avatar);
$emailVerified = !empty($user->email_verified_at);
$kycStatus = strtolower((string)($user->kyc_status ?? 'unverified'));
$kycLevel = (int)($user->kyc_level ?? 0);
$isKycComplete = ($kycStatus === 'verified') || ($kycLevel > 0);
$role = (string)($user->role ?? 'user');
$status = (string)($user->status ?? 'active');

ob_start();
?>

<div id="accountProfileRoot"
     class="acc-wrap"
     data-avatar-upload-url="<?= e(url('/profile/upload-avatar')) ?>"
     data-avatar-delete-url="<?= e(url('/profile/delete-avatar')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">

    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">manage_accounts</i></div>
            <div>
                <div class="acc-hero__eyebrow">Account Hub</div>
                <h1 class="acc-hero__title">مرکز حساب کاربری</h1>
                <p class="acc-hero__sub">مدیریت اطلاعات شخصی، امنیت حساب، احراز هویت، نشست‌های فعال و دسترسی‌های API.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/dashboard') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">dashboard</i> بازگشت به پنل کاربری</a>
            <a href="<?= url('/kyc') ?>" class="acc-btn <?= $isKycComplete ? 'acc-btn-ghost' : 'acc-btn-primary' ?>"><i class="material-icons">verified_user</i> <?= $isKycComplete ? 'KYC تأیید شده' : 'تکمیل KYC' ?></a>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'profile'; include view_path('user.account._account-nav'); ?>

        <main class="acc-hub-main">
            <section class="acc-spoke-grid">
                <a href="<?= url('/kyc') ?>" class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">verified_user</i></span><span class="acc-spoke-card__body"><strong>احراز هویت</strong><small>وضعیت KYC و مدارک هویتی</small></span><i class="material-icons">chevron_left</i></a>
                <a href="<?= url('/sessions') ?>" class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">devices</i></span><span class="acc-spoke-card__body"><strong>جلسات فعال</strong><small>دستگاه‌های واردشده به حساب</small></span><i class="material-icons">chevron_left</i></a>
                <a href="<?= url('/api-tokens') ?>" class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">vpn_key</i></span><span class="acc-spoke-card__body"><strong>توکن‌های API</strong><small>دسترسی برنامه‌های خارجی</small></span><i class="material-icons">chevron_left</i></a>
                <a href="<?= url('/settings/security') ?>" class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">security</i></span><span class="acc-spoke-card__body"><strong>تنظیمات امنیت</strong><small>۲FA و سیاست‌های امنیتی</small></span><i class="material-icons">chevron_left</i></a>
            </section>

            <section class="acc-stats">
                <div class="acc-stat <?= $emailVerified ? 'acc-stat--green' : 'acc-stat--gold' ?>"><div class="acc-stat__icon"><i class="material-icons"><?= $emailVerified ? 'mark_email_read' : 'mark_email_unread' ?></i></div><div><span class="acc-stat__lbl">وضعیت ایمیل</span><span class="acc-stat__val"><?= $emailVerified ? 'تأیید شده' : 'تأیید نشده' ?></span><span class="acc-stat__unit"><?= e($user->email ?? '') ?></span></div></div>
                <div class="acc-stat <?= $isKycComplete ? 'acc-stat--green' : 'acc-stat--red' ?>"><div class="acc-stat__icon"><i class="material-icons">verified_user</i></div><div><span class="acc-stat__lbl">احراز هویت</span><span class="acc-stat__val"><?= $isKycComplete ? 'تأیید شده' : 'نیازمند تکمیل' ?></span><span class="acc-stat__unit">KYC Level <?= e((string)$kycLevel) ?></span></div></div>
                <div class="acc-stat acc-stat--blue"><div class="acc-stat__icon"><i class="material-icons">person</i></div><div><span class="acc-stat__lbl">نقش حساب</span><span class="acc-stat__val"><?= e($role) ?></span><span class="acc-stat__unit">سطح دسترسی</span></div></div>
                <div class="acc-stat <?= $status === 'active' ? 'acc-stat--green' : 'acc-stat--red' ?>"><div class="acc-stat__icon"><i class="material-icons">toggle_on</i></div><div><span class="acc-stat__lbl">وضعیت حساب</span><span class="acc-stat__val"><?= e($status) ?></span><span class="acc-stat__unit">وضعیت عملیاتی</span></div></div>
            </section>

            <div class="acc-grid">
                <aside class="acc-card">
                    <div class="acc-card__head"><div class="acc-card__title"><i class="material-icons">account_circle</i> تصویر پروفایل</div></div>
                    <div class="acc-card__body acc-avatar">
                        <div class="acc-avatar__wrap">
                            <img id="avatarPreview" src="<?= e($avatarUrl) ?>" alt="<?= e($user->full_name ?? 'کاربر') ?>" class="acc-avatar__img">
                            <label for="avatarInput" class="acc-avatar__overlay" title="تغییر تصویر"><i class="material-icons">camera_alt</i></label>
                            <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" class="d-none">
                            <div class="acc-avatar__loader" id="avatarLoader"><i class="material-icons">hourglass_empty</i></div>
                        </div>
                        <p class="acc-avatar__hint">فرمت‌های مجاز: JPG, PNG, GIF, WEBP — حداکثر حجم ۲ مگابایت</p>
                        <div class="acc-avatar__actions">
                            <button type="button" class="acc-btn acc-btn-primary" data-action="trigger-avatar-upload"><i class="material-icons">upload</i> انتخاب تصویر جدید</button>
                            <?php if ($avatar && $avatar !== 'default-avatar.png'): ?>
                                <button type="button" class="acc-btn acc-btn-danger" data-action="delete-avatar"><i class="material-icons">delete</i> حذف تصویر فعلی</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>

                <section class="acc-form-card">
                    <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">badge</i> اطلاعات شخصی</div></div>
                    <div class="acc-form-card__body">
                        <form method="POST" action="<?= url('/profile/update') ?>">
                            <?= csrf_field() ?>
                            <div class="acc-form-row">
                                <div class="acc-form-group"><label>نام کامل</label><input type="text" name="full_name" value="<?= e($user->full_name ?? '') ?>" required></div>
                                <div class="acc-form-group"><label>ایمیل</label><input type="email" value="<?= e($user->email ?? '') ?>" readonly><small class="acc-form-text">ایمیل قابل تغییر نیست.</small></div>
                            </div>
                            <?php if (!$emailVerified): ?>
                                <div class="acc-alert acc-alert-warning" id="verify-email"><i class="material-icons">warning</i><div>ایمیل شما هنوز تأیید نشده است. <a href="<?= url('/email/verify-code?email=' . urlencode((string)($user->email ?? ''))) ?>" class="acc-badge acc-badge--warning">وارد کردن کد</a></div></div>
                            <?php endif; ?>
                            <div class="acc-form-row">
                                <div class="acc-form-group"><label>شماره موبایل</label><input type="text" name="mobile" value="<?= e($user->mobile ?? '') ?>" pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789"></div>
                                <?php $natVal = (string)($user->national_id ?? ''); if (strlen($natVal) > 12) $natVal = substr($natVal, 0, 4) . '****' . substr($natVal, -4); ?>
                                <div class="acc-form-group"><label>کد ملی</label><input type="text" name="national_id" value="<?= e($natVal) ?>" pattern="[0-9]{10}" maxlength="10" placeholder="1234567890"></div>
                            </div>
                            <div class="acc-form-row">
                                <div class="acc-form-group"><label>تاریخ تولد</label><input type="date" name="birth_date" value="<?= e($user->birth_date ?? '') ?>"></div>
                                <div class="acc-form-group"><label>جنسیت</label><select name="gender"><option value="">انتخاب کنید</option><option value="male" <?= ($user->gender ?? '') === 'male' ? 'selected' : '' ?>>مرد</option><option value="female" <?= ($user->gender ?? '') === 'female' ? 'selected' : '' ?>>زن</option><option value="other" <?= ($user->gender ?? '') === 'other' ? 'selected' : '' ?>>سایر</option></select></div>
                            </div>
                            <div class="acc-form-row one"><div class="acc-form-group"><label>آدرس</label><textarea name="address" rows="3" placeholder="آدرس کامل پستی خود را وارد کنید"><?= e($user->address ?? '') ?></textarea></div></div>
                            <div class="acc-info-grid">
                                <div class="acc-info-row">تاریخ عضویت<strong><?= e(!empty($user->created_at) ? jdate($user->created_at) : '-') ?></strong></div>
                                <div class="acc-info-row">آخرین ورود<strong><?= e(!empty($user->last_login) ? jdate($user->last_login) : 'هرگز') ?></strong></div>
                            </div>
                            <div class="acc-actions"><button type="submit" class="acc-btn acc-btn-primary"><i class="material-icons">save</i> ذخیره تغییرات</button></div>
                        </form>
                    </div>
                </section>
            </div>

            <section class="acc-form-card" style="margin-top:16px;">
                <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">lock</i> تغییر رمز عبور</div></div>
                <div class="acc-form-card__body">
                    <form method="POST" action="<?= url('/profile/change-password') ?>" id="changePasswordForm">
                        <?= csrf_field() ?>
                        <div class="acc-form-row">
                            <div class="acc-form-group"><label>رمز عبور فعلی</label><input type="password" name="current_password" required></div>
                            <div class="acc-form-group"><label>رمز عبور جدید</label><input type="password" name="new_password" id="newPassword" minlength="8" required><small class="acc-form-text">حداقل ۸ کاراکتر شامل حروف بزرگ، کوچک، عدد و نماد</small></div>
                        </div>
                        <div class="acc-form-row one"><div class="acc-form-group"><label>تکرار رمز عبور جدید</label><input type="password" name="new_password_confirmation" id="confirmPassword" minlength="8" required></div></div>
                        <div class="acc-actions"><button type="submit" class="acc-btn acc-btn-primary"><i class="material-icons">vpn_key</i> تغییر رمز عبور</button></div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userprofile.js') . '"></script>';
include view_path('layouts.user');
?>
