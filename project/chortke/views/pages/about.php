<?php
$title = 'درباره چرتکه';
ob_start();
?>
<style>
/* ── استایل‌های یکپارچه صفحات ثابت (acc-wrap) ── */
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
.acc-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); padding: 40px; border: 1px solid #e2e8f0; margin-top: 40px; }
.acc-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-top: 40px; }
@media(max-width:991px){ .acc-grid-3 { grid-template-columns: repeat(2, 1fr); } }
@media(max-width:767px){ .acc-grid-3 { grid-template-columns: 1fr; } .acc-hero { padding: 40px 25px; } }
.acc-feature-box { background: #fff; border-radius: 18px; padding: 30px; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.03); transition: all 0.2s ease; }
.acc-feature-box:hover { transform: translateY(-4px); border-color: #3b82f6; box-shadow: 0 15px 35px -5px rgba(59,130,246,0.15); }
.acc-feature-box .icon-wrap { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; }
.acc-feature-box .material-icons { font-size: 1.8rem; }
.acc-feature-box h5 { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
.acc-feature-box p { font-size: 0.95rem; color: #64748b; line-height: 1.7; margin: 0; }
.acc-stats-banner { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 20px; padding: 50px; color: #fff; margin-top: 50px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px; text-align: center; box-shadow: 0 20px 40px -10px rgba(37,99,235,0.4); }
@media(max-width:991px){ .acc-stats-banner { grid-template-columns: repeat(2, 1fr); padding: 35px; } }
.acc-stat-big__num { font-size: 2.6rem; font-weight: 700; margin-bottom: 8px; }
.acc-stat-big__lbl { font-size: 1.05rem; opacity: 0.9; font-weight: 600; }
.acc-content-box h2, .acc-content-box h3 { font-size: 1.6rem; font-weight: 700; color: #1e293b; margin-bottom: 20px; }
.acc-content-box p { font-size: 1.05rem; color: #64748b; line-height: 1.8; margin-bottom: 20px; }
</style>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">info</i></div>
            <div>
                <div class="acc-hero__eyebrow">Chortke Platform</div>
                <h1 class="acc-hero__title">درباره چرتکه</h1>
                <p class="acc-hero__sub">پلتفرم جامع کسب درآمد آنلاین ایرانی در سطح استانداردهای Enterprise</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/login') ?>" class="acc-btn acc-btn-primary"><i class="material-icons">login</i> ورود به پلتفرم</a>
        </div>
    </section>

    <div class="container">
        <!-- معرفی -->
        <div class="acc-card acc-content-box text-center" style="margin-top: -30px;">
            <img src="<?= asset('images/logo-dark.png') ?>" alt="چرتکه" class="mb-4" style="max-width: 180px;">
            <h2>چرتکه چیست؟</h2>
            <p class="lead" style="max-width: 800px; margin: 0 auto;">چرتکه یک پلتفرم نوین برای کسب درآمد آنلاین است. با انجام تسک، راه‌اندازی کمپین تبلیغاتی، سرمایه‌گذاری تتری، و فعالیت در شبکه‌های اجتماعی می‌توانید درآمد واقعی داشته باشید.</p>
        </div>

        <!-- ویژگی‌ها -->
        <div class="acc-grid-3">
            <?php
            $features = [
                ['task_alt','#4caf50','انجام تسک','از لایک اینستاگرام تا نصب اپ. تسک‌های متنوع با پرداخت فوری.'],
                ['campaign','#2196f3','تبلیغات هدفمند','کمپین تبلیغاتی بسازید و هزاران کاربر واقعی جذب کنید.'],
                ['trending_up','#ff9800','سرمایه‌گذاری تتری','با USDT سرمایه‌گذاری کن و از سود روزانه بهره ببر.'],
                ['auto_stories','#9c27b0','استوری اینفلوئنسر','محتوای خود را به هزاران دنبال‌کننده برسانید.'],
                ['group_add','#e91e63','سیستم معرفی','دوستانت را دعوت کن و از فعالیت آن‌ها کمیسیون بگیر.'],
                ['security','#00bcd4','امنیت بالا','احراز هویت ۲ مرحله‌ای، رمزگذاری SSL و نظارت ۲۴ ساعته.'],
            ];
            foreach ($features as [$icon, $color, $title_f, $desc]): ?>
            <div class="acc-feature-box">
                <div class="icon-wrap" style="background: <?= e($color) ?>22; color: <?= e($color) ?>;">
                    <span class="material-icons"><?= e($icon) ?></span>
                </div>
                <h5><?= e($title_f) ?></h5>
                <p><?= e($desc) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- آمارها -->
        <div class="acc-stats-banner">
            <?php
            $stats = [['۵۰,۰۰۰+', 'کاربر فعال'], ['۱,۰۰۰,۰۰۰+', 'تسک انجام شده'], ['۲۰۰+', 'تبلیغ‌دهنده'], ['۹۸٪', 'رضایت کاربران']];
            foreach ($stats as [$num, $label]): ?>
            <div class="acc-stat-big"><div class="acc-stat-big__num"><?= e($num) ?></div><div class="acc-stat-big__lbl"><?= e($label) ?></div></div>
            <?php endforeach; ?>
        </div>

        <!-- ارزش‌ها -->
        <div class="acc-card acc-content-box text-center">
            <h3>ارزش‌های ما</h3>
            <p style="margin:0;">ما در چرتکه باور داریم که هر ایرانی شایسته دسترسی به فرصت‌های واقعی کسب درآمد دیجیتال است.</p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>