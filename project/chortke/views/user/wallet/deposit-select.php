<?php
$title = $title ?? 'افزایش موجودی';
$hideSidebar = true;
$siteCurrency = $siteCurrency ?? 'both';
ob_start();
?>

<div class="fin-wrap">
    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">add_card</i></div>
            <div>
                <div class="fin-hero__eyebrow">Deposit Spoke</div>
                <h1 class="fin-hero__title">افزایش موجودی</h1>
                <p class="fin-hero__sub">روش مناسب شارژ کیف پول را انتخاب کنید؛ واریز آنلاین، واریز دستی یا شارژ USDT.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز مالی</a>
        </div>
    </section>

    <div class="fin-hub-layout">
        <?php $activeSpoke = 'deposit'; include view_path('user.wallet._finance-nav'); ?>
        <main class="fin-hub-main">
            <div class="fin-alert fin-alert-info">
                <i class="material-icons">info</i>
                <div><strong>راهنما:</strong> یکی از روش‌های زیر را برای افزایش موجودی کیف پول خود انتخاب کنید.</div>
            </div>

            <section class="fin-method-grid">
                <?php if ($siteCurrency === 'irt' || $siteCurrency === 'both'): ?>
                    <article class="fin-method-card">
                        <div class="fin-method-card__icon blue"><i class="material-icons">credit_card</i></div>
                        <h3>پرداخت آنلاین</h3>
                        <p>واریز سریع و آنی از طریق درگاه‌های بانکی فعال سیستم.</p>
                        <ul class="fin-feature-list">
                            <li><i class="material-icons">check_circle</i> تأیید خودکار</li>
                            <li><i class="material-icons">check_circle</i> واریز آنی</li>
                            <li><i class="material-icons">verified_user</i> امن و قابل پیگیری</li>
                        </ul>
                        <button type="button" class="fin-btn fin-btn-primary" onclick="showOnlinePaymentModal()">
                            انتخاب درگاه <i class="material-icons">chevron_left</i>
                        </button>
                    </article>

                    <article class="fin-method-card">
                        <div class="fin-method-card__icon green"><i class="material-icons">account_balance</i></div>
                        <h3>واریز دستی</h3>
                        <p>ثبت رسید کارت‌به‌کارت یا شبا برای بررسی تیم مالی.</p>
                        <ul class="fin-feature-list">
                            <li><i class="material-icons">check_circle</i> بدون کارمزد اضافی</li>
                            <li><i class="material-icons">schedule</i> بررسی ۲ تا ۲۴ ساعت</li>
                            <li><i class="material-icons">credit_card</i> واریز از کارت شخصی</li>
                        </ul>
                        <a href="<?= url('/wallet/deposit/manual') ?>" class="fin-btn fin-btn-primary">
                            شروع واریز دستی <i class="material-icons">chevron_left</i>
                        </a>
                    </article>
                <?php endif; ?>

                <?php if ($siteCurrency === 'usdt' || $siteCurrency === 'both'): ?>
                    <article class="fin-method-card">
                        <div class="fin-method-card__icon"><i class="material-icons">currency_bitcoin</i></div>
                        <h3>واریز USDT</h3>
                        <p>شارژ کیف پول تتر از طریق شبکه‌های پشتیبانی‌شده.</p>
                        <ul class="fin-feature-list">
                            <li><i class="material-icons">check_circle</i> بررسی خودکار</li>
                            <li><i class="material-icons">lan</i> پشتیبانی شبکه‌های فعال</li>
                            <li><i class="material-icons">schedule</i> تأیید پس از کانفرم شبکه</li>
                        </ul>
                        <a href="<?= url('/wallet/deposit/crypto') ?>" class="fin-btn fin-btn-primary">
                            واریز USDT <i class="material-icons">chevron_left</i>
                        </a>
                    </article>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div class="fin-modal" id="onlinePaymentModal" aria-hidden="true">
    <div class="fin-modal__content">
        <div class="fin-modal__header">
            <h3>پرداخت آنلاین</h3>
            <button type="button" class="fin-modal__close" onclick="closeOnlinePaymentModal()"><i class="material-icons">close</i></button>
        </div>
        <form method="POST" action="<?= url('/payment/request') ?>" id="paymentForm">
            <?= csrf_field() ?>
            <input type="hidden" name="idempotency_key" id="payment_idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <div class="fin-modal__body">
                <div class="fin-form-row one">
                    <div class="fin-form-group">
                        <label for="gateway">انتخاب درگاه پرداخت</label>
                        <select id="gateway" name="gateway" class="fin-form-control" required>
                            <option value="">انتخاب کنید</option>
                            <option value="mock">درگاه شبیه‌ساز (Mock - تست لوکال)</option>
                            <option value="zarinpal">زرین‌پال</option>
                            <option value="nextpay">نکست‌پی</option>
                            <option value="idpay">آیدی‌پی</option>
                            <option value="dgpay">دی‌جی‌پی</option>
                        </select>
                    </div>
                </div>
                <div class="fin-form-row one">
                    <div class="fin-form-group">
                        <label for="amount">مبلغ واریز تومان</label>
                        <input type="number" id="amount" name="amount" class="fin-form-control" placeholder="مثال: 100000" min="10000" step="1000" required>
                        <small class="fin-form-text">حداقل مبلغ: ۱۰,۰۰۰ تومان</small>
                    </div>
                </div>
                <div class="fin-actions">
                    <button type="button" class="fin-btn fin-btn-secondary" onclick="setAmount(50000)">50,000</button>
                    <button type="button" class="fin-btn fin-btn-secondary" onclick="setAmount(100000)">100,000</button>
                    <button type="button" class="fin-btn fin-btn-secondary" onclick="setAmount(250000)">250,000</button>
                    <button type="button" class="fin-btn fin-btn-secondary" onclick="setAmount(500000)">500,000</button>
                </div>
            </div>
            <div class="fin-modal__footer">
                <button type="button" class="fin-btn fin-btn-secondary" onclick="closeOnlinePaymentModal()">انصراف</button>
                <button type="submit" class="fin-btn fin-btn-primary"><i class="material-icons">payment</i> انتقال به درگاه</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">';
$scripts = '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/userwalletdepositselect.js') . '"></script>
<script nonce="' . e($cspNonce ?? '') . '">
window.showOnlinePaymentModal = function () {
    const m = document.getElementById("onlinePaymentModal");
    if (m) {
        m.style.setProperty("display", "flex", "important");
        m.classList.add("show");
        const ik = document.getElementById("payment_idempotency_key");
        if (ik) ik.value = "dep_" + Math.random().toString(36).substr(2, 9) + "_" + Date.now();
    }
};
window.closeOnlinePaymentModal = function () {
    const m = document.getElementById("onlinePaymentModal");
    if (m) {
        m.style.setProperty("display", "none", "important");
        m.classList.remove("show");
    }
};
window.setAmount = function (val) {
    const inp = document.getElementById("amount");
    if (inp) inp.value = val;
};
document.addEventListener("click", function (event) {
    const modal = document.getElementById("onlinePaymentModal");
    if (modal && event.target === modal) {
        window.closeOnlinePaymentModal();
    }
});
</script>';
include view_path('layouts.user');
?>
