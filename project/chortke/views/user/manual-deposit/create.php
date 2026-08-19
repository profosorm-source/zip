<?php
$title = 'واریز کارت به کارت';
ob_start();
?>

<!-- Header -->
<div class="content-header">
    <h1>واریز دستی</h1>
    <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline">
        <span class="material-icons icon-sm">arrow_forward</span>
        بازگشت
    </a>
</div>

<!-- 🛡️ OPT-IN REWARD VIDEO BANNER (تسریع در تایید فیش واریزی) -->
<?php $depositFastTrackEnabled = (bool)config('video_rewards.deposit_fast_track_enabled', setting('deposit_fast_track_enabled', 1)); ?>
<?php if ($depositFastTrackEnabled): ?>


<!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S واریز دستی -->

            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="dep_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="dep_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">⚡ بررسی فوری رسید واریزی در خزانه</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، فیش واریزی شما در اولویت بررسی خزانه قرار گرفته و موجودی شما فوراً شارژ شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_dep_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_dep_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="depositRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
    <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="depositRewardModalBox">
        <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="depositRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="depositRewardModalIconTxt">hourglass_empty</span></div>
        <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="depositRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
        <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="depositRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="depositRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
        <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="depositRewardCloseBtn" onclick="closeDepositRewardModal()">بستن و اعمال اولویت تایید فیش</button>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
function startDepositRewardedVideo(network, duration) {
    const modal = document.getElementById('depositRewardModalWrap');
    const box = document.getElementById('depositRewardModalBox');
    const title = document.getElementById('depositRewardModalTitle');
    const body = document.getElementById('depositRewardModalBody');
    const icon = document.getElementById('depositRewardModalIconTxt');
    const iconBox = document.getElementById('depositRewardModalIcon');
    const countTxt = document.getElementById('depositRewardCountdown');
    const closeBtn = document.getElementById('depositRewardCloseBtn');

    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    box.style.transform = 'scale(1)';
    title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
    iconBox.style.borderColor = '#10b981';
    iconBox.style.background = 'rgba(16,185,129,0.2)';
    iconBox.style.color = '#10b981';
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
            body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ فیش واریزی شما در اولویت بررسی خزانه قرار گرفت.';
            closeBtn.style.display = 'block';
        }
    }, 1000);
}
function closeDepositRewardModal() {
    document.getElementById('depositRewardModalWrap').style.opacity = '0';
    document.getElementById('depositRewardModalWrap').style.pointerEvents = 'none';
    alert('اولویت تایید فیش واریزی با موفقیت فعال شد.');
}

            function open_dep_boost_popup_v1() {
                const wrap = document.getElementById("dep_boost_popup_v1_wrap");
                const box = document.getElementById("dep_boost_popup_v1_box");
                if (wrap && box) { wrap.style.opacity = "1"; wrap.style.pointerEvents = "auto"; box.style.transform = "scale(1)"; }
            }
            function dismiss_dep_boost_popup_v1() {
                const wrap = document.getElementById("dep_boost_popup_v1_wrap");
                if (wrap) { wrap.style.opacity = "0"; wrap.style.pointerEvents = "none"; }
                try { sessionStorage.setItem("dep_boost_popup_v1", "1"); } catch(e){}
            }
            function accept_dep_boost_popup_v1() {
                dismiss_dep_boost_popup_v1();
                setTimeout(() => { startDepositRewardedVideo("tapsell", 15); }, 200);
            }
            window.addEventListener("DOMContentLoaded", () => {
                try { if (!sessionStorage.getItem("dep_boost_popup_v1")) { setTimeout(() => open_dep_boost_popup_v1(), 1000); } } catch(e){}
            });
            </script>

<?php endif; ?>

<!-- مراحل واریز -->
<div class="steps-container">
    <div class="step active">
        <div class="step-number">1</div>
        <div class="step-title">واریز به حساب سایت</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <div class="step-number">2</div>
        <div class="step-title">ثبت اطلاعات واریز</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <div class="step-number">3</div>
        <div class="step-title">بررسی و تأیید</div>
    </div>
</div>

<!-- اطلاعات حساب سایت -->
<div class="account-info-card">
    <h3>
        <span class="material-icons">account_balance</span>
        اطلاعات حساب سایت برای واریز
    </h3>
    
    <div class="info-grid">
        <div class="info-item">
            <span class="label">شماره کارت:</span>
            <div class="value-with-copy">
                <span class="value card-number"><?= e($siteCardNumber) ?></span>
                <button class="copy-btn" data-action="copy-to-clipboard" data-text="<?= e($siteCardNumber) ?>">
                    <span class="material-icons">content_copy</span>
                </button>
            </div>
        </div>

        <?php if ($siteSheba): ?>
        <div class="info-item">
            <span class="label">شماره شبا:</span>
            <div class="value-with-copy">
                <span class="value sheba-number" dir="ltr">IR<?= e($siteSheba) ?></span>
                <button class="copy-btn" data-action="copy-to-clipboard" data-text="IR<?= e($siteSheba) ?>">
                    <span class="material-icons">content_copy</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="info-item">
            <span class="label">نام بانک:</span>
            <span class="value"><?= e($siteBankName) ?></span>
        </div>

        <div class="info-item">
            <span class="label">به نام:</span>
            <span class="value">سایت چرتکه</span>
        </div>
    </div>

    <div class="alert alert-warning">
        <span class="material-icons icon-sm">warning</span>
        <div>
            <strong>توجه:</strong>
            حتماً از یکی از کارت‌های تأییدشده خود واریز کنید. واریز از کارت دیگران رد خواهد شد.
        </div>
    </div>
</div>

<!-- فرم ثبت واریز -->
<div class="form-card">
    <h3>ثبت اطلاعات واریز</h3>
    
    <form method="POST" action="<?= url('/wallet/deposit/manual') ?>" enctype="multipart/form-data" id="depositForm">
        <?= csrf_field() ?>
        
        <!-- Hidden Security Fields -->
        <input type="hidden" name="idempotency_key" id="idempotencyKey" value="">
        <input type="hidden" name="device_fingerprint" id="deviceFingerprint" value="">
        <input type="hidden" name="request_timestamp" id="requestTimestamp" value="">

        <div class="form-row">
            <div class="form-group">
                <label for="card_id">کارت بانکی شما: <span class="required">*</span></label>
                <select id="card_id" name="card_id" class="form-control" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($cards as $card): ?>
                    <option value="<?= e($card->id) ?>" <?= ($old['card_id'] ?? '') == $card->id ? 'selected' : '' ?>>
                        <?= e(substr($card->card_number, 0, 4)) ?>-****-****-<?= e(substr($card->card_number, -4)) ?> 
                        (<?= e($card->bank_name) ?>)
                        <?= $card->is_default ? '⭐' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">کارتی که از آن واریز کرده‌اید را انتخاب کنید</small>
            </div>

            <div class="form-group">
                <label for="amount">مبلغ واریزی (تومان): <span class="required">*</span></label>
                <input type="number" 
                       id="amount" 
                       name="amount" 
                       class="form-control" 
                       placeholder="مثال: 100000"
                       min="10000"
                       step="1000"
                       value="<?= e($old['amount'] ?? '') ?>"
                       required>
                <small class="form-text">حداقل مبلغ: 10,000 تومان</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="tracking_code">شماره پیگیری: <span class="required">*</span></label>
                <input type="text" 
                       id="tracking_code" 
                       name="tracking_code" 
                       class="form-control ltr" 
                       placeholder="مثال: 123456789"
                       value="<?= e($old['tracking_code'] ?? '') ?>"
                       required>
                <small class="form-text">شماره پیگیری تراکنش بانکی</small>
            </div>

            <div class="form-group">
                <label for="deposit_date">تاریخ واریز: <span class="required">*</span></label>
                <input type="date" 
                       id="deposit_date" 
                       name="deposit_date" 
                       class="form-control" 
                       max="<?= date('Y-m-d') ?>"
                       value="<?= e($old['deposit_date'] ?? date('Y-m-d')) ?>"
                       required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="deposit_time">ساعت واریز: <span class="required">*</span></label>
                <input type="time" 
                       id="deposit_time" 
                       name="deposit_time" 
                       class="form-control" 
                       value="<?= e($old['deposit_time'] ?? date('H:i')) ?>"
                       required>
            </div>

            <div class="form-group">
                <label for="receipt_image">تصویر فیش واریز:</label>
                <input type="file" 
                       id="receipt_image" 
                       name="receipt_image" 
                       class="form-control" 
                       accept="image/*">
                <small class="form-text">اختیاری - حداکثر 2MB - فرمت: JPG, PNG</small>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <span class="material-icons">check</span>
                ثبت درخواست واریز
            </button>
            <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline btn-lg">
                انصراف
            </a>
        </div>
    </form>
</div>

<!-- راهنما -->
<div class="help-card">
    <h4>
        <span class="material-icons icon-sm">help</span>
        راهنمای واریز دستی
    </h4>
    <ol>
        <li>ابتدا از یکی از کارت‌های تأییدشده خود به حساب سایت واریز کنید</li>
        <li>اطلاعات دقیق واریز (شماره پیگیری، تاریخ و ساعت) را در فرم بالا وارد کنید</li>
        <li>در صورت امکان، تصویر فیش واریز را آپلود کنید (سرعت تأیید بیشتر می‌شود)</li>
        <li>درخواست شما حداکثر ظرف 2 تا 24 ساعت بررسی و تأیید می‌شود</li>
        <li>پس از تأیید، مبلغ به کیف پول شما افزوده می‌شود</li>
    </ol>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usermanualdeposit.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usermanualdepositcreate.js') . '"></script>';
include view_path('layouts.user');
?>