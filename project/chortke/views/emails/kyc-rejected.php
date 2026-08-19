<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #ef4444 0%, #dc2626 100%)"; $headerTitle="❌ احراز هویت رد شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>متأسفانه مدارک احراز هویت شما پس از بررسی توسط تیم ما تأیید نشد.</p>

            <?php if (!empty($reason)): ?>
            <div class="reason-box">
                <div class="label">دلیل رد:</div>
                <div class="reason"><?= nl2br(e($reason)) ?></div>
            </div>
            <?php endif; ?>

            <div class="steps-box">
                <h3>📋 برای احراز هویت مجدد:</h3>
                <ul>
                    <li>تصویر کارت ملی باید واضح و خوانا باشد</li>
                    <li>سلفی با کارت ملی در دست باید به وضوح چهره را نشان دهد</li>
                    <li>اطمینان حاصل کنید نور کافی وجود دارد</li>
                    <li>اطلاعات وارد‌شده با مدارک تطابق داشته باشد</li>
                </ul>
            </div>

            <p style="color:#666; font-size:14px;">
                می‌توانید مدارک اصلاح‌شده را مجدداً ارسال کنید. در صورت نیاز به راهنمایی، با پشتیبانی تماس بگیرید.
            </p>

            <a href="<?= e($kyc_url ?? url('/kyc')) ?>" class="button">ارسال مجدد مدارک</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>