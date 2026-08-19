<?php
$title = $title ?? 'انتقال اعتبار';
ob_start();
?>
<div class="content-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="page-title mb-1">
            <span class="material-icons text-primary align-middle">swap_horiz</span> 
            انتقال اعتبار (Peer-to-Peer)
        </h4>
        <p class="text-muted mb-0 small">انتقال آنی و امن اعتبار به کیف پول سایر کاربران چرتکه</p>
    </div>
    <a href="<?= url('/wallet') ?>" class="btn btn-outline-secondary btn-sm">
        <span class="material-icons align-middle small">account_balance_wallet</span> کیف پول من
    </a>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h6 class="card-title fw-bold text-dark mb-0">فرم انتقال آنی</h6>
            </div>
            <div class="card-body p-4">
                <form id="peerTransferForm" method="POST" action="<?= url('/wallet/transfer') ?>">
                    <?= csrf_field() ?>
                    
                    <div class="mb-4">
                        <label for="recipientInput" class="form-label fw-bold">گیرنده اعتبار <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><span class="material-icons text-muted small">person_search</span></span>
                            <input type="text" class="form-control border-start-0 bg-light" id="recipientInput" name="recipient" placeholder="ایمیل یا نام کاربری گیرنده (مثال: user@chortke.ir)" required autofocus>
                        </div>
                        <div class="form-text mt-2">مقصد انتقال را با دقت وارد کنید. تراکنش‌های تأییدشده غیرقابل بازگشت هستند.</div>
                    </div>

                    <div class="mb-4">
                        <label for="transferAmount" class="form-label fw-bold">مبلغ انتقال (تومان) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><span class="material-icons text-muted small">payments</span></span>
                            <input type="number" class="form-control border-start-0 bg-light fw-bold fs-5" id="transferAmount" name="amount" placeholder="50,000" min="1000" step="1000" required>
                            <span class="input-group-text bg-light text-muted">تومان</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted">حداقل: ۱,۰۰۰ تومان</small>
                            <small class="text-info fw-bold quick-amount" data-amount="100000">انتخاب سریع: ۱۰۰,۰۰۰</small>
                        </div>
                    </div>

                    <div class="alert alert-warning d-flex align-items-center small p-3 mb-4" role="alert">
                        <span class="material-icons text-warning me-3 fs-4">security</span>
                        <div>
                            <strong>حفاظت اتمیک (ACID):</strong> در صورت بروز هرگونه اختلال سیستمی در لحظه‌ی واریز به مقصد، کل مبلغ بلافاصله به کیف پول شما بازگردانده می‌شود.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm" id="submitTransferBtn">
                        <span class="material-icons align-middle me-2">send</span> تأیید و انتقال آنی اعتبار
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card bg-gradient-primary text-white shadow border-0 mb-4" class="info-card gradient-ledger">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <span class="material-icons fs-1 text-warning">shield</span>
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2">شبکه لجر دوطرفه</span>
                </div>
                <h5 class="fw-bold mb-2">تراکنش‌های بدون خطا</h5>
                <p class="text-light small line-height-lg mb-0">سیستم کیف پول چرتکه از موتور قفل‌گذاری توزیع‌شده بدبینانه جهت ممانعت از هرگونه Race Condition و TOCTOU در زمان انتقال بهره می‌برد.</p>
            </div>
        </div>
    </div>
</div>


<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userwallettransfer.js') . '"></script>';
include view_path('layouts.user');
?>