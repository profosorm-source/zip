<?php
$title = 'ایجاد تیکت جدید';
$hideSidebar = true;
$categories = $categories ?? [];
$old = $old ?? [];

use App\Enums\TicketPriority;

ob_start();
?>

<div class="sup-wrap">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">add_comment</i></div>
            <div>
                <div class="sup-hero__eyebrow">New Ticket</div>
                <h1 class="sup-hero__title">ایجاد تیکت جدید</h1>
                <p class="sup-hero__sub">موضوع را شفاف بنویسید، دسته‌بندی و اولویت مناسب انتخاب کنید تا سریع‌تر پاسخ بگیرید.</p>
            </div>
        </div>
        <div class="sup-hero__side">
            <a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز پشتیبانی</a>
        </div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'create'; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <div class="sup-alert sup-alert-info"><i class="material-icons">info</i><div>برای مشکلات مالی و پرداخت، تیکت بهترین مسیر پیگیری رسمی است. فایل پیوست فقط JPG/PNG پذیرفته می‌شود.</div></div>

            <!-- 🛡️ OPT-IN REWARD VIDEO BANNER (تسریع در پاسخگویی تیکت) -->
            <?php $ticketVipSlaMinutes = (int)config('video_rewards.ticket_vip_sla_minutes', setting('ticket_vip_sla_minutes', 30)); ?>
            

            <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S تیکت -->
            
            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="ticket_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="ticket_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">⚡ بررسی و پاسخ‌گویی فوری (VIP Support)</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، تیکت شما در بالای صف بررسی قرار گرفته و در کمتر از ۱۰ دقیقه پاسخ داده شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_ticket_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_ticket_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="ticketRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="ticketRewardModalBox">
                    <div style="width: 80px; height: 80px; background: rgba(37,99,235,0.2); border: 2px solid #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 25px;" id="ticketRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="ticketRewardModalIconTxt">hourglass_empty</span></div>
                    <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="ticketRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
                    <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="ticketRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#3b82f6;" id="ticketRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
                    <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="ticketRewardCloseBtn" onclick="closeTicketRewardModal()">بستن و ثبت تیکت با اولویت بالا</button>
                </div>
            </div>

            <script nonce="<?= e(csp_nonce()) ?>">
            function startTicketRewardedVideo(network, duration) {
                const modal = document.getElementById('ticketRewardModalWrap');
                const box = document.getElementById('ticketRewardModalBox');
                const title = document.getElementById('ticketRewardModalTitle');
                const body = document.getElementById('ticketRewardModalBody');
                const icon = document.getElementById('ticketRewardModalIconTxt');
                const iconBox = document.getElementById('ticketRewardModalIcon');
                const countTxt = document.getElementById('ticketRewardCountdown');
                const closeBtn = document.getElementById('ticketRewardCloseBtn');

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
                        body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ تیکت شما در صف پاسخگویی سریع قرار گرفت.';
                        closeBtn.style.display = 'block';
                    }
                }, 1000);
            }
            function closeTicketRewardModal() {
                document.getElementById('ticketRewardModalWrap').style.opacity = '0';
                document.getElementById('ticketRewardModalWrap').style.pointerEvents = 'none';
                alert('اولویت پاسخگویی سریع با موفقیت فعال شد.');
            }
            </script>

            <div class="sup-form-card">
                <div class="sup-form-card__head"><div class="sup-form-card__title"><i class="material-icons">edit_note</i> اطلاعات تیکت</div></div>
                <div class="sup-form-card__body">
                    <form method="POST" action="<?= url('/tickets/store') ?>" enctype="multipart/form-data" id="ticketCreateForm">
                        <?= csrf_field() ?>

                        <div class="sup-form-row">
                            <div class="sup-form-group">
                                <label>دسته‌بندی</label>
                                <select name="category_id" class="sup-select" required>
                                    <option value="">انتخاب کنید...</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= e($category->id) ?>" <?= old('category_id') == $category->id ? 'selected' : '' ?>><?= e($category->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sup-form-group">
                                <label>موضوع</label>
                                <input type="text" name="subject" class="sup-input" value="<?= e(old('subject')) ?>" placeholder="مشکل یا سوال خود را خلاصه بنویسید" required>
                            </div>
                        </div>

                        <div class="sup-form-row one">
                            <div class="sup-form-group">
                                <label>اولویت</label>
                                <div class="sup-priority-grid">
                                    <?php
                                    $prLabels = ['low'=>'کم','normal'=>'معمولی','high'=>'زیاد','urgent'=>'فوری'];
                                    $prIcons = ['low'=>'arrow_downward','normal'=>'remove','high'=>'arrow_upward','urgent'=>'priority_high'];
                                    foreach (TicketPriority::all() as $p): $selected = old('priority', 'normal') === $p;
                                    ?>
                                        <label class="sup-priority-opt <?= $selected ? 'active' : '' ?>">
                                            <input type="radio" name="priority" value="<?= e($p) ?>" <?= $selected ? 'checked' : '' ?> required>
                                            <i class="material-icons"><?= e($prIcons[$p] ?? 'remove') ?></i><?= e($prLabels[$p] ?? $p) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="sup-form-row one">
                            <div class="sup-form-group">
                                <label>شرح مشکل</label>
                                <textarea name="message" class="sup-textarea" rows="7" placeholder="جزئیات مشکل، مراحل بروز خطا و هر اطلاعات مفیدی را بنویسید..." required><?= e(old('message')) ?></textarea>
                            </div>
                        </div>

                        <div class="sup-form-row one">
                            <div class="sup-form-group">
                                <label>فایل پیوست اختیاری</label>
                                <input type="file" id="attachFiles" name="attachments[]" multiple accept="image/*" class="sup-input">
                                <small class="sup-form-text">JPG یا PNG — حداکثر ۳ مگابایت برای هر فایل</small>
                                <div class="sup-file-preview" id="filePreview"></div>
                            </div>
                        </div>

                        <div class="sup-actions">
                            <button type="submit" class="sup-btn sup-btn-primary"><i class="material-icons">send</i> ارسال تیکت</button>
                            <a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-secondary"><i class="material-icons">close</i> انصراف</a>
                        </div>
                    </form>
                </div>
            </div>

            <section class="sup-spoke-grid" style="margin-top:16px;">
                <div class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">payments</i></span><span class="sup-spoke-card__body"><strong>مشکلات مالی</strong><small>شماره تراکنش و مبلغ را ذکر کنید</small></span></div>
                <div class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">screenshot_monitor</i></span><span class="sup-spoke-card__body"><strong>خطای صفحه</strong><small>اسکرین‌شات و مسیر صفحه را پیوست کنید</small></span></div>
                <div class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">schedule</i></span><span class="sup-spoke-card__body"><strong>زمان پاسخ</strong><small>معمولاً کمتر از ۲۴ ساعت</small></span></div>
                <div class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">security</i></span><span class="sup-spoke-card__body"><strong>موضوع امنیتی</strong><small>اولویت بالا انتخاب کنید</small></span></div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userticketscreate.js') . '"></script>';
include view_path('layouts.user');
?>
