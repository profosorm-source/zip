<?php
$title = 'قوانین و مقررات';
ob_start();
?>
<style>
/* ── استایل‌های یکپارچه قوانین و مقررات (acc-wrap) ── */
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
.acc-grid-legal { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 35px; margin-top: -30px; }
@media(max-width:991px){ .acc-grid-legal { grid-template-columns: minmax(0, 1fr); } }
.acc-toc-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 35px 30px; border: 1px solid #e2e8f0; height: fit-content; position: sticky; top: 25px; }
.acc-toc-card h6 { font-size: 1.2rem; font-weight: 700; color: #1e293b; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.acc-toc-card h6 .material-icons { color: #2563eb; }
.acc-toc-card ol { padding-right: 22px; margin: 0; display: flex; flex-direction: column; gap: 14px; }
.acc-toc-card a { color: #64748b; text-decoration: none; font-size: 0.95rem; font-weight: 600; transition: all 0.2s ease; }
.acc-toc-card a:hover { color: #2563eb; }
.acc-content-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 50px; border: 1px solid #e2e8f0; }
@media(max-width:767px){ .acc-content-card { padding: 30px 22px; } .acc-hero { padding: 40px 25px; } }
.acc-alert-box { padding: 20px 25px; background: #eff6ff; border-right: 4px solid #2563eb; border-radius: 14px; display: flex; align-items: center; gap: 18px; margin-bottom: 40px; color: #1e293b; font-size: 0.95rem; font-weight: 600; line-height: 1.7; }
.acc-alert-box .material-icons { color: #2563eb; font-size: 2.2rem; flex-shrink: 0; }
.terms-section { margin-bottom: 50px; }
.terms-section:last-child { margin-bottom: 0; }
.terms-section h2 { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 22px; display: flex; align-items: center; gap: 14px; }
.terms-section-number { width: 38px; height: 38px; background: #2563eb; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; flex-shrink: 0; }
.terms-section p { font-size: 1rem; color: #475569; line-height: 1.8; margin-bottom: 16px; }
.terms-section ul { padding-right: 22px; margin: 0; display: flex; flex-direction: column; gap: 12px; }
.terms-section li { font-size: 1rem; color: #475569; line-height: 1.7; }
.terms-tip { padding: 18px 22px; background: #fef3c7; border-right: 4px solid #f59e0b; border-radius: 12px; margin: 20px 0; color: #92400e; font-size: 0.95rem; font-weight: 600; }
</style>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">gavel</i></div>
            <div>
                <div class="acc-hero__eyebrow">Chortke Platform</div>
                <h1 class="acc-hero__title">قوانین و مقررات</h1>
                <p class="acc-hero__sub">لطفاً پیش از استفاده از خدمات چرتکه این قوانین را مطالعه کنید</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/login') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">login</i> ورود به پلتفرم</a>
        </div>
    </section>

    <div class="container">
        <div class="acc-grid-legal">
            
            <!-- فهرست مطالب -->
            <aside class="acc-toc-card">
                <h6><span class="material-icons">list</span> فهرست مطالب</h6>
                <ol>
                    <li><a href="#section-1">کلیات و تعاریف</a></li>
                    <li><a href="#section-2">شرایط ثبت‌نام</a></li>
                    <li><a href="#section-3">تعهدات کاربر</a></li>
                    <li><a href="#section-4">قوانین مالی و کیف پول</a></li>
                    <li><a href="#section-5">تسک‌ها و فعالیت‌ها</a></li>
                    <li><a href="#section-6">سرمایه‌گذاری</a></li>
                    <li><a href="#section-7">ممنوعیت‌ها</a></li>
                    <li><a href="#section-8">تغییرات و خاتمه</a></li>
                </ol>
            </aside>

            <!-- محتوای اصلی -->
            <main class="acc-content-card">
                <div class="acc-alert-box">
                    <span class="material-icons">info</span>
                    <div>با ثبت‌نام در چرتکه، شما این قوانین را خوانده و پذیرفته‌اید. آخرین بروزرسانی: <strong>بهار ۱۴۰۴</strong></div>
                </div>

                <div id="section-1" class="terms-section">
                    <h2><span class="terms-section-number">۱</span> کلیات و تعاریف</h2>
                    <p>چرتکه یک پلتفرم آنلاین برای کسب درآمد از طریق انجام تسک، تبلیغات، سرمایه‌گذاری و سایر فعالیت‌های دیجیتال است.</p>
                    <ul>
                        <li>«سایت» به چرتکه و تمامی زیرمجموعه‌های آن اطلاق می‌شود</li>
                        <li>«کاربر» هر شخص حقیقی است که در سایت ثبت‌نام کرده</li>
                        <li>«تسک» به فعالیت‌هایی گفته می‌شود که با انجام آن‌ها درآمد کسب می‌کنید</li>
                        <li>«موجودی» به مبلغ تومانی یا تتری در کیف پول کاربر گفته می‌شود</li>
                    </ul>
                </div>

                <div id="section-2" class="terms-section">
                    <h2><span class="terms-section-number">۲</span> شرایط ثبت‌نام</h2>
                    <ul>
                        <li>حداقل سن ثبت‌نام ۱۸ سال تمام است</li>
                        <li>هر شخص فقط مجاز به داشتن یک حساب کاربری است</li>
                        <li>ثبت‌نام با اطلاعات واقعی و صحیح الزامی است</li>
                        <li>استفاده از VPN یا IP خارجی ممنوع است</li>
                        <li>شماره موبایل معتبر ایرانی الزامی است</li>
                    </ul>
                </div>

                <div id="section-3" class="terms-section">
                    <h2><span class="terms-section-number">۳</span> تعهدات کاربر</h2>
                    <ul>
                        <li>انجام صحیح و واقعی تسک‌ها (بدون استفاده از ربات یا ابزار خودکار)</li>
                        <li>عدم ثبت اطلاعات جعلی یا تکراری</li>
                        <li>رعایت قوانین شبکه‌های اجتماعی هنگام انجام تسک</li>
                        <li>عدم سوءاستفاده از سیستم معرفی (referral)</li>
                        <li>حفظ محرمانگی اطلاعات حساب کاربری</li>
                    </ul>
                </div>

                <div id="section-4" class="terms-section">
                    <h2><span class="terms-section-number">۴</span> قوانین مالی و کیف پول</h2>
                    <ul>
                        <li>واریز وجه فقط از طریق درگاه‌های رسمی سایت مجاز است</li>
                        <li>برداشت روزانه محدود به یک بار است</li>
                        <li>احراز هویت (KYC) برای برداشت وجه الزامی است</li>
                        <li>واریز کارت به کارت فقط از کارت بانکی ثبت‌شده کاربر مجاز است</li>
                        <li>سایت در قبال تراکنش‌های ناموفق ناشی از خطای کاربر مسئولیتی ندارد</li>
                        <li>کارمزد برداشت طبق تعرفه‌ای است که در پنل مدیریت اعلام می‌شود</li>
                    </ul>
                </div>

                <div id="section-5" class="terms-section">
                    <h2><span class="terms-section-number">۵</span> تسک‌ها و فعالیت‌ها</h2>
                    <ul>
                        <li>انجام هر تسک باید با مدرک معتبر (تصویر/ویدیو) همراه باشد</li>
                        <li>مدرک جعلی یا تکراری منجر به تعلیق حساب می‌شود</li>
                        <li>هر تسک دارای مهلت انجام مشخص است</li>
                        <li>درآمد تسک‌ها پس از تأیید مدیریت به کیف پول اضافه می‌شود</li>
                        <li>اعتراض به رد شدن تسک باید ظرف ۴۸ ساعت از طریق تیکت ارسال شود</li>
                    </ul>
                </div>

                <div id="section-6" class="terms-section">
                    <h2><span class="terms-section-number">۶</span> سرمایه‌گذاری</h2>
                    <div class="terms-tip">
                        <strong>توجه:</strong> سرمایه‌گذاری در چرتکه دارای ریسک است. مقدار سود و زیان طبق اعلام مدیریت اعمال می‌شود.
                    </div>
                    <ul>
                        <li>سرمایه‌گذاری بر اساس تتر (USDT) انجام می‌شود</li>
                        <li>برداشت سود فقط هفتگی امکان‌پذیر است</li>
                        <li>سرمایه‌گذاری در هر پلن تا پایان دوره قابل برداشت نیست</li>
                        <li>سود و زیان روزانه توسط تیم تریدینگ اعلام می‌شود</li>
                    </ul>
                </div>

                <div id="section-7" class="terms-section">
                    <h2><span class="terms-section-number">۷</span> ممنوعیت‌ها</h2>
                    <ul>
                        <li>استفاده از ابزار، ربات یا نرم‌افزار خودکار</li>
                        <li>داشتن بیش از یک حساب کاربری</li>
                        <li>تبانی بین کاربران برای کسب سود غیرواقعی</li>
                        <li>پول‌شویی یا هرگونه فعالیت غیرقانونی</li>
                        <li>انتشار محتوای غیراخلاقی یا غیرقانونی</li>
                        <li>فروش یا واگذاری حساب کاربری به دیگران</li>
                    </ul>
                </div>

                <div id="section-8" class="terms-section">
                    <h2><span class="terms-section-number" style="background:#dc2626;">۸</span> تغییرات و خاتمه</h2>
                    <ul>
                        <li>چرتکه حق تغییر این قوانین را در هر زمان دارد</li>
                        <li>تغییرات از طریق اعلانیه در سایت اطلاع‌رسانی می‌شود</li>
                        <li>نقض قوانین می‌تواند منجر به تعلیق یا حذف حساب شود</li>
                        <li>در صورت تعلیق، موجودی پس از بررسی قابل برداشت است</li>
                    </ul>
                </div>

            </main>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>