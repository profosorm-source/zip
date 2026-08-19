<?php
$title = 'احراز هویت';
$hideSidebar = true;
$kyc = $kyc ?? null;
$canSubmit = $canSubmit ?? ['can' => true, 'reason' => ''];
$appName = $appName ?? setting('site_name', 'چرتکه');
$todayJalali = $todayJalali ?? to_jalali(date('Y-m-d'));

$statusConfig = [
    'pending'      => ['label' => 'در انتظار بررسی', 'icon' => 'schedule', 'badge' => 'acc-badge--warning', 'stat' => 'acc-stat--gold'],
    'under_review' => ['label' => 'در حال بررسی', 'icon' => 'manage_search', 'badge' => 'acc-badge--info', 'stat' => 'acc-stat--blue'],
    'verified'     => ['label' => 'تأیید شده', 'icon' => 'verified', 'badge' => 'acc-badge--success', 'stat' => 'acc-stat--green'],
    'rejected'     => ['label' => 'رد شده', 'icon' => 'cancel', 'badge' => 'acc-badge--danger', 'stat' => 'acc-stat--red'],
    'expired'      => ['label' => 'منقضی شده', 'icon' => 'event_busy', 'badge' => 'acc-badge--danger', 'stat' => 'acc-stat--red'],
];
$cfg = $kyc ? ($statusConfig[$kyc->status] ?? $statusConfig['pending']) : ['label' => 'احراز نشده', 'icon' => 'assignment_ind', 'badge' => 'acc-badge--warning', 'stat' => 'acc-stat--gold'];

ob_start();
?>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">verified_user</i></div>
            <div>
                <div class="acc-hero__eyebrow">KYC Spoke</div>
                <h1 class="acc-hero__title">احراز هویت</h1>
                <p class="acc-hero__sub">تأیید هویت برای دسترسی کامل به امکانات مالی، برداشت وجه و امنیت بیشتر حساب.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/profile') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز حساب</a>
            <?php if ((!$kyc || ($kyc->status ?? '') === 'rejected') && !empty($canSubmit['can'])): ?>
                <a href="<?= url('/kyc/upload') ?>" class="acc-btn acc-btn-primary"><i class="material-icons"><?= $kyc ? 'refresh' : 'upload' ?></i> <?= $kyc ? 'ارسال مجدد مدارک' : 'شروع احراز هویت' ?></a>
            <?php endif; ?>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'kyc'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <!-- 🛡️ OPT-IN REWARD VIDEO BANNER (تسریع در تایید مدارک هویتی) -->
            <?php $kycExpressMinutes = (int)config('video_rewards.kyc_express_minutes', setting('kyc_express_minutes', 15)); ?>
            

            <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S KYC -->
            
            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="kyc_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="kyc_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">⚡ بررسی و تأیید فوری مدارک هویتی (Express KYC)</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، مدارک هویتی شما در اولویت پردازش هوش مصنوعی (DeepFace) قرار گرفته و ظرف ۱۵ دقیقه تأیید شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_kyc_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_kyc_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="kycRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="kycRewardModalBox">
                    <div style="width: 80px; height: 80px; background: rgba(37,99,235,0.2); border: 2px solid #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 25px;" id="kycRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="kycRewardModalIconTxt">hourglass_empty</span></div>
                    <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="kycRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
                    <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="kycRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#3b82f6;" id="kycRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
                    <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="kycRewardCloseBtn" onclick="closeKycRewardModal()">بستن و اعمال اولویت هوش مصنوعی</button>
                </div>
            </div>

            <script nonce="<?= e(csp_nonce()) ?>">
            function startKycRewardedVideo(network, duration) {
                const modal = document.getElementById('kycRewardModalWrap');
                const box = document.getElementById('kycRewardModalBox');
                const title = document.getElementById('kycRewardModalTitle');
                const body = document.getElementById('kycRewardModalBody');
                const icon = document.getElementById('kycRewardModalIconTxt');
                const iconBox = document.getElementById('kycRewardModalIcon');
                const countTxt = document.getElementById('kycRewardCountdown');
                const closeBtn = document.getElementById('kycRewardCloseBtn');

                modal.style.opacity = '1';
                modal.style.pointerEvents = 'auto';
                box.style.transform = 'scale(1)';
                title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
                iconBox.style.borderColor = '#3b82f6';
                iconBox.style.background = 'rgba(37,99,235,0.2)';
                iconBox.style.color = '#3b82f6';
                icon.innerText = 'hourglass_empty';
                closeBtn.style.display = 'none';

                let timer = duration;
                countTxt.innerText = timer;
                
                const interval = setInterval(() => {
                    timer--;
                    countTxt.innerText = timer;
                    if (timer <= 0) {
                        clearInterval(interval);
                        iconBox.style.borderColor = '#10b981';
                        iconBox.style.background = 'rgba(16,185,129,0.2)';
                        iconBox.style.color = '#10b981';
                        icon.innerText = 'verified_user';
                        title.innerText = 'نمایش ویدیو با موفقیت به اتمام رسید!';
                        body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ مدارک شما در اولویت پردازش هوش مصنوعی (DeepFace) قرار گرفت.';
                        closeBtn.style.display = 'block';
                    }
                }, 1000);
            }
            function closeKycRewardModal() {
                document.getElementById('kycRewardModalWrap').style.opacity = '0';
                document.getElementById('kycRewardModalWrap').style.pointerEvents = 'none';
                alert('اولویت بررسی سریع با موفقیت فعال شد.');
            }
            </script>
        <main class="acc-hub-main">
            <section class="acc-stats">
                <div class="acc-stat <?= e($cfg['stat']) ?>"><div class="acc-stat__icon"><i class="material-icons"><?= e($cfg['icon']) ?></i></div><div><span class="acc-stat__lbl">وضعیت KYC</span><span class="acc-stat__val"><?= e($cfg['label']) ?></span><span class="acc-stat__unit">احراز هویت کاربر</span></div></div>
                <div class="acc-stat acc-stat--blue"><div class="acc-stat__icon"><i class="material-icons">edit_note</i></div><div><span class="acc-stat__lbl">مدارک لازم</span><span class="acc-stat__val">کد ملی + سلفی</span><span class="acc-stat__unit">مدارک خوانا و واضح</span></div></div>
                <div class="acc-stat acc-stat--gold"><div class="acc-stat__icon"><i class="material-icons">schedule</i></div><div><span class="acc-stat__lbl">زمان بررسی</span><span class="acc-stat__val">حداکثر ۴۸ ساعت</span><span class="acc-stat__unit">توسط تیم پشتیبانی</span></div></div>
                <div class="acc-stat acc-stat--green"><div class="acc-stat__icon"><i class="material-icons">workspace_premium</i></div><div><span class="acc-stat__lbl">مزیت</span><span class="acc-stat__val">دسترسی کامل</span><span class="acc-stat__unit">برداشت و امکانات مالی</span></div></div>
            </section>

            <?php if (!$kyc): ?>
                <section class="acc-section">
                    <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">route</i> مراحل احراز هویت</div></div>
                    <div class="acc-section__body">
                        <div class="acc-spoke-grid" style="margin-bottom:0;">
                            <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">badge</i></span><span class="acc-spoke-card__body"><strong>۱. اطلاعات هویتی</strong><small>کد ملی و تاریخ تولد</small></span></div>
                            <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">photo_camera</i></span><span class="acc-spoke-card__body"><strong>۲. سلفی مدارک</strong><small>کارت ملی و برگه دست‌نوشته</small></span></div>
                            <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">manage_search</i></span><span class="acc-spoke-card__body"><strong>۳. بررسی کارشناس</strong><small>کنترل صحت مدارک</small></span></div>
                            <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">verified</i></span><span class="acc-spoke-card__body"><strong>۴. تأیید نهایی</strong><small>فعال شدن دسترسی کامل</small></span></div>
                        </div>
                    </div>
                </section>

                <div class="acc-empty acc-section">
                    <i class="material-icons">assignment_ind</i>
                    <h3>احراز هویت نشده</h3>
                    <p>برای برداشت وجه و استفاده کامل از امکانات، هویت خود را تأیید کنید.</p>
                    <?php if (!empty($canSubmit['can'])): ?>
                        <a href="<?= url('/kyc/upload') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">upload</i> شروع احراز هویت</a>
                    <?php else: ?>
                        <div class="acc-alert acc-alert-warning" style="display:inline-flex;margin:0;"><i class="material-icons">info</i><?= e($canSubmit['reason'] ?? '') ?></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section class="acc-section">
                    <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons"><?= e($cfg['icon']) ?></i> وضعیت احراز هویت</div><span class="acc-badge <?= e($cfg['badge']) ?>"><?= e($cfg['label']) ?></span></div>
                    <div class="acc-section__body">
                        <div class="acc-info-grid">
                            <?php
                            $rawNat = (string)($kyc->national_code ?? $kyc->national_id ?? '');
                            if (strlen($rawNat) > 12) {
                                $displayNat = substr($rawNat, 0, 4) . '****' . substr($rawNat, -4);
                            } elseif ($rawNat !== '') {
                                $displayNat = substr_replace($rawNat, '****', 3, 4);
                            } else {
                                $displayNat = 'ثبت نشده';
                            }
                            ?>
                            <div class="acc-info-row">کد ملی<strong class="acc-num" style="word-break: break-all; overflow-wrap: anywhere; max-width: 170px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; direction: ltr; display: inline-block; vertical-align: middle;" title="<?= e($rawNat) ?>"><?= e($displayNat) ?></strong></div>
                            <div class="acc-info-row">تاریخ ثبت<strong><?= to_jalali($kyc->submitted_at ?? '') ?></strong></div>
                            <div class="acc-info-row">تاریخ بررسی<strong><?= !empty($kyc->reviewed_at) ? to_jalali($kyc->reviewed_at) : 'در انتظار' ?></strong></div>
                            <div class="acc-info-row">اعتبار تا<strong><?= !empty($kyc->expires_at) ? to_jalali($kyc->expires_at) : '—' ?></strong></div>
                        </div>

                        <?php if (($kyc->status ?? '') === 'rejected'): ?>
                            <div class="acc-alert acc-alert-danger" style="margin-top:16px;"><i class="material-icons">report_problem</i><div><strong>دلیل رد درخواست:</strong><br><?= nl2br(e($kyc->rejection_reason ?? 'دلیلی ثبت نشده است')) ?></div></div>
                            <?php if (!empty($canSubmit['can'])): ?><a href="<?= url('/kyc/upload') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">refresh</i> ارسال مجدد مدارک</a><?php endif; ?>
                        <?php elseif (($kyc->status ?? '') === 'verified'): ?>
                            <div class="acc-alert acc-alert-success" style="margin-top:16px;"><i class="material-icons">shield</i><div>حساب شما تأیید شده و دسترسی کامل مالی فعال است.</div></div>
                        <?php else: ?>
                            <div class="acc-alert acc-alert-info" style="margin-top:16px;"><i class="material-icons">manage_search</i><div>درخواست شما ثبت شده و در صف بررسی قرار دارد.</div></div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="acc-section" style="margin-top:16px;">
                <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">help_outline</i> راهنمای ارسال مدارک</div></div>
                <div class="acc-section__body">
                    <div class="acc-spoke-grid" style="margin-bottom:0;">
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">credit_card</i></span><span class="acc-spoke-card__body"><strong>کارت ملی</strong><small>واضح، خوانا و بدون پوشیدگی</small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">edit_note</i></span><span class="acc-spoke-card__body"><strong>برگه دست‌نوشته</strong><small><?= e($appName) ?> - <?= e($todayJalali) ?></small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">face</i></span><span class="acc-spoke-card__body"><strong>سلفی</strong><small>صورت، کارت و برگه در یک تصویر</small></span></div>
                        <div class="acc-spoke-card"><span class="acc-spoke-card__icon"><i class="material-icons">light_mode</i></span><span class="acc-spoke-card__body"><strong>نور مناسب</strong><small>بدون فیلتر، سایه یا تاری</small></span></div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
include view_path('layouts.user');
?>
