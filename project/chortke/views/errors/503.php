<?php
$title = 'در دست تعمیر | چرتکه';
ob_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>در دست تعمیر | چرتکه</title>
    
    <!-- Bootstrap RTL -->
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>" rel="stylesheet">
    
    <!-- Material Icons -->
    
    
</head>
<body>
    <div class="maintenance-container">
        <i class="material-icons maintenance-icon">build_circle</i>
        <h1 class="maintenance-title">سایت در دست تعمیر است</h1>
        <p class="maintenance-description">
            ما در حال بهبود سرویس‌های خود هستیم.<br>
            لطفاً چند لحظه دیگر مراجعه فرمایید.
        </p>
        
        <div class="countdown">
            <div class="countdown-item">
                <span class="countdown-value" id="hours">00</span>
                <span class="countdown-label">ساعت</span>
            </div>
            <div class="countdown-item">
                <span class="countdown-value" id="minutes">00</span>
                <span class="countdown-label">دقیقه</span>
            </div>
            <div class="countdown-item">
                <span class="countdown-value" id="seconds">00</span>
                <span class="countdown-label">ثانیه</span>
            </div>
        </div>
        
        <p class="opacity-80">
            <i class="material-icons align-middle">email</i>
            support@chortke.com
        </p>
    </div>
    
    
</body>
</html>
<?php
$content = ob_get_clean();
echo $content;
?>
