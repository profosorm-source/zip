<?php
$title = 'واریز تتر (USDT)';
ob_start();
?>

<!-- Header -->
<div class="content-header">
    <h1>واریز USDT</h1>
    <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline">
        <span class="material-icons icon-sm">arrow_forward</span>
        بازگشت
    </a>
</div>

<!-- مراحل واریز -->
<div class="steps-container">
    <div class="step active">
        <div class="step-number">1</div>
        <div class="step-title">انتخاب شبکه</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <div class="step-number">2</div>
        <div class="step-title">ارسال USDT</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <div class="step-number">3</div>
        <div class="step-title">ثبت اطلاعات</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <div class="step-number">4</div>
        <div class="step-title">تأیید خودکار</div>
    </div>
</div>

<!-- انتخاب شبکه -->
<div class="network-selection">
    <h3><span class="material-icons icon-lg">settings_ethernet</span> انتخاب شبکه انتقال</h3>
    <div class="form-group full-width">
        <label for="requested_amount">مبلغ درخواستی (USDT)</label>
        <input type="number" id="requested_amount" class="form-control" min="<?= e($minDeposit) ?>" step="0.01" placeholder="مثال: 50">
        <small class="form-text">پس از انتخاب شبکه، سیستم مبلغ دقیق اعشاری و مهلت پرداخت را تعیین می‌کند.</small>
        <div id="intent_info" class="alert alert-info d-none"></div>
    </div>
    <div class="network-grid">
        <?php foreach ($cryptoNetworks as $networkKey => $network): ?>
        <div class="network-card" data-action="select-network" data-network="<?= e($networkKey) ?>">
            <input type="radio" name="network_select" id="network_<?= e($networkKey) ?>" value="<?= e($networkKey) ?>">
            <label for="network_<?= e($networkKey) ?>">
                <div class="network-icon"><span class="material-icons">link</span></div>
                <h4><?= e($network['title']) ?></h4>
                <div class="network-features"><div class="feature"><span>شبکه فعال</span></div></div>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($cryptoNetworks as $networkKey => $network): ?>
<div class="wallet-address-card" id="<?= e($networkKey) ?>_wallet" style="display:none">
    <div class="network-badge"><span class="material-icons icon-sm">link</span><?= e($network['title']) ?></div>
    <h3>آدرس کیف پول سایت</h3>
    <div class="address-display">
        <div class="address-text" dir="ltr"><?= e($network['address']) ?></div>
        <button class="copy-btn" data-action="copy-to-clipboard" data-text="<?= e($network['address']) ?>"><span class="material-icons">content_copy</span>کپی</button>
    </div>
    <div class="qr-code"><div id="qr_<?= e($networkKey) ?>"></div><p>اسکن QR Code</p></div>
    <div class="alert alert-danger"><span class="material-icons icon-sm">warning</span><div>فقط USDT را روی همین شبکه و با مبلغ دقیق تعیین‌شده ارسال کنید.</div></div>
</div>
<?php endforeach; ?>

<!-- فرم ثبت واریز -->
<div class="form-card" id="deposit_form" class="d-none">
    <h3>ثبت اطلاعات واریز</h3>
    
    <div class="alert alert-info">
        <span class="material-icons icon-sm">info</span>
        <div>
            پس از ارسال USDT، اطلاعات تراکنش خود را در فرم زیر وارد کنید.
            سیستم به صورت خودکار تراکنش شما را بررسی و تأیید می‌کند.
        </div>
    </div>

    <form method="POST" action="<?= url('/wallet/deposit/crypto') ?>" id="cryptoDepositForm">
        <?= csrf_field() ?>
        
        <input type="hidden" name="network" id="selected_network">
        <input type="hidden" name="intent_id" id="intent_id">

        <div class="form-row">
            <div class="form-group full-width">
                <label for="tx_hash">هش تراکنش (Transaction Hash): <span class="required">*</span></label>
                <input type="text" 
                       id="tx_hash" 
                       name="tx_hash" 
                       class="form-control ltr" 
                       placeholder="0x..."
                       value="<?= e($old['tx_hash'] ?? '') ?>"
                       required>
                <small class="form-text">هش تراکنش را از کیف پول خود کپی کنید</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group full-width">
                <label for="from_wallet">آدرس کیف پول مبدا (اختیاری)</label>
                <input type="text" id="from_wallet" name="from_wallet" class="form-control ltr" placeholder="آدرس ولتی که از آن ارسال کرده‌اید">
                <small class="form-text">اگر وارد شود، سیستم آن را با sender واقعی تراکنش تطبیق می‌دهد.</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="amount">مبلغ دقیق قابل پرداخت (USDT)</label>
                <input type="number" id="amount" name="amount" class="form-control" readonly>
                <small class="form-text">این مبلغ توسط سیستم تعیین می‌شود؛ فقط همین مقدار را ارسال کنید.</small>
            </div>

            <div class="form-group">
                <label for="deposit_date">تاریخ ارسال: <span class="required">*</span></label>
                <input type="date" 
                       id="deposit_date" 
                       name="deposit_date" 
                       class="form-control" 
                       max="<?= date('Y-m-d') ?>"
                       value="<?= e($old['deposit_date'] ?? date('Y-m-d')) ?>"
                       required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="deposit_time">ساعت ارسال: <span class="required">*</span></label>
                <input type="time" 
                       id="deposit_time" 
                       name="deposit_time" 
                       class="form-control" 
                       value="<?= e($old['deposit_time'] ?? date('H:i')) ?>"
                       required>
            </div>
        </div>

        <div class="verification-info">
            <div class="info-icon">
                <span class="material-icons">verified</span>
            </div>
            <div class="info-content">
                <h4>بررسی خودکار تراکنش</h4>
                <p>
                    پس از ثبت درخواست، سیستم به صورت خودکار تراکنش شما را از طریق Blockchain بررسی می‌کند.
                    در صورت تأیید، موجودی شما ظرف 5 تا 30 دقیقه افزایش می‌یابد.
                </p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <span class="material-icons">check</span>
                ثبت درخواست واریز
            </button>
            <button type="button" class="btn btn-outline btn-lg" data-action="reset-crypto-form">
                انصراف
            </button>
        </div>
    </form>
</div>

<!-- راهنما -->
<div class="help-card">
    <h4>
        <span class="material-icons icon-sm">help</span>
        راهنمای واریز USDT
    </h4>
    <ol>
        <li>شبکه مورد نظر خود (BNB20 یا TRC20) را انتخاب کنید</li>
        <li>آدرس کیف پول سایت را کپی کنید یا از QR Code استفاده کنید</li>
        <li>از کیف پول خود (Trust Wallet, MetaMask, Binance و...) USDT ارسال کنید</li>
        <li><strong>حتماً شبکه صحیح را انتخاب کنید</strong> (خطا در انتخاب شبکه منجر به از دست رفتن دارایی می‌شود)</li>
        <li>پس از ارسال، هش تراکنش و مبلغ را در فرم وارد کنید</li>
        <li>سیستم به صورت خودکار تراکنش شما را بررسی و تأیید می‌کند</li>
        <li>پس از تأیید، موجودی به کیف پول شما افزوده می‌شود</li>
    </ol>

    <div class="alert alert-warning mt-3">
        <span class="material-icons icon-sm">info</span>
        <div>
            <strong>نکته:</strong>
            اگر تراکنش شما به صورت خودکار تأیید نشد، نگران نباشید.
            تیم پشتیبانی به صورت دستی آن را بررسی و تأیید خواهد کرد.
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercryptodeposit.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usercryptodepositcreate.js') . '" data-networks="' . e(json_encode($cryptoNetworks, JSON_UNESCAPED_UNICODE)) . '" data-intent-url="' . e(url('/wallet/deposit/crypto/intent')) . '"></script>';
include view_path('layouts.user');
?>