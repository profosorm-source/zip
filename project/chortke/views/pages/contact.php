<?php
$title = 'تماس با ما';
ob_start();
?>
<style>
/* ── استایل‌های یکپارچه تماس با ما (acc-wrap) ── */
.acc-wrap { font-family: Tahoma, 'IRANSans', sans-serif; direction: rtl; background: #f8f9fa; padding-bottom: 80px; }
.acc-hero { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; padding: 60px 40px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); flex-wrap: wrap; gap: 20px; }
.acc-hero__main { display: flex; align-items: center; gap: 24px; }
.acc-hero__icon { width: 72px; height: 72px; background: rgba(37,99,235,0.2); border: 2px solid #2563eb; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; flex-shrink: 0; }
.acc-hero__icon .material-icons { font-size: 2.4rem; }
.acc-hero__eyebrow { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; color: #3b82f6; font-weight: 700; margin-bottom: 6px; }
.acc-hero__title { font-size: 2.2rem; font-weight: 700; margin: 0 0 8px; color: #fff; }
.acc-hero__sub { font-size: 1.05rem; color: #94a3b8; margin: 0; }
.acc-btn { display: inline-flex; align-items: center; gap: 10px; padding: 14px 28px; border-radius: 14px; font-weight: 600; font-size: 1rem; text-decoration: none; transition: all 0.2s ease; cursor: pointer; border: none; }
.acc-btn-primary { background: #2563eb; color: #fff; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); }
.acc-btn-primary:hover { background: #3b82f6; transform: translateY(-2px); color: #fff; }
.acc-grid-contact { display: grid; grid-template-columns: 380px minmax(0, 1fr); gap: 35px; margin-top: -30px; }
@media(max-width:991px){ .acc-grid-contact { grid-template-columns: minmax(0, 1fr); } }
.acc-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 40px; border: 1px solid #e2e8f0; }
.acc-side-card { background: #1e293b; border-radius: 20px; padding: 40px; color: #fff; box-shadow: 0 15px 35px -10px rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); }
.acc-side-card h4 { font-size: 1.4rem; font-weight: 700; margin-bottom: 30px; color: #fff; }
.acc-contact-item { display: flex; align-items: center; gap: 20px; margin-bottom: 28px; }
.acc-contact-icon { width: 52px; height: 52px; background: rgba(37,99,235,0.2); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #3b82f6; flex-shrink: 0; }
.acc-contact-icon .material-icons { font-size: 1.6rem; }
.acc-contact-label { font-size: 0.9rem; color: #94a3b8; margin-bottom: 4px; }
.acc-contact-value { font-size: 1.1rem; font-weight: 700; color: #fff; text-decoration: none; }
.acc-contact-value:hover { color: #3b82f6; }
.acc-form-card h4 { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 30px; }
.acc-form-group label { font-size: 0.95rem; font-weight: 600; color: #475569; margin-bottom: 10px; display: block; }
.acc-input { width: 100%; padding: 14px 18px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font-size: 0.95rem; transition: all 0.2s ease; margin-bottom: 22px; }
.acc-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); outline: none; }
.acc-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
@media(max-width:767px){ .acc-grid-2 { grid-template-columns: 1fr; } .acc-card, .acc-side-card { padding: 28px 20px; } }
</style>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">contact_support</i></div>
            <div>
                <div class="acc-hero__eyebrow">Chortke Platform</div>
                <h1 class="acc-hero__title">تماس با ما</h1>
                <p class="acc-hero__sub">هر سوالی داری، تیم پشتیبانی چرتکه ۲۴ ساعته در خدمت شماست</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/login') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">login</i> ورود به پلتفرم</a>
        </div>
    </section>

    <div class="container">
        <div class="acc-grid-contact">
            
            <!-- پنل کناری اطلاعات تماس -->
            <aside class="acc-side-card">
                <h4>راه‌های ارتباطی</h4>
                <div class="acc-contact-item">
                    <div class="acc-contact-icon"><span class="material-icons">email</span></div>
                    <div>
                        <div class="acc-contact-label">ایمیل پشتیبانی</div>
                        <a href="mailto:<?= e(setting('contact_email')) ?>" class="acc-contact-value"><?= e(setting('contact_email','support@chortke.ir')) ?></a>
                    </div>
                </div>
                <div class="acc-contact-item">
                    <div class="acc-contact-icon"><span class="material-icons">phone</span></div>
                    <div>
                        <div class="acc-contact-label">تلفن ارتباطی</div>
                        <a href="tel:<?= e(setting('contact_phone')) ?>" class="acc-contact-value"><?= e(setting('contact_phone','021-XXXXXXXX')) ?></a>
                    </div>
                </div>
                <div class="acc-contact-item">
                    <div class="acc-contact-icon"><span class="material-icons">send</span></div>
                    <div>
                        <div class="acc-contact-label">آیدی تلگرام</div>
                        <a href="https://t.me/<?= e(setting('telegram_support')) ?>" class="acc-contact-value" target="_blank">@<?= e(setting('telegram_support','chortke_support')) ?></a>
                    </div>
                </div>
                <div class="acc-contact-item" style="margin-bottom:0;">
                    <div class="acc-contact-icon"><span class="material-icons">schedule</span></div>
                    <div>
                        <div class="acc-contact-label">ساعت پاسخگویی</div>
                        <div class="acc-contact-value" style="font-size: 1rem;">شنبه تا چهارشنبه ۹ تا ۱۸</div>
                    </div>
                </div>
            </aside>

            <!-- فرم اصلی تماس -->
            <main class="acc-card acc-form-card">
                <h4>ارسال پیام مستقیم</h4>
                <form method="POST" action="<?= url('/contact/send') ?>">
                    <?= csrf_field() ?>
                    <div class="acc-grid-2">
                        <div class="acc-form-group">
                            <label>نام و نام خانوادگی <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="acc-input" required placeholder="علی احمدی">
                        </div>
                        <div class="acc-form-group">
                            <label>ایمیل <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="acc-input" required placeholder="example@email.com">
                        </div>
                    </div>
                    <div class="acc-form-group">
                        <label>موضوع <span class="text-danger">*</span></label>
                        <select name="subject" class="acc-input" required>
                            <option value="">انتخاب کنید...</option>
                            <option value="support">پشتیبانی فنی</option>
                            <option value="financial">مسائل مالی</option>
                            <option value="account">مشکل حساب کاربری</option>
                            <option value="suggestions">پیشنهادات و انتقادات</option>
                            <option value="complaint">شکایت</option>
                            <option value="cooperation">درخواست همکاری</option>
                            <option value="other">سایر موارد</option>
                        </select>
                    </div>
                    <div class="acc-form-group">
                        <label>پیام <span class="text-danger">*</span></label>
                        <textarea name="message" class="acc-input" rows="6" required placeholder="پیام خود را با جزئیات بنویسید..."></textarea>
                    </div>
                    <!-- 🛡️ SPAM BOT HONEYPOT TRAP -->
                    <div style="display:none; position:absolute; left:-9999px;" aria-hidden="true">
                        <input type="text" name="user_name" tabindex="-1" autocomplete="off">
                        <input type="email" name="confirm_email" tabindex="-1" autocomplete="off">
                        <input type="text" name="address" tabindex="-1" autocomplete="off">
                        <input type="text" name="phone_number_ext" tabindex="-1" autocomplete="off">
                    </div>
                    <?php if (function_exists('captcha_field')): ?><div class="acc-form-group"><?= captcha_field() ?></div><?php endif; ?>
                    <button type="submit" class="acc-btn acc-btn-primary" style="width: 100%; justify-content: center; font-size: 1.1rem; padding: 16px;">
                        <span class="material-icons">send</span> ارسال پیام
                    </button>
                </form>
            </main>

        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>