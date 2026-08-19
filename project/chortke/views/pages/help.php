<?php
$title = 'راهنمای جامع و آموزش ویدیویی سایت';
ob_start();
?>
<style>
/* ── استایل‌های اختصاصی هاب آموزشی چرتکه (SPA Help Hub) ── */
.help-spa-wrap {
    font-family: Tahoma, 'IRANSans', sans-serif;
    direction: rtl;
    background: #f8f9fa;
    padding-bottom: 80px;
}
.help-spa-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    padding: 60px 0 40px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.help-spa-hero h1 {
    font-size: 2.2rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.help-spa-hero p {
    font-size: 1.1rem;
    color: #94a3b8;
    max-width: 600px;
    margin: 0 auto;
}
.help-spa-grid {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 30px;
    margin-top: -30px;
}
@media (max-width: 991px) {
    .help-spa-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
/* سایدبار ناوبری */
.help-spa-sidebar {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
    padding: 20px 16px;
    height: fit-content;
    position: sticky;
    top: 25px;
}
.help-spa-sidebar .nav-pills {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.help-spa-sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    color: #475569;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 600;
    text-align: right;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}
.help-spa-sidebar .nav-link:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.help-spa-sidebar .nav-link.active {
    background: #2563eb;
    color: #fff;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
}
.help-spa-sidebar .nav-link .material-icons {
    font-size: 1.3rem;
    transition: transform 0.2s ease;
}
.help-spa-sidebar .nav-link.active .material-icons {
    transform: scale(1.1);
}
/* پنل اصلی محتوا */
.help-spa-content {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
    padding: 40px;
}
@media (max-width: 767px) {
    .help-spa-content {
        padding: 25px;
    }
}
.help-section-title {
    font-size: 1.6rem;
    font-weight: 700;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 16px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.help-section-title .material-icons {
    color: #2563eb;
    font-size: 2rem;
}
/* باکس شبیه‌ساز پلیر ویدیویی (Video Embed Simulator) */
.help-video-box {
    position: relative;
    background: #1e293b;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 35px;
    box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255,255,255,0.1);
}
.help-video-thumb {
    width: 100%;
    height: 340px;
    background: radial-gradient(circle, #334155 0%, #0f172a 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-align: center;
    padding: 20px;
}
.help-play-btn {
    width: 80px;
    height: 80px;
    background: #2563eb;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.5);
    cursor: pointer;
    transition: all 0.2s ease;
    margin-bottom: 20px;
}
.help-play-btn .material-icons {
    font-size: 3.2rem;
    margin-left: 6px;
}
.help-play-btn:hover {
    transform: scale(1.1);
    background: #3b82f6;
}
.help-video-title {
    font-size: 1.3rem;
    font-weight: 700;
    margin-bottom: 8px;
}
.help-video-meta {
    font-size: 0.9rem;
    color: #94a3b8;
}
/* مراحل آموزشی متنی */
.help-step-card {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 24px;
    background: #f8f9fa;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    margin-bottom: 20px;
    transition: transform 0.2s ease;
}
.help-step-card:hover {
    transform: translateY(-2px);
    background: #fff;
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.04);
}
.help-step-number {
    width: 44px;
    height: 44px;
    background: #2563eb;
    color: #fff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: 700;
    flex-shrink: 0;
}
.help-step-content h4 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 10px;
    margin-top: 5px;
}
.help-step-content p {
    font-size: 0.95rem;
    color: #64748b;
    line-height: 1.7;
    margin: 0;
}
/* باکس هشداری و نکات */
.help-spa-tip {
    padding: 20px 25px;
    background: #eff6ff;
    border-right: 4px solid #2563eb;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 30px;
    color: #1e293b;
    font-size: 0.95rem;
    font-weight: 600;
}
.help-spa-tip .material-icons {
    color: #2563eb;
    font-size: 2rem;
    flex-shrink: 0;
}
</style>

<div class="help-spa-wrap">
    <!-- هیرو بار -->
    <section class="help-spa-hero">
        <div class="container">
            <h1><span class="material-icons">school</span> آموزش جامع و ویدیویی پلتفرم چرتکه</h1>
            <p>در این هاب آموزشی، بدون نیاز به باز کردن صفحات متعدد، با کارکرد تمامی ماژول‌ها و ابزارهای سیستم آشنا شوید.</p>
        </div>
    </section>

    <!-- ساختار تک‌صفحه‌ای (SPA Tabbed Hub) -->
    <div class="container">
        <div class="help-spa-grid">
            
            <!-- سایدبار بخش‌ها -->
            <aside class="help-spa-sidebar">
                <div class="nav nav-pills" id="help-tab" role="tablist" aria-orientation="vertical">
                    <button class="nav-link active" id="tab-start" data-bs-toggle="pill" data-bs-target="#content-start" type="button" role="tab"><span class="material-icons">rocket_launch</span> ۱. شروع کار و ثبت‌نام</button>
                    <button class="nav-link" id="tab-kyc" data-bs-toggle="pill" data-bs-target="#content-kyc" type="button" role="tab"><span class="material-icons">verified_user</span> ۲. احراز هویت (KYC)</button>
                    <button class="nav-link" id="tab-wallet" data-bs-toggle="pill" data-bs-target="#content-wallet" type="button" role="tab"><span class="material-icons">account_balance_wallet</span> ۳. کیف‌پول، شارژ و برداشت</button>
                    <button class="nav-link" id="tab-tasks" data-bs-toggle="pill" data-bs-target="#content-tasks" type="button" role="tab"><span class="material-icons">task_alt</span> ۴. انجام تسک‌های تعاملی</button>
                    <button class="nav-link" id="tab-ads" data-bs-toggle="pill" data-bs-target="#content-ads" type="button" role="tab"><span class="material-icons">campaign</span> ۵. ساخت کمپین تبلیغاتی</button>
                    <button class="nav-link" id="tab-influencer" data-bs-toggle="pill" data-bs-target="#content-influencer" type="button" role="tab"><span class="material-icons">stars</span> ۶. بازارچه‌ی اینفلوئنسرها</button>
                    <button class="nav-link" id="tab-invest" data-bs-toggle="pill" data-bs-target="#content-invest" type="button" role="tab"><span class="material-icons">trending_up</span> ۷. سرمایه‌گذاری و دریافت سود</button>
                    <button class="nav-link" id="tab-referral" data-bs-toggle="pill" data-bs-target="#content-referral" type="button" role="tab"><span class="material-icons">group_add</span> ۸. معرفی دوستان و کمیسیون</button>
                    <button class="nav-link" id="tab-support" data-bs-toggle="pill" data-bs-target="#content-support" type="button" role="tab"><span class="material-icons">support_agent</span> ۹. پشتیبانی، تیکت و داوری</button>
                    <button class="nav-link" id="tab-security" data-bs-toggle="pill" data-bs-target="#content-security" type="button" role="tab"><span class="material-icons">shield</span> ۱۰. امنیت حساب و 2FA</button>
                </div>
            </aside>

            <!-- پنل محتوا -->
            <main class="help-spa-content tab-content" id="help-tabContent">
                
                <!-- ۱. شروع کار و ثبت‌نام -->
                <div class="tab-pane fade show active" id="content-start" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">rocket_launch</span> آموزش شروع کار و ثبت‌نام در چرتکه</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: معرفی پلتفرم چرتکه و ثبت‌نام')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: راهنمای گام‌به‌گام ثبت‌نام و ورود به پلتفرم</div>
                            <div class="help-video-meta">مدت زمان: ۳ دقیقه و ۲۵ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ورود به صفحه ثبت‌نام</h4><p>با استفاده از دکمه «ثبت‌نام» در منوی اصلی، وارد فرم ثبت‌نام شوید. اطلاعات اولیه شامل نام و نام خانوادگی، شماره موبایل و ایمیل خود را به دقت وارد کنید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>تایید حساب کاربری</h4><p>پس از ارسال فرم، یک کد تایید به ایمیل یا شماره موبایل شما ارسال می‌شود. با وارد کردن این کد، حساب شما به صورت رسمی فعال خواهد شد.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۳</div><div class="help-step-content"><h4>ورود به داشبورد</h4><p>پس از تایید حساب، وارد داشبورد کاربری خود شوید. در داشبورد می‌توانید وضعیت مالی، رتبه کاربری و تسک‌های در دسترس را مشاهده کنید.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">lightbulb</span>نکته مهم: برای دریافت پاداش اولیه ورود، حتماً اطلاعات پروفایل خود شامل آواتار و تنظیمات پایه را تکمیل کنید.</div>
                </div>

                <!-- ۲. احراز هویت (KYC) -->
                <div class="tab-pane fade" id="content-kyc" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">verified_user</span> راهنمای احراز هویت (KYC)</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: تکمیل احراز هویت و ارسال مدارک')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: نحوه صحیح آپلود مدارک هویتی و تایید حساب</div>
                            <div class="help-video-meta">مدت زمان: ۴ دقیقه و ۱۰ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ورود به بخش KYC</h4><p>از منوی تنظیمات کاربری وارد بخش احراز هویت شوید. برای ثبت درخواست برداشت وجه و فعالیت در بازارچه ویترین، تکمیل این مرحله الزامی است.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>ارسال مشخصات فردی</h4><p>کد ملی، تاریخ تولد و تصویر کارت ملی یا شناسنامه خود را ارسال کنید. سیستم به صورت هوشمند و از طریق آداپتور هوش مصنوعی (DeepFace) اطلاعات شما را پردازش می‌کند.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">gpp_good</span>توجه: تمامی اطلاعات هویتی شما به صورت رمزنگاری‌شده (AES-256-GCM) ذخیره می‌شوند و تیم حقوقی چرتکه امنیت مطلق آن‌ها را تضمین می‌کند.</div>
                </div>

                <!-- ۳. کیف‌پول، شارژ و برداشت -->
                <div class="tab-pane fade" id="content-wallet" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">account_balance_wallet</span> آموزش امور مالی، شارژ و برداشت وجه</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: مدیریت کیف‌پول، واریز تتری و برداشت وجه')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: آموزش شارژ کیف‌پول از طریق درگاه‌های ریالی و تتری (USDT)</div>
                            <div class="help-video-meta">مدت زمان: ۶ دقیقه و ۱۵ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>شارژ کیف‌پول ریالی</h4><p>از طریق درگاه‌های پرداخت امن زرین‌پال، نکست‌پی، آیدی‌پی یا دیجی‌پی می‌توانید موجودی کیف‌پول تومانی خود را به صورت آنی افزایش دهید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>واریز تتری (USDT)</h4><p>در بخش واریز رمزارز، شبکه‌های TRC20، TON، SOL یا BNB20 را انتخاب کرده، مبلغ را به آدرس ولت سایت واریز کنید و هش تراکنش را ثبت نمایید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۳</div><div class="help-step-content"><h4>درخواست برداشت وجه</h4><p>با انتخاب کارت بانکی تاییدشده خود (با اعتبارسنجی خودکار شبا و الگوریتم لوهن)، درخواست برداشت را ثبت کنید. تسویه‌حساب‌ها در کمتر از ۲۴ ساعت کاری واریز می‌شوند.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">account_balance</span>حداقل مبلغ برداشت برای موجودی فیات ۵۰,۰۰۰ تومان و برای موجودی تتری ۱۰ دلار می‌باشد.</div>
                </div>

                <!-- ۴. انجام تسک‌های تعاملی -->
                <div class="tab-pane fade" id="content-tasks" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">task_alt</span> راهنمای انجام تسک‌ها و کسب درآمد</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: انجام تسک‌های تعاملی و ارسال اثبات')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: نحوه انتخاب تسک، ارسال مدرک (اسکرین‌شات) و دریافت دستمزد</div>
                            <div class="help-video-meta">مدت زمان: ۵ دقیقه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>انتخاب تسک از فید</h4><p>وارد بخش تسک‌های موجود شوید. تسک‌ها شامل موارد تنوعی نظیر فالو اینستاگرام، تماشای ویدیو یوتیوب (AdTube)، نصب اپلیکیشن یا جستجوی گوگل (SEO) هستند.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>شروع و اجرای تسک</h4><p>روی دکمه «شروع تسک» کلیک کنید تا ظرفیت برای شما رزرو شود. دستورالعمل کارفرما را مطالعه کرده و کار را انجام دهید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۳</div><div class="help-step-content"><h4>ارسال اثبات (Proof)</h4><p>اسکرین‌شات انجام کار یا آدرس خواسته شده را ارسال کنید. پس از بررسی کارفرما، مبلغ به صورت خودکار از حساب امانی (Escrow) به کیف‌پول شما منتقل می‌شود.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">speed</span>در صورت عدم تایید یا رد بی‌دلیل کارفرما، می‌توانید از سیستم داوری (Dispute) جهت احقاق حق خود استفاده کنید.</div>
                </div>

                <!-- ۵. ساخت کمپین تبلیغاتی -->
                <div class="tab-pane fade" id="content-ads" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">campaign</span> آموزش ساخت کمپین تبلیغاتی</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: راه‌اندازی کمپین تبلیغاتی و هدفمندسازی')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: راه‌اندازی کمپین‌های تبلیغات تعاملی، سئو و ویدیویی</div>
                            <div class="help-video-meta">مدت زمان: ۷ دقیقه و ۱۰ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ایجاد کمپین جدید</h4><p>روی دکمه «ساخت کمپین» کلیک کنید. نوع تبلیغ (تسک اجتماعی، بازدید ویدیویی AdTube یا ورودی گوگل SEO) را مشخص کنید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>تنظیم بودجه و پاداش</h4><p>پاداش مجری به ازای هر انجام و تعداد کل سفارش را مشخص کنید. سیستم به صورت هوشمند هزینه کل را محاسبه کرده و در حساب امانی مسدود می‌کند.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">info</span>در صورت توقف یا لغو کمپین توسط شما، تمامی بودجه باقی‌مانده فوراً به کیف‌پول شما بازگردانده می‌شود.</div>
                </div>

                <!-- ۶. بازارچه‌ی اینفلوئنسرها -->
                <div class="tab-pane fade" id="content-influencer" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">stars</span> آموزش فعالیت در بازارچه‌ی اینفلوئنسرها</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: ثبت پروفایل اینفلوئنسر و دریافت تبلیغات')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: راهنمای ثبت پیج اینستاگرامی و کانال تلگرامی در بازارچه‌ی چرتکه</div>
                            <div class="help-video-meta">مدت زمان: ۵ دقیقه و ۴۵ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ثبت پروفایل اجتماعی</h4><p>اگر دارای پیج اینستاگرام، کانال تلگرام یا یوتیوب با مخاطب واقعی هستید، مشخصات پروفایل و تعرفه تبلیغاتی خود را در سیستم ثبت کنید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>تایید حساب با کد OTP</h4><p>برای اثبات مالکیت، کد یک‌بار‌مصرف ارائه‌شده توسط سیستم را موقتاً در بیوی پیج یا کاپشن پست خود قرار داده و درخواست احراز را بزنید.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">front_hand</span>پس از تایید، تبلیغ‌دهندگان می‌توانند به صورت مستقیم درخواست انتشار استوری یا پست را برای شما ارسال کنند.</div>
                </div>

                <!-- ۷. سرمایه‌گذاری و دریافت سود -->
                <div class="tab-pane fade" id="content-invest" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">trending_up</span> راهنمای سرمایه‌گذاری تتری و دریافت سود</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: سرمایه‌گذاری با تتر و مشاهده صندوق سود')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: معرفی طرح‌های سرمایه‌گذاری USDT و سازوکار تخصیص سود روزانه</div>
                            <div class="help-video-meta">مدت زمان: ۶ دقیقه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>انتخاب طرح سرمایه‌گذاری</h4><p>در پنل سرمایه‌گذاری، طرح‌های موجود بر پایه تتر (USDT) را بررسی کنید. سود حاصله بر پایه فعالیت‌های تبلیغاتی پلتفرم محاسبه می‌شود.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>تخصیص و واریز وجه</h4><p>با انتقال تتر از کیف‌پول خود به صندوق سرمایه‌گذاری، قرارداد فعال می‌شود. سود دوره‌ای به صورت خودکار به ولت شما واریز خواهد شد.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">analytics</span>صندوق سرمایه‌گذاری چرتکه دارای سیستم گزارش‌دهی شفاف (Solvency Report) است که امنیت دارایی شما را کاملاً پایش می‌کند.</div>
                </div>

                <!-- ۸. معرفی دوستان و کمیسیون -->
                <div class="tab-pane fade" id="content-referral" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">group_add</span> آموزش سیستم زیرمجموعه‌گیری و دریافت کمیسیون</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: زیرمجموعه‌گیری و ارتقای سطح برنز تا پلاتینوم')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: آموزش معرفی دوستان، دریافت لینک اختصاصی و ساخت درآمد غیرفعال</div>
                            <div class="help-video-meta">مدت زمان: ۴ دقیقه و ۵۰ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>دریافت لینک دعوت</h4><p>در بخش معرفی دوستان، لینک اختصاصی یا کد معرف خود را دریافت کرده و با دوستان خود در شبکه‌های اجتماعی به اشتراک بگذارید.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>دریافت کمیسیون مادام‌العمر</h4><p>با هر فعالیت مالی زیرمجموعه‌ها (انجام تسک یا راه‌اندازی کمپین)، درصدی از هزینه به عنوان پورسانت به صورت آنی به حساب شما واریز می‌شود.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">military_tech</span>با افزایش تعداد زیرمجموعه‌ها، رتبه کاربری شما از برنز به پلاتینوم ارتقا یافته و پاداش‌های چندسطحی دریافت خواهید کرد.</div>
                </div>

                <!-- ۹. پشتیبانی، تیکت و داوری -->
                <div class="tab-pane fade" id="content-support" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">support_agent</span> راهنمای پشتیبانی، تیکتینگ و داوری (Dispute)</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: ثبت تیکت پشتیبانی و درخواست داوری در تسک‌ها')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: نحوه ارتباط با تیم داوری چرتکه و حل اختلاف در تسک‌ها</div>
                            <div class="help-video-meta">مدت زمان: ۳ دقیقه و ۵۰ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ثبت تیکت پشتیبانی</h4><p>در صورت بروز هرگونه مشکل در حساب کاربری یا واریزها، از بخش تیکت‌ها درخواست خود را ثبت کنید. تیم پشتیبانی در کمتر از چند ساعت پاسخگو خواهد بود.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>درخواست داوری (Dispute)</h4><p>اگر در اجرای تسک‌ها یا معاملات ویترین میان کارفرما و مجری اختلافی رخ دهد، با فشردن دکمه داوری، تیم قضایی چرتکه مدارک را بررسی کرده و وجه را به حساب ذی‌حق واریز می‌کند.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">forum</span>برای سوالات سریع و لحظه‌ای می‌توانید از طریق کانال و آیدی رسمی تلگرام چرتکه با اپراتورها در تماس باشید.</div>
                </div>

                <!-- ۱۰. امنیت حساب و 2FA -->
                <div class="tab-pane fade" id="content-security" role="tabpanel" tabindex="0">
                    <div class="help-section-title"><span class="material-icons">shield</span> آموزش امنیت حساب و احراز دو مرحله‌ای (2FA)</div>
                    <div class="help-video-box">
                        <div class="help-video-thumb">
                            <div class="help-play-btn" onclick="alert('پخش آموزش ویدیویی: فعال‌سازی Google Authenticator و حفاظت حساب')"><span class="material-icons">play_arrow</span></div>
                            <div class="help-video-title">ویدیوی آموزشی: ایمن‌سازی اکانت با اپلیکیشن Google Authenticator و Authy</div>
                            <div class="help-video-meta">مدت زمان: ۵ دقیقه و ۳۰ ثانیه • کیفیت 1080p HD</div>
                        </div>
                    </div>
                    <div class="help-step-card"><div class="help-step-number">۱</div><div class="help-step-content"><h4>ورود به بخش 2FA</h4><p>از منوی تنظیمات امنیتی وارد بخش احراز دو مرحله‌ای شوید. با وارد کردن رمز عبور فعلی، مجوز ۱۰ دقیقه‌ای برای مشاهده QR کد صادر می‌شود.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۲</div><div class="help-step-content"><h4>اسکن با اپلیکیشن</h4><p>اپلیکیشن Google Authenticator را در گوشی باز کرده، QR کد را اسکن کنید و کد ۶ رقمی تولید شده را در سایت وارد کنید تا قفل امنیتی فعال شود.</p></div></div>
                    <div class="help-step-card"><div class="help-step-number">۳</div><div class="help-step-content"><h4>نگهداری کدهای بازیابی</h4><p>سیستم ۸ عدد کد بازیابی ۲۴ کاراکتری به شما ارائه می‌دهد. این کدها را حتماً در جای امن ذخیره کنید تا در صورت سرقت یا گم شدن گوشی، بتوانید وارد حساب خود شوید.</p></div></div>
                    <div class="help-spa-tip"><span class="material-icons">phonelink_lock</span>کدهای 2FA کاملاً آفلاین و بر پایه ساعت گوشی شما کار می‌کنند و امنیت اکانت شما را در برابر حملات سرقت پسورد تضمین می‌نمایند.</div>
                </div>

            </main>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>