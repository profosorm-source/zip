<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)"; $headerTitle="💬 پاسخ جدید برای تیکت شما"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>تیم پشتیبانی چرتکه به تیکت شما پاسخ داد.</p>

            <div class="ticket-info">
                <strong>موضوع تیکت:</strong> <?= e($ticket_subject ?? '—') ?>
                <?php if (!empty($ticket_id)): ?>
                <br><strong>شماره تیکت:</strong> #<?= (int)$ticket_id ?>
                <?php endif; ?>
            </div>

            <div class="reply-box">
                <div class="reply-label">پاسخ پشتیبانی:</div>
                <div class="reply-text"><?= e($reply_text ?? '—') ?></div>
            </div>

            <p style="color:#666; font-size:14px;">
                برای پاسخ به این پیام یا مشاهده کامل تیکت روی دکمه زیر کلیک کنید.
            </p>

            <a href="<?= e($ticket_url ?? url('/tickets')) ?>" class="button">مشاهده تیکت</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>