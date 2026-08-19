<?php
$title = 'در حال بروزرسانی | چرتکه';
ob_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>در حال بروزرسانی | چرتکه</title>
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
</head>
<body>
<div class="maintenance-page">
    <div class="maintenance-box">
        <span class="maintenance-icon">⚙️</span>
        <h1 class="maintenance-title">سایت در حال بروزرسانی است</h1>
        <p class="maintenance-desc">
            <?= e($message ?? 'داریم یه چیز باحال برات آماده می‌کنیم!<br>سایت چرتکه به‌زودی با امکانات جدید برمی‌گردد.') ?>
        </p>
        <div class="maintenance-progress">
            <div class="maintenance-progress-bar"></div>
        </div>
        <?php if (!empty($estimatedTime)): ?>
        <p class="icon-sm">زمان تخمینی: <?= e($estimatedTime) ?></p>
        <?php endif; ?>
        <div class="icon-sm">
            برای اطلاعات بیشتر با پشتیبانی تماس بگیرید
        </div>
    </div>
</div>
</body>
</html>

<?php
$content = ob_get_clean();
echo $content;
?>
