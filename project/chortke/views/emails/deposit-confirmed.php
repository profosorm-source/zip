<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)"; $headerTitle="💳 واریز شما تأیید شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>واریز شما با موفقیت تأیید شد و مبلغ به کیف پول شما اضافه گردید.</p>

            <div class="amount-box">
                <div class="label">مبلغ واریز‌شده</div>
                <div class="amount"><?= e($amount ?? '—') ?> <?= e(!empty($currency) && strtolower($currency) === 'usdt' ? 'USDT' : 'تومان') ?></div>
            </div>

            <table class="info-table">
                <tr>
                    <td>روش واریز:</td>
                    <td><?= e($method ?? 'واریز دستی') ?></td>
                </tr>
                <tr>
                    <td>تاریخ تأیید:</td>
                    <td><?= e($date ?? to_jalali(date('Y-m-d'))) ?></td>
                </tr>
                <?php if (!empty($reference)): ?>
                <tr>
                    <td>شماره پیگیری:</td>
                    <td><?= e($reference) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>وضعیت:</td>
                    <td style="color:#10b981">تأیید و اعتبارگذاری شده ✓</td>
                </tr>
            </table>

            <p style="color:#666; font-size:14px;">
                موجودی کیف پول شما به‌روز شده و می‌توانید همین الان از آن استفاده کنید.
            </p>

            <a href="<?= e($wallet_url ?? url('/wallet')) ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>