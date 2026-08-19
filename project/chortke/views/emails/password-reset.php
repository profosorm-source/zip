<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #f093fb 0%, #f5576c 100%)"; $headerTitle="بازیابی رمز عبور"; include __DIR__ . "/_header.php"; ?>
        
        <div class="content">
            <h2>بازیابی رمز عبور</h2>
            
            <p>
                درخواست بازیابی رمز عبور برای حساب شما دریافت شد.
            </p>
            
            <p>
                برای تنظیم رمز عبور جدید، روی دکمه زیر کلیک کنید:
            </p>
            
            <div style="text-align: center;">
                <a href="<?= e($reset_url) ?>" class="button">
                    بازیابی رمز عبور
                </a>
            </div>
            
            <p style="color: #999; font-size: 12px;">
                اگر دکمه کار نکرد، لینک زیر را کپی کرده و در مرورگر باز کنید:
                <br>
                <a href="<?= e($reset_url) ?>"><?= e($reset_url) ?></a>
            </p>
            
            <div class="info-box">
                <strong>ℹ️ نکته:</strong>
                این لینک فقط برای <strong>1 ساعت</strong> معتبر است.
            </div>
            
            <div class="warning">
                <strong>⚠️ هشدار امنیتی:</strong>
                اگر شما درخواست بازیابی رمز نداده‌اید، این ایمیل را نادیده بگیرید 
                و فوراً رمز عبور خود را تغییر دهید.
            </div>
        </div>
        
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>