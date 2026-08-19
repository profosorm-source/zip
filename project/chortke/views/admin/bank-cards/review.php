<?php
$title  = 'بررسی کارت بانکی';

ob_start();
?>
<div id="bankCardsRoot" data-base="<?= url('/admin/bank-cards') ?>" data-verify-url="<?= url('/admin/bank-cards/verify') ?>" data-reject-url="<?= url('/admin/bank-cards/reject') ?>" data-card-id="<?= (int)$cardId ?>"></div>
<?php
$card = $card ?? null;
$user = $user ?? null;
$cardId = (int)($card->id ?? 0);

$statusMap = [
    'pending'  => ['label' => 'در انتظار',   'icon' => 'schedule',      'cls' => 'pending'],
    'verified' => ['label' => 'تأیید شده',   'icon' => 'check_circle',  'cls' => 'verified'],
    'rejected' => ['label' => 'رد شده',      'icon' => 'cancel',        'cls' => 'rejected'],
];
$st = $statusMap[$card->status ?? ''] ?? ['label' => e($card->status ?? '—'), 'icon' => 'help', 'cls' => 'pending'];

// فرمت شماره کارت نمایشی — فقط ۴ رقم اول و آخر نمایش داده می‌شود
// card_number از BankCardService decrypt شده است (AES-256-GCM در DB)
$rawNum  = $card->card_number ?? '';
$dispNum = (strlen($rawNum) >= 16)
    ? e(substr($rawNum, 0, 4)) . '  ****  ****  ' . e(substr($rawNum, -4))
    : '****  ****  ****  ****';
unset($rawNum); // حذف متغیر — جلوگیری از استفاده تصادفی در view
?>


<!-- ══ PAGE HEADER ══ -->
<div class="page-header-section">
    <div class="bc-hero-left">
        <div class="bc-hero-icon">
            <span class="material-icons">credit_card</span>
        </div>
        <div>
            <h1 >بررسی کارت بانکی <span >#<?= $cardId ?></span></h1>
            <p >
                ثبت شده توسط <?= e($user->full_name ?? 'نامشخص') ?> — <?= to_jalali($card->created_at ?? '') ?>
            </p>
        </div>
    </div>
    <div class="page-header-right">
        <div class="bc-status-display <?= $st['cls'] ?>">
            <span class="material-icons"><?= $st['icon'] ?></span>
            <?= $st['label'] ?>
        </div>
        <a href="<?= url('/admin/bank-cards') ?>" class="btn btn-secondary btn-sm">
            <span class="material-icons">arrow_forward</span> بازگشت
        </a>
    </div>
</div>

<!-- ══ CARD PREVIEW ══ -->
<div class="bc-card-preview-wrap">
    <div class="bank-card-visual-lg">
        <div class="bcvl-top">
            <div class="bcvl-chip"></div>
            <div class="bcvl-bank"><?= e(strtoupper($card->bank_name ?? 'BANK')) ?></div>
        </div>
        <div class="bcvl-number"><?= $dispNum ?></div>
        <div class="bcvl-bottom">
            <?php // BUGFIX-BANKCARD-NAMING-2026-06: read from DB column `owner_name`. ?>
            <div class="bcvl-holder"><?= e($card->owner_name ?? $card->cardholder_name ?? 'CARD HOLDER') ?></div>
            <?php if (!empty($card->sheba)): ?>
            <div class="bcvl-sheba">IR<?= e($card->sheba) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ INFO GRID ══ -->
<div class="bc-review-grid">

    <!-- اطلاعات کاربر -->
    <div class="bc-info-card">
        <div class="bc-info-card-head">
            <span class="material-icons">person</span>
            <h4>اطلاعات صاحب کارت</h4>
        </div>
        <div class="bc-info-card-body">
            <div class="bc-info-row">
                <span class="bc-info-label">نام کامل</span>
                <span class="bc-info-value"><?= e($user->full_name ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">ایمیل</span>
                <span class="bc-info-value" dir="ltr"><?= e($user->email ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">موبایل</span>
                <span class="bc-info-value" dir="ltr"><?= e($user->mobile ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">وضعیت KYC</span>
                <span class="bc-info-value">
                    <?php
                    $kyc = $user->kyc_status ?? 'none';
                    $kycColor = $kyc === 'verified' ? 'var(--green)' : 'var(--orange)';
                    $kycIcon  = $kyc === 'verified' ? 'verified_user' : 'pending';
                    $kycLabel = $kyc === 'verified' ? 'تأیید شده' : 'در انتظار';
                    ?>
                    <span >
                        <span class="material-icons"><?= $kycIcon ?></span>
                        <?= $kycLabel ?>
                    </span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">عضویت از</span>
                <span class="bc-info-value"><?= to_jalali($user->created_at ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- اطلاعات کارت -->
    <div class="bc-info-card">
        <div class="bc-info-card-head">
            <span class="material-icons">credit_card</span>
            <h4>اطلاعات کارت بانکی</h4>
        </div>
        <div class="bc-info-card-body">
            <div class="bc-info-row">
                <span class="bc-info-label">شماره کارت</span>
                <span class="bc-info-value">
                    <span class="bc-card-num"><?= e($card->card_number ?? '—') ?></span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">نام بانک</span>
                <span class="bc-info-value">
                    <span class="bc-bank-badge">
                        <span class="material-icons">account_balance</span>
                        <?= e($card->bank_name ?? '—') ?>
                    </span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">نام صاحب حساب</span>
                <span class="bc-info-value"><?= e($card->owner_name ?? $card->cardholder_name ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">شماره شبا</span>
                <span class="bc-info-value">
                    <?php if (!empty($card->sheba)): ?>
                    <span class="bc-sheba">IR<?= e($card->sheba) ?></span>
                    <?php else: ?>
                    <span >—</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">تاریخ ثبت</span>
                <span class="bc-info-value"><?= to_jalali($card->created_at ?? '') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ══ ACTION PANEL (فقط در وضعیت pending) ══ -->
<?php if (($card->status ?? '') === 'pending'): ?>
<div class="bc-action-panel">
    <div class="bc-action-panel-head">
        <span class="material-icons">admin_panel_settings</span>
        <h4>تصمیم‌گیری</h4>
    </div>
    <div class="bc-action-panel-body">
        <button class="bc-btn-action approve" data-click="doVerify">
            <span class="material-icons">check_circle</span>
            تأیید کارت
        </button>
        <button class="bc-btn-action reject-action" data-toggle-class="show" data-target="#rejectOverlay" data-mode="add">
            <span class="material-icons">cancel</span>
            رد کارت
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ══ مودال رد ══ -->
<div class="bc-modal-overlay" id="rejectOverlay">
    <div class="bc-modal">
        <div class="bc-modal-head">
            <div class="bc-modal-head-title">
                <span class="material-icons">cancel</span>
                رد کارت بانکی
            </div>
            <button class="bc-modal-close" data-toggle-class="show" data-target="#rejectOverlay" data-mode="remove">
                <span class="material-icons">close</span>
            </button>
        </div>
        <form id="rejectForm">
            <?= csrf_field() ?>
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <div class="bc-modal-body">
                <div class="bc-form-group">
                    <label>دلیل رد <span class="req">*</span></label>
                    <textarea id="rejectReason" name="rejection_reason" class="bc-textarea"
                              placeholder="دلیل رد را به صورت واضح برای کاربر توضیح دهید..." required></textarea>
                </div>
                <div class="bc-quick-reasons">
                    <button type="button" class="bc-reason-chip" data-click="setR" data-args="نام صاحب کارت با نام کاربر مطابقت ندارد">عدم تطابق نام</button>
                    <button type="button" class="bc-reason-chip" data-click="setR" data-args="تصویر کارت واضح نیست">تصویر نامناسب</button>
                    <button type="button" class="bc-reason-chip" data-click="setR" data-args="اطلاعات وارد شده نادرست است">اطلاعات نادرست</button>
                    <button type="button" class="bc-reason-chip" data-click="setR" data-args="شماره کارت با شبا مطابقت ندارد">عدم تطابق شبا</button>
                </div>
            </div>
            <div class="bc-modal-foot">
                <button type="button" class="bc-btn-lg bc-btn-cancel"
                        data-toggle-class="show" data-target="#rejectOverlay" data-mode="remove">
                    <span class="material-icons">arrow_back</span> انصراف
                </button>
                <button type="submit" class="bc-btn-lg bc-btn-submit-reject">
                    <span class="material-icons">cancel</span> ثبت رد
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast -->
<!-- ══ مودال تأیید ══ -->
<div class="bc-modal-overlay" id="confirmOverlay">
    <div class="bc-modal">
        <div class="bc-modal-head">
            <div class="bc-modal-head-title">
                <span class="material-icons">check_circle</span>
                تأیید کارت بانکی
            </div>
            <button class="bc-modal-close" data-toggle-class="show" data-target="#confirmOverlay" data-mode="remove">
                <span class="material-icons">close</span>
            </button>
        </div>
        <div class="bc-modal-body">
            <div >
                <span class="material-icons">credit_card</span>
            </div>
            <p >آیا از تأیید این کارت مطمئنید؟</p>
            <p >پس از تأیید، کاربر می‌تواند از این کارت استفاده کند.</p>
        </div>
        <div class="bc-modal-foot">
            <button type="button" class="bc-btn-lg bc-btn-cancel"
                    data-toggle-class="show" data-target="#confirmOverlay" data-mode="remove">
                <span class="material-icons">close</span> انصراف
            </button>
            <button type="button" class="bc-btn-lg" id="confirmYesBtn" onmouseover="this.style.background='var(--green)';this.style.color='#fff';" onmouseout="this.style.background='var(--green-dim)';this.style.color='var(--green)';">
                <span class="material-icons">check_circle</span> بله، تأیید شود
            </button>
        </div>
    </div>
</div>

<div class="bc-toast-wrap" id="bcToastWrap"></div>



<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbankcardsreview.css') . '">';
$content = ob_get_clean();
include view_path('layouts.admin');
?>
