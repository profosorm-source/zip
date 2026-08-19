<?php
$title = $title ?? 'ایجاد معامله امن';

ob_start();
?>
<div class="content-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="page-title mb-1">
            <span class="material-icons text-success align-middle">add_security</span> 
            ثبت معامله امن و امانی (Escrow Hold)
        </h4>
        <p class="text-muted mb-0 small">وجه شما تا زمان تحویل نهایی و رضایت کامل در صندوق امانات قفل می‌شود</p>
    </div>
    <a href="<?= url('/wallet/escrows') ?>" class="btn btn-outline-secondary btn-sm">
        <span class="material-icons align-middle small">handshake</span> صندوق امانات من
    </a>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h6 class="card-title fw-bold text-dark mb-0">فرم قفل‌گذاری وجه امانی</h6>
            </div>
            <div class="card-body p-4">
                <form id="createEscrowForm" method="POST" action="<?= url('/wallet/escrow/store') ?>">
                    <?= csrf_field() ?>
                    
                    <div class="mb-4">
                        <label for="sellerInput" class="form-label fw-bold">فروشنده / مجری پروژه <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><span class="material-icons text-muted small">person</span></span>
                            <input type="text" class="form-control border-start-0 bg-light" id="sellerInput" name="seller" placeholder="ایمیل یا نام کاربری فروشنده (مثال: seller@chortke.ir)" required autofocus>
                        </div>
                        <div class="form-text mt-2">طرف معامله باید در چرتکه حساب کاربری فعال داشته باشد.</div>
                    </div>

                    <div class="mb-4">
                        <label for="orderTitle" class="form-label fw-bold">عنوان معامله یا پروژه</label>
                        <input type="text" class="form-control bg-light" id="orderTitle" name="title" placeholder="مثال: خرید سورس کد پروژه یا طراحی قالب گرافیکی" required>
                    </div>

                    <div class="mb-4">
                        <label for="escrowAmount" class="form-label fw-bold">مبلغ معامله (تومان) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><span class="material-icons text-muted small">lock</span></span>
                            <input type="number" class="form-control border-start-0 bg-light fw-bold fs-5 text-success" id="escrowAmount" name="amount" placeholder="5,000,000" min="10000" step="10000" required>
                            <span class="input-group-text bg-light text-muted">تومان</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2 small">
                            <small class="text-muted">حداقل: ۱۰,۰۰۰ تومان</small>
                            <small class="text-success fw-bold quick-amount" data-amount="10000000">انتخاب سریع: ۱۰,۰۰۰,۰۰۰ تومان</small>
                        </div>
                    </div>

                    <div class="alert alert-success d-flex align-items-center small p-3 mb-4" role="alert">
                        <span class="material-icons text-success me-3 fs-4">verified</span>
                        <div>
                            <strong>تضمین ۱۰۰٪ اسکرو:</strong> فروشنده پس از قفل شدن وجه، عملیات را آغاز می‌کند اما به پول دسترسی ندارد تا زمانی که شما دکمه «آزادسازی» را بزنید.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm" id="submitEscrowBtn">
                        <span class="material-icons align-middle me-2">lock</span> قفل‌گذاری آنی وجه در صندوق امانات
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card bg-gradient-success text-white shadow border-0 mb-4" class="info-card gradient-escrow">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <span class="material-icons fs-1 text-success">security</span>
                    <span class="badge bg-success text-white fw-bold px-3 py-2">اسکرو هوشمند</span>
                </div>
                <h5 class="fw-bold mb-2">چرا معاملات امانی؟</h5>
                <p class="text-light small line-height-lg mb-3">با سیستم اسکرو چرتکه، خریدار مطمئن است که کالای خود را دریافت می‌کند و فروشنده مطمئن است که پول پروژه در صندوق سایت قفل شده و سوخت نمی‌شود.</p>
                <div class="border-top border-light opacity-50 my-3"></div>
                <div class="small text-light">
                    <i class="material-icons small align-middle text-warning me-1">gavel</i> در صورت بروز هرگونه اختلاف، داوران حقوقی چرتکه مستندات را بررسی و وجه را به نفع ذی‌حق تسویه می‌کنند.
                </div>
            </div>
        </div>
    </div>
</div>


<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userwalletescrowcreate.js') . '"></script>';
include view_path('layouts.user');
?>