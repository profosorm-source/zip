<?php $title = '⚖️ مرکز حل اختلافات'; ?>
<?php ob_start(); ?>



<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">⚖️ مرکز حل اختلافات و داوری</h2>
            <p class="text-muted small mb-0">پیگیری چالش‌ها، رد شدن تسک‌ها و اعتراضات مالی شما در یک محیط امن</p>
        </div>
    </div>

    <!-- 🛡️ OPT-IN REWARD VIDEO BANNER (ارجاع فوری به داور ارشد) -->
    <?php $disputeExpressQueue = (bool)config('video_rewards.dispute_express_queue', setting('dispute_express_queue', 1)); ?>
    

    <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S داوری -->
    
            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="dispute_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="dispute_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">⚖️ ارجاع فوری به داور ارشد (Express Arbitration)</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، پرونده اختلاف شما خارج از نوبت و مستقیماً توسط داور ارشد پلتفرم بررسی و تسویه شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_dispute_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_dispute_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="disputeRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
        <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="disputeRewardModalBox">
            <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="disputeRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="disputeRewardModalIconTxt">hourglass_empty</span></div>
            <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="disputeRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
            <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="disputeRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="disputeRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
            <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="disputeRewardCloseBtn" onclick="closeDisputeRewardModal()">بستن و ارجاع پرونده به داور ارشد</button>
        </div>
    </div>

    <script nonce="<?= e(csp_nonce()) ?>">
    function startDisputeRewardedVideo(network, duration) {
        const modal = document.getElementById('disputeRewardModalWrap');
        const box = document.getElementById('disputeRewardModalBox');
        const title = document.getElementById('disputeRewardModalTitle');
        const body = document.getElementById('disputeRewardModalBody');
        const icon = document.getElementById('disputeRewardModalIconTxt');
        const iconBox = document.getElementById('disputeRewardModalIcon');
        const countTxt = document.getElementById('disputeRewardCountdown');
        const closeBtn = document.getElementById('disputeRewardCloseBtn');

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
                body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ پرونده اختلاف شما به داور ارشد ارجاع داده شد.';
                closeBtn.style.display = 'block';
            }
        }, 1000);
    }
    function closeDisputeRewardModal() {
        document.getElementById('disputeRewardModalWrap').style.opacity = '0';
        document.getElementById('disputeRewardModalWrap').style.pointerEvents = 'none';
        alert('پرونده شما با موفقیت به داور ارشد ارجاع شد.');
    }
    
            function open_dispute_boost_popup_v1() {
                const wrap = document.getElementById("dispute_boost_popup_v1_wrap");
                const box = document.getElementById("dispute_boost_popup_v1_box");
                if (wrap && box) { wrap.style.opacity = "1"; wrap.style.pointerEvents = "auto"; box.style.transform = "scale(1)"; }
            }
            function dismiss_dispute_boost_popup_v1() {
                const wrap = document.getElementById("dispute_boost_popup_v1_wrap");
                if (wrap) { wrap.style.opacity = "0"; wrap.style.pointerEvents = "none"; }
                try { sessionStorage.setItem("dispute_boost_popup_v1", "1"); } catch(e){}
            }
            function accept_dispute_boost_popup_v1() {
                dismiss_dispute_boost_popup_v1();
                setTimeout(() => { startDisputeRewardedVideo("tapsell", 15); }, 200);
            }
            window.addEventListener("DOMContentLoaded", () => {
                try { if (!sessionStorage.getItem("dispute_boost_popup_v1")) { setTimeout(() => open_dispute_boost_popup_v1(), 1000); } } catch(e){}
            });
            </script>

<?php if (empty($disputes)): ?>
        <div class="text-center py-5 bg-light rounded-4 border border-dashed">
            <span class="material-icons text-muted mb-3 icon-60">verified_user</span>
            <h4 class="fw-bold">هیچ اختلافی یافت نشد!</h4>
            <p class="text-muted">شما پرونده فعال یا بسته‌شده‌ای در این بخش ندارید. بسیار عالی است!</p>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-12">
                <?php foreach ($disputes as $d): 
                    $isClosed = in_array($d->status, $model::CLOSED_STATUSES);
                    $indicatorClass = $isClosed ? 'bg-closed' : ($d->status === 'under_review' ? 'bg-review' : 'bg-open');
                ?>
                    <div class="dispute-card p-3 p-md-4">
                        <div class="row align-items-center g-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="flex-shrink-0 text-center rounded-3 p-2 bg-light min-w-60">
                                        <span class="small text-muted d-block">کد پرونده</span>
                                        <span class="fw-bold">#<?= $d->id ?></span>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="status-indicator <?= $indicatorClass ?>"></span>
                                            <span class="badge bg-light text-dark border"><?= e($model->statusLabel($d->status)) ?></span>
                                            <span class="small text-muted"><?= e($d->ref_type === 'task' ? 'مربوط به تسک' : ($d->ref_type === 'order' ? 'مربوط به سفارش' : 'عمومی')) ?></span>
                                        </div>
                                        <h6 class="fw-bold mb-0 line-clamp-1"><?= e($d->reason) ?></h6>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 border-end-md text-md-end">
                                <div class="small text-muted">آخرین بروزرسانی:</div>
                                <div class="fw-semibold"><?= \Core\Helpers\PersianDate::toPersian($d->updated_at) ?></div>
                            </div>

                            <div class="col-md-2 text-end">
                                <a href="<?= url("/disputes/{$d->id}") ?>" class="btn btn-primary rounded-pill btn-sm px-4 py-2 w-100 shadow-sm">
                                    مشاهده پرونده <i class="material-icons small align-middle ms-1">visibility</i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php 
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userdisputesindex.css') . '">';
$content = ob_get_clean(); 
include view_path('layouts.user'); 
?>
