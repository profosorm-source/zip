<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #11998e 0%, #38ef7d 100%)"; $headerTitle="خوش آمدید! 🎉"; include __DIR__ . "/_header.php"; ?>
        
        <div class="content">
            <h2>سلام <?= e($name ?? $email ?? 'کاربر گرامی') ?>!</h2>
            
            <p>
                از اینکه به خانواده چرتکه پیوستید خوشحالیم! حساب کاربری شما با موفقیت ایجاد شد.
            </p>
            
            <h3>چه کارهایی می‌توانید انجام دهید؟</h3>
            
            <div class="feature">
                <strong>💼 انجام تسک‌ها</strong><br>
                با انجام تسک‌های ساده درآمد کسب کنید
            </div>
            
            <div class="feature">
                <strong>📈 سرمایه‌گذاری</strong><br>
                سرمایه‌گذاری امن و کسب سود هفتگی
            </div>
            
            <div class="feature">
                <strong>🎲 قرعه‌کشی روزانه</strong><br>
                شانس برنده شدن جوایز نقدی
            </div>
            
            <div class="feature">
                <strong>👥 دعوت دوستان</strong><br>
                از معرفی دوستان کمیسیون دریافت کنید
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?= url('dashboard') ?>" class="button">
                    شروع کنید
                </a>
            </div>
            
            <p>
                <strong>کد معرف شما:</strong> <?= e($referral_code ?? '') ?>
            </p>
            
            <p style="color: #999; font-size: 12px;">
                این لینک را با دوستان خود به اشتراک بگذارید:<br>
                <?= url('register?ref=' . ($referral_code ?? '')) ?>
            </p>
        </div>
        
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>