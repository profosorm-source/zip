<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #f59e0b 0%, #d97706 100%)"; $headerTitle="🏆 تبریک! شما برنده شدید!"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>اخبار فوق‌العاده! شما در قرعه‌کشی امروز چرتکه <strong>برنده</strong> شدید. 🎉</p>

            <div class="prize-box">
                <div class="confetti">🎊 🎉 🎊</div>
                <div class="label">جایزه شما</div>
                <div class="prize"><?= e($prize ?? '—') ?> تومان</div>
                <div class="confetti">🎊 🎉 🎊</div>
            </div>

            <div class="info-row">
                <span style="color:#666">تاریخ قرعه‌کشی:</span>
                <span style="font-weight:600"><?= e($date ?? to_jalali(date('Y-m-d'))) ?></span>
            </div>
            <div class="info-row">
                <span style="color:#666">وضعیت جایزه:</span>
                <span style="font-weight:600; color:#10b981">واریز شده به کیف پول</span>
            </div>

            <p style="margin-top:20px; color:#666; font-size:14px;">
                جایزه شما به کیف پول تومانی‌تان اضافه شده و می‌توانید همین الان از آن استفاده کنید.
            </p>

            <a href="<?= e($wallet_url ?? '') ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>