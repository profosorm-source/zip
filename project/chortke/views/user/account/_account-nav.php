<?php
$activeSpoke = $activeSpoke ?? 'profile';
$currentUser = $currentUser ?? auth();
$kycStatus = strtolower((string)($currentUser->kyc_status ?? 'unverified'));
$kycLevel = (int)($currentUser->kyc_level ?? 0);
$isKycComplete = ($kycStatus === 'verified') || ($kycLevel > 0);
$emailVerified = !empty($currentUser->email_verified_at);

$spokes = [
    ['key'=>'profile', 'href'=>url('/profile'), 'icon'=>'manage_accounts', 'label'=>'مرکز حساب کاربری', 'desc'=>'پروفایل، رمز عبور و اطلاعات پایه'],
    ['key'=>'kyc', 'href'=>url('/kyc'), 'icon'=>'verified_user', 'label'=>'احراز هویت KYC', 'desc'=>'وضعیت و ارسال مدارک هویتی'],
    ['key'=>'sessions', 'href'=>url('/sessions'), 'icon'=>'devices', 'label'=>'جلسات فعال', 'desc'=>'مدیریت دستگاه‌های واردشده'],
    ['key'=>'api', 'href'=>url('/api-tokens'), 'icon'=>'vpn_key', 'label'=>'توکن‌های API', 'desc'=>'دسترسی برنامه‌های خارجی'],
    ['key'=>'security', 'href'=>url('/settings/security'), 'icon'=>'security', 'label'=>'تنظیمات امنیت', 'desc'=>'۲FA و سیاست‌های امنیتی'],
    ['key'=>'notifications', 'href'=>url('/settings/notifications'), 'icon'=>'notifications', 'label'=>'اعلان‌ها', 'desc'=>'ترجیحات پیام و هشدارها'],
];
?>
<aside class="acc-hub-sidebar" aria-label="منوی داخلی حساب کاربری">
    <div class="acc-side-card acc-module-card">
        <div class="acc-module-card__top">
            <div class="acc-module-card__icon"><i class="material-icons">hub</i></div>
            <div>
                <div class="acc-module-card__eyebrow">Account Hub</div>
                <h2 class="acc-module-card__title">حساب کاربری</h2>
                <p class="acc-module-card__desc">امنیت، هویت و دسترسی‌های حساب از یک مرکز واحد.</p>
            </div>
        </div>
        <nav class="acc-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="acc-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="acc-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="acc-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
                    <i class="material-icons acc-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="acc-side-card acc-mini">
        <div class="acc-mini__head"><i class="material-icons">shield</i><span>وضعیت امنیت</span></div>
        <div class="acc-mini-row"><span>KYC</span><strong class="<?= $isKycComplete ? 'acc-badge acc-badge--success' : 'acc-badge acc-badge--warning' ?>"><?= $isKycComplete ? 'تأیید شده' : 'نیازمند تکمیل' ?></strong></div>
        <div class="acc-mini-row"><span>ایمیل</span><strong class="<?= $emailVerified ? 'acc-badge acc-badge--success' : 'acc-badge acc-badge--warning' ?>"><?= $emailVerified ? 'تأیید شده' : 'تأیید نشده' ?></strong></div>
    </div>
</aside>
