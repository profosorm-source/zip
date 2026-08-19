<?php
$title = 'سیاست حریم خصوصی';
ob_start();
?>
<style>
/* ── استایل‌های یکپارچه حریم خصوصی (acc-wrap) ── */
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
.acc-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; margin-top: -30px; margin-bottom: 40px; }
@media(max-width:991px){ .acc-grid-4 { grid-template-columns: repeat(2, 1fr); } }
@media(max-width:767px){ .acc-grid-4 { grid-template-columns: 1fr; } .acc-content-card { padding: 30px 22px; } .acc-hero { padding: 40px 25px; } }
.acc-privacy-box { background: #fff; border-radius: 18px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.03); text-align: center; }
.acc-privacy-box .icon-wrap { width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
.acc-privacy-box .material-icons { font-size: 1.8rem; }
.acc-privacy-box h6 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
.acc-privacy-box p { font-size: 0.9rem; color: #64748b; margin: 0; }
.acc-content-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 50px; border: 1px solid #e2e8f0; }
.acc-content-card h2 { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 20px; margin-top: 45px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; }
.acc-content-card h2:first-child { margin-top: 0; }
.acc-content-card p { font-size: 1.05rem; color: #475569; line-height: 1.8; margin-bottom: 18px; }
.acc-content-card ul { padding-right: 22px; margin-bottom: 25px; display: flex; flex-direction: column; gap: 12px; }
.acc-content-card li { font-size: 1.05rem; color: #475569; line-height: 1.7; }
.acc-content-card a { color: #2563eb; text-decoration: none; font-weight: 600; }
.acc-content-card a:hover { text-decoration: underline; }
</style>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">privacy_tip</i></div>
            <div>
                <div class="acc-hero__eyebrow">Chortke Platform</div>
                <h1 class="acc-hero__title">سیاست حریم خصوصی</h1>
                <p class="acc-hero__sub">اطلاعات شما برای ما امانت است</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/login') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">login</i> ورود به پلتفرم</a>
        </div>
    </section>

    <div class="container">
        <!-- خلاصه تصویری -->
        <div class="acc-grid-4">
            <?php
            $boxes = [
                ['lock','#0284c7','رمزگذاری اطلاعات','داده‌های شما با SSL رمزگذاری می‌شود'],
                ['enhanced_encryption','#16a34a','عدم فروش داده','اطلاعات شما به هیچ‌کس فروخته نمی‌شود'],
                ['delete_forever','#ea580c','حق حذف','می‌توانی حساب و داده‌هایت را حذف کنی'],
                ['notifications','#9333ea','اطلاع‌رسانی','از هر تغییر مطلع می‌شوی'],
            ];
            foreach ($boxes as [$icon, $color, $title_box, $desc]): ?>
            <div class="acc-privacy-box">
                <div class="icon-wrap" style="background: <?= e($color) ?>22; color: <?= e($color) ?>;">
                    <span class="material-icons"><?= e($icon) ?></span>
                </div>
                <h6><?= e($title_box) ?></h6>
                <p><?= e($desc) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <main class="acc-content-card">
            <h2>۱. اطلاعاتی که جمع‌آوری می‌کنیم</h2>
            <p>برای ارائه خدمات، اطلاعات زیر را جمع‌آوری می‌کنیم:</p>
            <ul>
                <li>نام، نام خانوادگی، ایمیل و شماره موبایل هنگام ثبت‌نام</li>
                <li>اطلاعات هویتی (کارت ملی و سلفی) برای احراز هویت</li>
                <li>اطلاعات کارت‌های بانکی برای پرداخت و برداشت</li>
                <li>آدرس IP، نوع مرورگر و اطلاعات دستگاه برای امنیت</li>
                <li>تاریخچه فعالیت‌ها، تراکنش‌ها و تسک‌ها</li>
            </ul>

            <h2>۲. نحوه استفاده از اطلاعات</h2>
            <p>اطلاعات جمع‌آوری‌شده صرفاً برای اهداف زیر استفاده می‌شود:</p>
            <ul>
                <li>احراز هویت و جلوگیری از تقلب</li>
                <li>پردازش تراکنش‌های مالی</li>
                <li>ارسال اعلان‌ها و اطلاعیه‌های مهم</li>
                <li>بهبود خدمات و تجربه کاربری</li>
                <li>رعایت الزامات قانونی</li>
            </ul>

            <h2>۳. حفاظت از اطلاعات</h2>
            <ul>
                <li>تمامی ارتباطات با SSL/TLS رمزگذاری می‌شوند</li>
                <li>رمزهای عبور با الگوریتم Argon2id هش می‌شوند</li>
                <li>اطلاعات پرداخت با استانداردهای PCI-DSS حفظ می‌شوند</li>
                <li>دسترسی به اطلاعات محدود به کارکنان مجاز است</li>
                <li>سیستم تشخیص نفوذ ۲۴ ساعته فعال است</li>
            </ul>

            <h2>۴. اشتراک‌گذاری اطلاعات</h2>
            <p>اطلاعات شما <strong>هرگز</strong> به دلایل تجاری به اشخاص ثالث فروخته نمی‌شود. اطلاعات فقط در موارد زیر ممکن است به اشتراک گذاشته شود:</p>
            <ul>
                <li>الزامات قانونی و دستورات قضایی</li>
                <li>درگاه‌های پرداخت برای پردازش تراکنش‌ها</li>
                <li>سرویس‌های امنیتی برای جلوگیری از تقلب</li>
            </ul>

            <h2>۵. حقوق کاربران</h2>
            <ul>
                <li><strong>دسترسی:</strong> می‌توانی کلیه اطلاعات خود را مشاهده کنی</li>
                <li><strong>اصلاح:</strong> اطلاعات نادرست را از طریق پنل تصحیح کن</li>
                <li><strong>حذف:</strong> با درخواست به پشتیبانی، حساب و داده‌هایت حذف می‌شود</li>
                <li><strong>محدودیت پردازش:</strong> می‌توانی درخواست محدودیت پردازش بدی</li>
            </ul>

            <h2>۶. کوکی‌ها</h2>
            <p>سایت از کوکی‌های ضروری برای عملکرد و از کوکی‌های تحلیلی برای بهبود تجربه استفاده می‌کند. می‌توانی کوکی‌های غیرضروری را در مرورگر خود غیرفعال کنی.</p>

            <h2>۷. تغییرات در سیاست</h2>
            <p>هرگونه تغییر در این سیاست از طریق ایمیل و اعلان در پنل کاربری اطلاع‌رسانی می‌شود. ادامه استفاده از سایت پس از اطلاع‌رسانی به منزله پذیرش تغییرات است.</p>

            <h2>۸. تماس</h2>
            <p>برای سوالات مرتبط با حریم خصوصی می‌توانی از طریق <a href="<?= url('/contact') ?>">صفحه تماس با ما</a> یا ایمیل <a href="mailto:<?= e(setting('contact_email','privacy@chortke.ir')) ?>"><?= e(setting('contact_email','privacy@chortke.ir')) ?></a> با ما در ارتباط باشی.</p>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>