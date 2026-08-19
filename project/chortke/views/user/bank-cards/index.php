<?php
$hideSidebar = true;
$pageTitle = $pageTitle ?? 'کارت‌های بانکی من';
$cards = $cards ?? [];
$cardCount = $cardCount ?? count($cards);
$maxCards = $maxCards ?? 4;
$old = $old ?? [];

$verifiedCards = array_filter($cards, fn($c) => $c->status === 'verified');
$pendingCards  = array_filter($cards, fn($c) => $c->status === 'pending');
$rejectedCards = array_filter($cards, fn($c) => $c->status === 'rejected');
$otherCards    = array_merge($pendingCards, $rejectedCards);

$statusMap = [
    'verified' => ['label' => 'تأیید شده',       'icon' => 'check_circle'],
    'pending'  => ['label' => 'در انتظار تأیید',  'icon' => 'schedule'],
    'rejected' => ['label' => 'رد شده',           'icon' => 'cancel'],
];

ob_start();
?>

<style>
.bc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 20px; margin-bottom: 32px; }
.fin-section-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; color: inherit; border-bottom: 1px solid var(--fin-border-soft, #181F2A); padding-bottom: 12px; }
.fin-section-title i { color: var(--gold, #F0B90B); }
.form-card { background: var(--fin-surface, #11161F); border: 1px solid var(--fin-border-soft, #181F2A); border-radius: 18px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.05); }
.form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px; }
.form-group { display: flex; flex-direction: column; gap: 6px; text-align: right; }
.form-group label { font-weight: 700; font-size: 0.9rem; }
.form-group .required { color: #f6465d; }
.form-control { width: 100%; background: var(--fin-surface-2, #161C27); border: 1px solid var(--fin-border-soft, #181F2A); border-radius: 12px; padding: 12px 16px; font-size: 0.95rem; color: inherit; box-sizing: border-box; }
.form-control:focus { outline: none; border-color: var(--gold, #F0B90B); }
.form-control.ltr { direction: ltr; text-align: left; font-family: monospace, sans-serif; letter-spacing: 1px; font-weight: 700; }
.input-with-prefix { position: relative; display: flex; align-items: center; }
.input-with-prefix .prefix { position: absolute; left: 14px; font-weight: 800; font-family: monospace; color: var(--gold, #F0B90B); }
.input-with-prefix .form-control { padding-left: 42px; }
.form-text { font-size: 0.8rem; opacity: 0.7; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--fin-border-soft, #181F2A); }
</style>

<div class="fin-wrap">
    <!-- Hero Header -->
    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">credit_card</i></div>
            <div>
                <div class="fin-hero__eyebrow">Finance Hub</div>
                <h1 class="fin-hero__title">کارت‌های بانکی من</h1>
                <p class="fin-hero__sub">مدیریت، بررسی و ثبت کارت‌های بانکی تأییدشده جهت برداشت وجه امن در صرافی چرتکه.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز مالی</a>
            <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
        </div>
    </section>

    <!-- Hub Layout -->
    <div class="fin-hub-layout">
        <?php $activeSpoke = 'cards'; include view_path('user.wallet._finance-nav'); ?>

        <main class="fin-hub-main">
            <!-- Alert -->
            <div class="fin-alert fin-alert-info" style="margin-bottom: 28px;">
                <i class="material-icons">info</i>
                <div>
                    <strong>توجه مهم:</strong> کارت‌ها باید حتماً به نام خودتان باشند. پس از تأیید مدیریت می‌توانید برای برداشت و واریز استفاده کنید. (<?= $cardCount ?> از <?= $maxCards ?> کارت ثبت‌شده)
                </div>
            </div>

            <!-- SECTION 1: Verified Cards -->
            <div class="fin-section-title">
                <i class="material-icons" style="color:#0ecb81;">verified_user</i> کارت‌های بانکی تأیید شده (مقصدهای مجاز برداشت)
            </div>

            <?php if (empty($verifiedCards)): ?>
                <div class="bc-empty" style="text-align:center; padding:36px 20px; background:var(--fin-surface, #11161F); border:1px dashed var(--fin-border-soft, #181F2A); border-radius:18px; margin-bottom:32px;">
                    <i class="material-icons" style="font-size:48px; opacity:0.5; margin-bottom:10px;">credit_card_off</i>
                    <h3 style="font-size:1.1rem; font-weight:700; margin:0 0 6px 0;">هیچ کارت تأییدشده‌ای ندارید</h3>
                    <p style="opacity:0.7; font-size:0.9rem; margin:0;">اولین کارت بانکی خود را از فرم پایین صفحه اضافه کنید.</p>
                </div>
            <?php else: ?>
                <div class="bc-grid">
                    <?php foreach ($verifiedCards as $card): ?>
                        <?php
                        $st = $statusMap[$card->status] ?? $statusMap['pending'];
                        $num = preg_replace('/\D/', '', $card->card_number);
                        $formatted = implode('-', str_split($num, 4));
                        $masked = substr($formatted,0,4) . '-****-****-' . substr($num,-4);
                        ?>
                        <div class="bc-card <?= e($card->status) ?>">
                            <div class="bc-card-top">
                                <div class="bc-status-badge">
                                    <i class="material-icons"><?= $st['icon'] ?></i>
                                    <?= $st['label'] ?>
                                </div>
                                <div class="d-flex flex-column align-items-end gap-1">
                                    <?php if ($card->is_default): ?>
                                    <div class="bc-default-badge">
                                        <i class="material-icons">star</i> پیش‌فرض
                                    </div>
                                    <?php endif; ?>
                                    <div class="bc-bank-logo">
                                        <?= mb_substr($card->bank_name, 0, 1) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="bc-chip"></div>
                            <div class="bc-number"><?= $masked ?></div>

                            <div class="bc-card-bottom">
                                <div class="bc-holder">
                                    <span class="bc-holder-label">Card Holder</span>
                                    <span class="bc-holder-name"><?= e($card->cardholder_name) ?></span>
                                </div>
                                <div class="bc-bank-name"><?= e($card->bank_name) ?></div>
                            </div>

                            <div class="bc-date">
                                <i class="material-icons">access_time</i>
                                ثبت: <?= to_jalali($card->created_at, 'Y/m/d') ?>
                            </div>

                            <div class="bc-actions">
                                <?php if (!$card->is_default): ?>
                                <button class="bc-btn bc-btn-star" data-action="set-default-card" data-card-id="<?= (int)$card->id ?>">
                                    <span class="material-icons">star</span> پیش‌فرض
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- SECTION 2: Pending & Rejected Cards -->
            <?php if (!empty($otherCards)): ?>
                <div class="fin-section-title" style="margin-top:20px;">
                    <i class="material-icons" style="color:#F0B90B;">schedule</i> کارت‌های در انتظار بررسی یا رد شده
                </div>
                <div class="bc-grid">
                    <?php foreach ($otherCards as $card): ?>
                        <?php
                        $st = $statusMap[$card->status] ?? $statusMap['pending'];
                        $num = preg_replace('/\D/', '', $card->card_number);
                        $formatted = implode('-', str_split($num, 4));
                        $masked = substr($formatted,0,4) . '-****-****-' . substr($num,-4);
                        ?>
                        <div class="bc-card <?= e($card->status) ?>">
                            <div class="bc-card-top">
                                <div class="bc-status-badge">
                                    <i class="material-icons"><?= $st['icon'] ?></i>
                                    <?= $st['label'] ?>
                                </div>
                                <div class="bc-bank-logo"><?= mb_substr($card->bank_name, 0, 1) ?></div>
                            </div>

                            <div class="bc-chip"></div>
                            <div class="bc-number"><?= $masked ?></div>

                            <div class="bc-card-bottom">
                                <div class="bc-holder">
                                    <span class="bc-holder-label">Card Holder</span>
                                    <span class="bc-holder-name"><?= e($card->cardholder_name) ?></span>
                                </div>
                                <div class="bc-bank-name"><?= e($card->bank_name) ?></div>
                            </div>

                            <div class="bc-date">
                                <i class="material-icons">access_time</i>
                                ثبت: <?= to_jalali($card->created_at, 'Y/m/d') ?>
                            </div>

                            <?php if ($card->status === 'rejected' && !empty($card->rejection_reason)): ?>
                            <div class="bc-rejection">
                                <i class="material-icons">error_outline</i>
                                <p><strong>دلیل رد:</strong> <?= e($card->rejection_reason) ?></p>
                            </div>
                            <?php endif; ?>

                            <div class="bc-actions">
                                <button class="bc-btn bc-btn-del" data-action="confirm-delete-card" data-card-id="<?= (int)$card->id ?>">
                                    <span class="material-icons">delete</span> حذف و ثبت مجدد
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- SECTION 3: Add New Card Form (Using original form-card markup) -->
            <div class="fin-section-title" style="margin-top: 24px;">
                <i class="material-icons" style="color:#F0B90B;">add_card</i> افزودن کارت بانکی جدید
            </div>

            <?php if ($cardCount >= $maxCards): ?>
                <div class="bc-empty" style="text-align:center; padding:36px 20px; background:var(--fin-surface, #11161F); border:1px dashed var(--fin-border-soft, #181F2A); border-radius:18px;">
                    <i class="material-icons" style="font-size:48px; color:#f6465d; margin-bottom:10px;">block</i>
                    <h3 style="font-size:1.1rem; font-weight:700; margin:0 0 6px 0;">سقف مجاز تکمیل شده است</h3>
                    <p style="opacity:0.7; font-size:0.9rem; margin:0;">شما حداکثر تعداد مجاز (<?= $maxCards ?> کارت بانکی) را ثبت کرده‌اید. برای ثبت کارت جدید، ابتدا یکی از کارت‌های قبلی را حذف کنید.</p>
                </div>
            <?php else: ?>
                <div class="form-card">
                    <form method="POST" action="<?= url('/bank-cards/store') ?>" id="bankCardForm">
                        <?= csrf_field() ?>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="card_number">شماره کارت: <span class="required">*</span></label>
                                <input type="text" 
                                       id="card_number" 
                                       name="card_number" 
                                       class="form-control ltr" 
                                       placeholder="1234-5678-9012-3456"
                                       maxlength="19"
                                       value="<?= e($old['card_number'] ?? '') ?>"
                                       required>
                                <small class="form-text">شماره کارت 16 رقمی خود را وارد کنید</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="bank_name">نام بانک: <span class="required">*</span></label>
                                <select id="bank_name" name="bank_name" class="form-control" required>
                                    <option value="">انتخاب کنید</option>
                                    <option value="ملی" <?= ($old['bank_name'] ?? '') === 'ملی' ? 'selected' : '' ?>>بانک ملی</option>
                                    <option value="ملت" <?= ($old['bank_name'] ?? '') === 'ملت' ? 'selected' : '' ?>>بانک ملت</option>
                                    <option value="صادرات" <?= ($old['bank_name'] ?? '') === 'صادرات' ? 'selected' : '' ?>>بانک صادرات</option>
                                    <option value="تجارت" <?= ($old['bank_name'] ?? '') === 'تجارت' ? 'selected' : '' ?>>بانک تجارت</option>
                                    <option value="سپه" <?= ($old['bank_name'] ?? '') === 'سپه' ? 'selected' : '' ?>>بانک سپه</option>
                                    <option value="رفاه" <?= ($old['bank_name'] ?? '') === 'رفاه' ? 'selected' : '' ?>>بانک رفاه</option>
                                    <option value="پاسارگاد" <?= ($old['bank_name'] ?? '') === 'پاسارگاد' ? 'selected' : '' ?>>بانک پاسارگاد</option>
                                    <option value="پارسیان" <?= ($old['bank_name'] ?? '') === 'پارسیان' ? 'selected' : '' ?>>بانک پارسیان</option>
                                    <option value="کشاورزی" <?= ($old['bank_name'] ?? '') === 'کشاورزی' ? 'selected' : '' ?>>بانک کشاورزی</option>
                                    <option value="مسکن" <?= ($old['bank_name'] ?? '') === 'مسکن' ? 'selected' : '' ?>>بانک مسکن</option>
                                    <option value="پست بانک" <?= ($old['bank_name'] ?? '') === 'پست بانک' ? 'selected' : '' ?>>پست بانک</option>
                                    <option value="سامان" <?= ($old['bank_name'] ?? '') === 'سامان' ? 'selected' : '' ?>>بانک سامان</option>
                                    <option value="سینا" <?= ($old['bank_name'] ?? '') === 'سینا' ? 'selected' : '' ?>>بانک سینا</option>
                                    <option value="شهر" <?= ($old['bank_name'] ?? '') === 'شهر' ? 'selected' : '' ?>>بانک شهر</option>
                                    <option value="آینده" <?= ($old['bank_name'] ?? '') === 'آینده' ? 'selected' : '' ?>>بانک آینده</option>
                                    <option value="اقتصاد نوین" <?= ($old['bank_name'] ?? '') === 'اقتصاد نوین' ? 'selected' : '' ?>>بانک اقتصاد نوین</option>
                                    <option value="دی" <?= ($old['bank_name'] ?? '') === 'دی' ? 'selected' : '' ?>>بانک دی</option>
                                    <option value="سایر" <?= ($old['bank_name'] ?? '') === 'سایر' ? 'selected' : '' ?>>سایر</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="cardholder_name">نام صاحب کارت: <span class="required">*</span></label>
                                <input type="text" 
                                       id="cardholder_name" 
                                       name="cardholder_name" 
                                       class="form-control" 
                                       placeholder="نام و نام خانوادگی"
                                       value="<?= e($old['cardholder_name'] ?? '') ?>"
                                       required>
                                <small class="form-text">طبق کارت بانکی</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="account_number">شماره حساب:</label>
                                <input type="text" 
                                       id="account_number" 
                                       name="account_number" 
                                       class="form-control ltr" 
                                       placeholder="1234567890"
                                       value="<?= e($old['account_number'] ?? '') ?>">
                                <small class="form-text">اختیاری</small>
                            </div>

                            <div class="form-group">
                                <label for="sheba">شماره شبا:</label>
                                <div class="input-with-prefix">
                                    <span class="prefix">IR</span>
                                    <input type="text" 
                                           id="sheba" 
                                           name="sheba" 
                                           class="form-control ltr" 
                                           placeholder="000000000000000000000000"
                                           maxlength="24"
                                           value="<?= e($old['sheba'] ?? '') ?>">
                                </div>
                                <small class="form-text">24 رقم بدون IR - اختیاری</small>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="fin-btn fin-btn-primary" style="background:#F0B90B; color:#0b0e11; font-weight:800; border:none;">
                                <i class="material-icons">check</i>
                                ثبت کارت بانکی
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/userbankcardsindex.css') . '">';
$scripts = '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/userbankcardsindex.js') . '" data-set-default-url="' . e(url('/bank-cards/set-default/__ID__')) . '" data-delete-url="' . e(url('/bank-cards/delete/__ID__')) . '"></script>
<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/userbankcardscreate.js') . '"></script>';
include view_path('layouts.user');
?>
