<?php
$title = 'تسک‌های شبکه اجتماعی';
$hideSidebar = true;
ob_start();
?>

<div class="earn-wrap task-market-wrap">
    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><i class="material-icons">groups</i></div>
            <div>
                <div class="earn-hero__eyebrow">Social Tasks</div>
                <h1 class="earn-hero__title">تسک‌های شبکه اجتماعی</h1>
                <p class="earn-hero__sub">این بخش در بازار یکپارچه تسک‌ها نمایش داده می‌شود. با فیلتر Social می‌توانید فقط همین تسک‌ها را ببینید.</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/tasks?type=social') ?>" class="earn-btn earn-btn-primary"><i class="material-icons">dynamic_feed</i> مشاهده در بازار تسک‌ها</a>
            <a href="<?= url('/social-tasks/dashboard') ?>" class="earn-btn earn-btn-ghost"><i class="material-icons">dashboard</i> داشبورد من</a>
        </div>
    </section>

    <div class="earn-hub-layout">
        <?php $activeSpoke = 'social'; include view_path('user.tasks._earn-nav'); ?>
        <main class="earn-hub-main">
            <div class="earn-alert earn-alert-info"><i class="material-icons">info</i><div>برای تجربه بهتر، تسک‌های اجتماعی همراه با تسک‌های SEO و سفارشی در صفحه یکپارچه بازار تسک‌ها قرار گرفته‌اند.</div></div>
            
            <!-- 🛡️ OPT-IN REWARD VIDEO BANNER (نمایش تسک‌های اختصاصی با درآمد بالاتر) -->
            <?php $vipTaskMultiplier = (float)config('video_rewards.vip_task_multiplier', setting('vip_task_multiplier', 1.5)); ?>
            

            <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S تسک‌ها -->
            
            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="social_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="social_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">⭐ نمایش تسک‌های اختصاصی با درآمد بالاتر</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، دسترسی شما به تسک‌های VIP با کارمزد و پاداش بالاتر (تا ۳ برابری) باز شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_social_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_social_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="taskRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="taskRewardModalBox">
                    <div style="width: 80px; height: 80px; background: rgba(212,175,55,0.2); border: 2px solid #d4af37; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #d4af37; margin: 0 auto 25px;" id="taskRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="taskRewardModalIconTxt">hourglass_empty</span></div>
                    <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="taskRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
                    <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="taskRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#d4af37;" id="taskRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
                    <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="taskRewardCloseBtn" onclick="closeTaskRewardModal()">بستن و ورود به فید VIP</button>
                </div>
            </div>

            <script nonce="<?= e(csp_nonce()) ?>">
            function startTaskRewardedVideo(network, duration) {
                const modal = document.getElementById('taskRewardModalWrap');
                const box = document.getElementById('taskRewardModalBox');
                const title = document.getElementById('taskRewardModalTitle');
                const body = document.getElementById('taskRewardModalBody');
                const icon = document.getElementById('taskRewardModalIconTxt');
                const iconBox = document.getElementById('taskRewardModalIcon');
                const countTxt = document.getElementById('taskRewardCountdown');
                const closeBtn = document.getElementById('taskRewardCloseBtn');

                modal.style.opacity = '1';
                modal.style.pointerEvents = 'auto';
                box.style.transform = 'scale(1)';
                title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
                iconBox.style.borderColor = '#d4af37';
                iconBox.style.background = 'rgba(212,175,55,0.2)';
                iconBox.style.color = '#d4af37';
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
                        body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ قفل تسک‌های اختصاصی با درآمد بالاتر برای شما فعال شد.';
                        closeBtn.style.display = 'block';
                    }
                }, 1000);
            }
            function closeTaskRewardModal() {
                document.getElementById('taskRewardModalWrap').style.opacity = '0';
                document.getElementById('taskRewardModalWrap').style.pointerEvents = 'none';
                window.location.href = "<?= url('/tasks?type=social&vip=1') ?>";
            }
            </script>

            <section class="earn-spoke-grid">
                <a href="<?= url('/tasks?type=social&platform=instagram') ?>" class="earn-spoke-card"><span class="earn-spoke-card__icon"><i class="material-icons">photo_camera</i></span><span class="earn-spoke-card__body"><strong>Instagram</strong><small>فالو، لایک و تعامل</small></span></a>
                <a href="<?= url('/tasks?type=social&platform=telegram') ?>" class="earn-spoke-card"><span class="earn-spoke-card__icon"><i class="material-icons">send</i></span><span class="earn-spoke-card__body"><strong>Telegram</strong><small>عضویت و تعامل کانال</small></span></a>
                <a href="<?= url('/tasks?type=social&sort=highest_price') ?>" class="earn-spoke-card"><span class="earn-spoke-card__icon"><i class="material-icons">payments</i></span><span class="earn-spoke-card__body"><strong>پاداش بالا</strong><small>مرتب‌سازی بر اساس درآمد</small></span></a>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
include view_path('layouts.user');
?>
