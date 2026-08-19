<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #10b981 0%, #059669 100%)"; $headerTitle="✅ برداشت شما تأیید شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>درخواست برداشت شما با موفقیت پردازش و مبلغ به حساب شما واریز شد.</p>

            <div class="amount-box">
                <div class="label">مبلغ برداشت‌شده</div>
                <div class="amount"><?= e($amount ?? '—') ?> <?= e($currency === 'usdt' ? 'USDT' : 'تومان') ?></div>
            </div>

            <div class="info-row">
                <span class="info-label">تاریخ پردازش:</span>
                <span class="info-value"><?= e($date ?? to_jalali(date('Y-m-d'))) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">وضعیت:</span>
                <span class="info-value" style="color:#10b981">تأیید و واریز شده</span>
            </div>

            <p style="margin-top:20px; color:#666; font-size:14px;">
                معمولاً وجه ظرف ۱ تا ۳ روز کاری به حساب بانکی شما واریز می‌شود.
                در صورت عدم دریافت، با پشتیبانی تماس بگیرید.
            </p>

            <a href="<?= e($wallet_url ?? '') ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>