<?php
$title = 'حالت ایمن';
ob_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حالت ایمن</title>
    
    
</head>
<body>
    <div class="container">
        <i class="material-icons icon">warning</i>
        <h1>سیستم در حالت ایمن قرار دارد</h1>
        <p>برخی عملیات‌ها موقتاً غیرفعال شده‌اند</p>
    </div>
</body>
</html>

<?php
$content = ob_get_clean();
echo $content;
?>
