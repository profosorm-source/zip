<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #667eea 0%, #764ba2 100%)"; $headerTitle="تأیید ایمیل"; include __DIR__ . "/_header.php"; ?>

        <div class="content">
            <h2>سلام <?= e($name) ?>! 👋</h2>
            <p>از اینکه به جمع چرتکه پیوستید خوشحالیم 🎉</p>
            <p>برای فعال‌سازی حساب کاربری خود، از یکی از دو روش زیر استفاده کنید:</p>

            <!-- روش اول: دکمه لینک -->
            <p><strong>روش اول — کلیک روی دکمه:</strong></p>
            <div style="text-align:center;">
                <a href="<?= e($verify_url) ?>" class="button">✅ تأیید ایمیل</a>
            </div>

            <div class="divider">یا</div>

            <!-- روش دوم: کد ۶ رقمی -->
            <p><strong>روش دوم — وارد کردن کد:</strong></p>
            <p style="font-size:13px;color:#666;">اگر دکمه بالا کار نکرد، این کد را در صفحه تأیید ایمیل وارد کنید:</p>
            <div class="code-box">
                <div class="label">کد تأیید شما</div>
                <div class="code"><?= e($verify_code) ?></div>
            </div>

            <p style="font-size:12px; color:#aaa;">لینک مستقیم:</p>
            <div class="link-box"><?= e($verify_url) ?></div>

            <div class="warning">
                <strong>⚠️ توجه:</strong> اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.
            </div>
        </div>

        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>