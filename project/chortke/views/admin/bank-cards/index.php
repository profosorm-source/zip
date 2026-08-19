<?php
$title  = 'مدیریت کارت‌های بانکی';

$session = \Core\Session::getInstance();
ob_start();

?>
<div id="bankCardsRoot" data-base="<?= url('/admin/bank-cards') ?>" data-verify-url="<?= url('/admin/bank-cards/verify') ?>" data-reject-url="<?= url('/admin/bank-cards/reject') ?>"></div>
<?php
$cards       = $cards       ?? [];
$currentPage = $currentPage ?? 1;
$totalPages  = $totalPages  ?? 1;
$total       = $total       ?? 0;
?>


<!-- ══ HERO ══ -->
<div class="bc-hero">
    <div class="bc-hero-left">
        <div class="bc-hero-icon">
            <span class="material-icons">credit_card</span>
        </div>
        <div class="bc-hero-text">
            <h1>کارت‌های بانکی در انتظار بررسی</h1>
            <p>تأیید یا رد کارت‌های ثبت‌شده توسط کاربران</p>
        </div>
    </div>
    <div class="bc-hero-right">
        <div class="bc-stat-pill gold">
            <span class="material-icons">schedule</span>
            <?= number_format($total) ?> در انتظار
        </div>
    </div>
</div>

<?php if ($flash = $session->getFlash('success')): ?>
<div class="alert alert-success">
    <span class="material-icons">check_circle</span> <?= e($flash) ?>
</div>
<?php endif; ?>

<!-- ══ TABLE ══ -->
<div class="bc-table-wrap">
    <div class="bc-table-header">
        <h3>
            <span class="material-icons">list_alt</span>
            لیست کارت‌ها
        </h3>
        <span ><?= number_format($total) ?> کارت در انتظار</span>
    </div>

    <?php if (empty($cards)): ?>
    <div class="bc-empty">
        <div class="bc-empty-icon">
            <span class="material-icons">credit_card_off</span>
        </div>
        <h3>کارتی در انتظار بررسی نیست</h3>
        <p>همه کارت‌های ثبت‌شده بررسی شده‌اند</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="bc-table">
            <thead>
                <tr>
                    <th>کاربر</th>
                    <th>کارت بانکی</th>
                    <th>بانک</th>
                    <th>صاحب حساب</th>
                    <th>شبا</th>
                    <th>تاریخ ثبت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cards as $card): ?>
                <?php $initials = mb_substr($card->full_name ?? 'U', 0, 1); ?>
                <tr>
                    <!-- کاربر -->
                    <td>
                        <div class="bc-user-cell">
                            <div class="bc-user-avatar"><?= e($initials) ?></div>
                            <div>
                                <p class="bc-user-name"><?= e($card->full_name ?? 'نامشخص') ?></p>
                                <p class="bc-user-email"><?= e($card->email ?? '') ?></p>
                            </div>
                        </div>
                    </td>
                    <!-- شماره کارت -->
                    <td>
                        <span class="bc-card-num">
                            <?= substr($card->card_number, 0, 4) ?>-****-****-<?= substr($card->card_number, -4) ?>
                        </span>
                    </td>
                    <!-- بانک -->
                    <td>
                        <span class="bc-bank-badge">
                            <span class="material-icons">account_balance</span>
                            <?= e($card->bank_name ?? '—') ?>
                        </span>
                    </td>
                    <!-- صاحب حساب -->
                    <?php // BUGFIX-BANKCARD-NAMING-2026-06: DB column is `owner_name`,
                          // populated (decrypted) by BankCardService::list/get. Old
                          // `cardholder_name` ref pointed at a column that never existed. ?>
                    <td >
                        <?= e($card->owner_name ?? $card->cardholder_name ?? '—') ?>
                    </td>
                    <!-- شبا -->
                    <td>
                        <?php if (!empty($card->sheba)): ?>
                        <span class="bc-sheba">IR<?= e($card->sheba) ?></span>
                        <?php else: ?>
                        <span >—</span>
                        <?php endif; ?>
                    </td>
                    <!-- تاریخ -->
                    <td>
                        <span ><?= to_jalali($card->created_at) ?></span>
                    </td>
                    <!-- عملیات -->
                    <td>
                        <div class="bc-actions">
                            <a href="<?= url('/admin/bank-cards/review?id=' . $card->id) ?>"
                               class="bc-btn bc-btn-view" title="مشاهده جزئیات">
                                <span class="material-icons">visibility</span>
                                جزئیات
                            </a>
                            <button class="bc-btn bc-btn-approve"
                                    data-click="verifyCard" data-args="<?= (int)$card->id ?>" data-pass-el
                                    title="تأیید کارت">
                                <span class="material-icons">check</span>
                                تأیید
                            </button>
                            <button class="bc-btn bc-btn-reject"
                                    data-click="openRejectModal" data-args="<?= (int)$card->id ?>"
                                    title="رد کارت">
                                <span class="material-icons">close</span>
                                رد
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="bc-pagination">
        <?php if ($currentPage > 1): ?>
        <a href="?page=<?= $currentPage - 1 ?>" class="bc-page-btn">
            <span class="material-icons">chevron_right</span>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
        <a href="?page=<?= $i ?>" class="bc-page-btn <?= $i === $currentPage ? 'active' : '' ?>">
            <?= fa_number($i) ?>
        </a>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?>
        <a href="?page=<?= $currentPage + 1 ?>" class="bc-page-btn">
            <span class="material-icons">chevron_left</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ══ مودال رد کارت ══ -->
<div class="bc-modal-overlay" id="rejectOverlay">
    <div class="bc-modal">
        <div class="bc-modal-head">
            <div class="bc-modal-head-title">
                <span class="material-icons">cancel</span>
                رد کارت بانکی
            </div>
            <button class="bc-modal-close" data-click="closeRejectModal">
                <span class="material-icons">close</span>
            </button>
        </div>
        <form id="rejectForm">
            <?= csrf_field() ?>
            <input type="hidden" id="reject_card_id" name="card_id">
            <div class="bc-modal-body">
                <div class="bc-form-group">
                    <label>دلیل رد <span class="req">*</span></label>
                    <textarea id="rejection_reason" name="rejection_reason" class="bc-textarea"
                              placeholder="لطفاً دلیل رد کارت را به صورت واضح توضیح دهید..." required></textarea>
                    <small >
                        <span class="material-icons">info</span>
                        این پیام برای کاربر نمایش داده می‌شود
                    </small>
                </div>
                <div>
                    <div >دلایل متداول:</div>
                    <div class="bc-quick-reasons">
                        <button type="button" class="bc-reason-chip" data-click="setReason" data-args="نام صاحب کارت با نام کاربر مطابقت ندارد">عدم تطابق نام</button>
                        <button type="button" class="bc-reason-chip" data-click="setReason" data-args="تصویر کارت واضح نیست یا کیفیت پایین دارد">تصویر نامناسب</button>
                        <button type="button" class="bc-reason-chip" data-click="setReason" data-args="اطلاعات وارد شده نادرست است">اطلاعات نادرست</button>
                        <button type="button" class="bc-reason-chip" data-click="setReason" data-args="شماره کارت با شبا مطابقت ندارد">عدم تطابق شبا</button>
                        <button type="button" class="bc-reason-chip" data-click="setReason" data-args="کارت منقضی شده است">کارت منقضی</button>
                    </div>
                </div>
            </div>
            <div class="bc-modal-foot">
                <button type="button" class="bc-btn-lg bc-btn-cancel" data-click="closeRejectModal">
                    <span class="material-icons">arrow_back</span> انصراف
                </button>
                <button type="submit" class="bc-btn-lg bc-btn-submit-reject">
                    <span class="material-icons">cancel</span> ثبت رد کارت
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast container -->
<!-- ══ مودال تأیید ══ -->
<div class="bc-modal-overlay" id="confirmOverlay">
    <div class="bc-modal">
        <div class="bc-modal-head">
            <div class="bc-modal-head-title">
                <span class="material-icons">check_circle</span>
                تأیید کارت بانکی
            </div>
            <button class="bc-modal-close" data-click="closeConfirmModal">
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
            <button type="button" class="bc-btn-lg bc-btn-cancel" data-click="closeConfirmModal">
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
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbankcardsindex.css') . '">';
$content = ob_get_clean();
include view_path('layouts.admin');
?>
