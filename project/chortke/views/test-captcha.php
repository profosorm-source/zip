<?php
$title = 'تست CAPTCHA';
$siteKey = (string)setting('recaptcha_site_key', config('captcha.recaptcha_site_key', ''));
ob_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تست CAPTCHA</title>
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    
</head>
<body>
<div class="test-container">

    <div class="page-title">
        <i class="material-icons" class="align-middle icon-sm">shield</i>
        تست سیستم CAPTCHA
    </div>

    <?php if (!empty($flashSuccess)): ?>
    <div class="flash-msg flash-success">
        <i class="material-icons">check_circle</i>
        <?= e($flashSuccess) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
    <div class="flash-msg flash-error">
        <i class="material-icons">cancel</i>
        <?= e($flashError) ?>
    </div>
    <?php endif; ?>

    <!-- 1. Math -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons">calculate</i>
            Math CAPTCHA
            <span class="badge-type">ریاضی</span>
        </h4>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <?= captcha_field('math') ?>
            <button type="submit" class="btn-verify mt-2">ارسال و تأیید</button>
        </form>
    </div>

    <!-- 2. Image -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons">image</i>
            Image CAPTCHA
            <span class="badge-type">تصویری</span>
        </h4>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <?= captcha_field('image') ?>
            <button type="submit" class="btn-verify mt-2">ارسال و تأیید</button>
        </form>
    </div>

    <!-- 3. Behavioral -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons">touch_app</i>
            Behavioral CAPTCHA
            <span class="badge-type">رفتاری</span>
        </h4>

        <form method="POST" action="<?= url('/test-captcha/verify') ?>" id="bc-form">
            <?= csrf_field() ?>
            <?= captcha_field('behavioral') ?>
            <!-- DEBUG sid=<?= session_id() ?> -->

            <div class="behavioral-panel">
                <p class="hint">
                    <i class="material-icons" class="icon-sm align-middle">info</i>
                    این کپچا رفتار شما را آنالیز می‌کند. کمی موس حرکت دهید، اسکرول کنید یا تایپ کنید تا امتیاز کافی برسد.
                </p>

                <div class="metrics-grid">
                    <div class="metric-box"><div class="val" id="bc-events">0</div><div class="lbl">رویداد ثبت‌شده</div></div>
                    <div class="metric-box"><div class="val" id="bc-score">0</div><div class="lbl">امتیاز انسانی</div></div>
                    <div class="metric-box"><div class="val" id="bc-elapsed">0s</div><div class="lbl">زمان سپری‌شده</div></div>
                    <div class="metric-box"><div class="val" id="bc-iact">0</div><div class="lbl">تعامل (کلیک+تایپ)</div></div>
                </div>

                <div class="bc-progress"><div class="bc-bar" id="bc-bar"></div></div>

                <div class="d-flex align-center justify-between">
                    <span class="status-badge s-wait" id="bc-status">
                        <span class="status-dot"></span>
                        در انتظار تعامل
                    </span>
                    <span>حداقل امتیاز: 60</span>
                </div>

                <div class="log-box" id="bc-log"></div>
            </div>

            <button type="submit" class="btn-verify" id="bc-submit" disabled>
                <i class="material-icons" class="icon-sm align-middle">send</i>
                ارسال و تأیید
            </button>
        </form>
    </div>

    <!-- 4. reCAPTCHA v2 -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons">verified_user</i>
            reCAPTCHA v2
            <span class="badge-type">Google</span>
        </h4>
        <?php if ($siteKey !== ''): ?>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <div class="my-2">
                <div class="g-recaptcha" data-sitekey="<?= e($siteKey) ?>"></div>
            </div>
            <button type="submit" class="btn-verify">ارسال و تأیید</button>
        </form>
        
        <?php else: ?>
        <span class="badge-na">
            <i class="material-icons" class="icon-sm align-middle">warning</i>
            Site Key در تنظیمات سیستم وارد نشده
        </span>
        <?php endif; ?>
    </div>

</div><!-- /test-container -->

<?= captcha_refresh_script() ?>


</body>
</html>
<?php
$content = ob_get_clean();
echo $content;
?>
