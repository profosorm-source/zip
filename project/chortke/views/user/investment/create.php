<?php
$title = 'سرمایه‌گذاری جدید';
$hideSidebar = true;

$settings = $settings ?? [];
$isDepositLocked = (bool)($isDepositLocked ?? false);
$activeInvestment = $activeInvestment ?? null;

$minAmount = (float)($settings['min_amount'] ?? 10);
$maxAmount = max($minAmount, (float)($settings['max_amount'] ?? 10000));
$feePercent = (float)($settings['site_fee_percent'] ?? 10);
$depositLock = (int)($settings['deposit_lock'] ?? 7);
$withdrawalCooldown = (int)($settings['withdrawal_cooldown'] ?? 7);
$quickMid = min($maxAmount, max($minAmount, $minAmount * 5));
$walletUsdt = (float)($user->wallet_usdt ?? $user->usdt_balance ?? $user->balance_usdt ?? 0);

ob_start();
?>

<div id="investmentCreateRoot"
     class="inv-wrap"
     data-store-url="<?= e(url('/investment/store')) ?>"
     data-redirect-url="<?= e(url('/investment')) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-min="<?= e(number_format($minAmount, 8, '.', '')) ?>"
     data-max="<?= e(number_format($maxAmount, 8, '.', '')) ?>"
     data-fee="<?= e(number_format($feePercent, 8, '.', '')) ?>">

    <section class="inv-hub-hero inv-hub-hero--compact">
        <div class="inv-hub-hero__main">
            <div class="inv-hub-hero__icon"><i class="material-icons">add_chart</i></div>
            <div>
                <div class="inv-hub-hero__eyebrow">Investment Spoke</div>
                <h1 class="inv-hub-hero__title">سرمایه‌گذاری جدید</h1>
                <p class="inv-hub-hero__sub">فرم مرحله‌ای با اعتبارسنجی سریع، پیش‌نمایش کارمزد و تأیید ریسک.</p>
            </div>
        </div>
        <a href="<?= url('/investment') ?>" class="inv-btn inv-btn-ghost">
            <i class="material-icons">arrow_forward</i>
            بازگشت به مرکز
        </a>
    </section>

    <div class="inv-hub-layout">
        <?php $activeSpoke = 'create'; include view_path('user.investment._hub-nav'); ?>

        <main class="inv-hub-main">
            <?php if ($isDepositLocked): ?>
                <div class="inv-alert inv-alert--info">
                    <i class="material-icons">lock_clock</i>
                    <span>به دلیل برداشت اخیر، فعلاً امکان سرمایه‌گذاری جدید ندارید.</span>
                </div>
            <?php else: ?>

                <section class="inv-settings-bar" aria-label="تنظیمات سرمایه‌گذاری">
                    <div class="inv-setting-item">
                        <span class="inv-setting-item__lbl">حداقل سرمایه‌گذاری</span>
                        <span class="inv-setting-item__val inv-num"><?= number_format($minAmount, 2) ?> USDT</span>
                    </div>
                    <div class="inv-setting-item">
                        <span class="inv-setting-item__lbl">حداکثر سرمایه‌گذاری</span>
                        <span class="inv-setting-item__val inv-num"><?= number_format($maxAmount, 2) ?> USDT</span>
                    </div>
                    <div class="inv-setting-item">
                        <span class="inv-setting-item__lbl">کارمزد پلتفرم روی سود</span>
                        <span class="inv-setting-item__val inv-num"><?= e((string)$feePercent) ?>%</span>
                    </div>
                    <div class="inv-setting-item">
                        <span class="inv-setting-item__lbl">قفل پس از برداشت</span>
                        <span class="inv-setting-item__val"><?= e((string)$depositLock) ?> روز</span>
                    </div>
                </section>

                <div class="inv-create-layout">
                    <section class="inv-step-card">
                        <div class="inv-step-card__head">
                            <div>
                                <div class="inv-step-kicker">مرحله <span id="investStepNumber">۱</span> از ۳</div>
                                <h2 id="investStepTitle">تعیین مبلغ سرمایه‌گذاری</h2>
                                <p id="investStepSub">مبلغ را وارد کنید تا پیش‌نمایش کارمزد محاسبه شود.</p>
                            </div>
                            <div class="inv-stepper" aria-hidden="true">
                                <span class="inv-stepper__seg active" data-seg="1"><i></i></span>
                                <span class="inv-stepper__seg" data-seg="2"><i></i></span>
                                <span class="inv-stepper__seg" data-seg="3"><i></i></span>
                            </div>
                        </div>

                        <form id="investForm" class="inv-form" novalidate>
                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="amount" id="amountValue" value="">

                            <div class="inv-step-panels">
                                <section class="inv-form-step current" data-step="1">
                                    <div class="inv-step-label">
                                        <i class="material-icons">payments</i>
                                        مبلغ سرمایه‌گذاری
                                    </div>
                                    <div class="inv-amount-input-wrap" id="amountWrap">
                                        <div class="inv-amount-label-row">
                                            <span>مبلغ</span>
                                            <span>محدوده مجاز: <b class="inv-num"><?= number_format($minAmount, 2) ?> - <?= number_format($maxAmount, 2) ?></b></span>
                                        </div>
                                        <div class="inv-amount-field-row">
                                            <input type="text" id="amount" inputmode="decimal" autocomplete="off" placeholder="0.00" dir="ltr">
                                            <span>USDT</span>
                                        </div>
                                    </div>
                                    <div class="inv-field-error" id="amountError">
                                        <i class="material-icons">error_outline</i>
                                        <span id="amountErrorText">مبلغ واردشده معتبر نیست.</span>
                                    </div>
                                    <div class="inv-quick-amounts">
                                        <button type="button" class="inv-quick-chip" data-amt="<?= e(number_format($minAmount, 8, '.', '')) ?>">حداقل</button>
                                        <button type="button" class="inv-quick-chip" data-amt="<?= e(number_format($quickMid, 8, '.', '')) ?>"><?= number_format($quickMid, 2) ?></button>
                                        <button type="button" class="inv-quick-chip" data-amt="<?= e(number_format($maxAmount, 8, '.', '')) ?>">حداکثر</button>
                                    </div>

                                    <div class="inv-review-list inv-review-list--preview" id="feePreview">
                                        <div class="inv-review-row">
                                            <span>مبلغ انتخابی</span>
                                            <strong class="inv-num" id="previewAmount">—</strong>
                                        </div>
                                        <div class="inv-review-row">
                                            <span>کارمزد پلتفرم روی سود</span>
                                            <strong class="inv-num" id="previewFee">—</strong>
                                        </div>
                                    </div>
                                </section>

                                <section class="inv-form-step" data-step="2">
                                    <div class="inv-step-label inv-step-label--danger">
                                        <i class="material-icons">warning_amber</i>
                                        پذیرش ریسک
                                    </div>
                                    <div class="inv-risk-banner inv-risk-banner--strong">
                                        <i class="material-icons">report_problem</i>
                                        <span>این سرمایه‌گذاری تضمین سود ندارد و در شرایط بد بازار می‌تواند باعث از دست رفتن تمام سرمایه شود.</span>
                                    </div>
                                    <label class="inv-risk-check" for="risk_accepted">
                                        <input type="checkbox" name="risk_accepted" id="risk_accepted" value="1">
                                        <span class="inv-risk-check__text">
                                            <strong>هشدار ریسک را مطالعه کردم</strong> و با آگاهی کامل از احتمال ضرر، این سرمایه‌گذاری را انجام می‌دهم. مسئولیت هرگونه ضرر و زیان با اینجانب است.
                                        </span>
                                    </label>

                                    <div class="inv-rule-grid">
                                        <div class="inv-rule-item"><i class="material-icons">dangerous</i><span>احتمال ضرر تا ۱۰۰٪ سرمایه وجود دارد.</span></div>
                                        <div class="inv-rule-item"><i class="material-icons">history</i><span>عملکرد گذشته تضمینی برای آینده نیست.</span></div>
                                        <div class="inv-rule-item"><i class="material-icons">lock</i><span>پس از برداشت، <?= e((string)$depositLock) ?> روز امکان سرمایه‌گذاری جدید نیست.</span></div>
                                        <div class="inv-rule-item"><i class="material-icons">schedule</i><span>برداشت‌ها طبق cooldown <?= e((string)$withdrawalCooldown) ?> روزه بررسی می‌شوند.</span></div>
                                    </div>
                                </section>

                                <section class="inv-form-step" data-step="3">
                                    <div class="inv-step-label">
                                        <i class="material-icons">fact_check</i>
                                        بازبینی نهایی
                                    </div>
                                    <div class="inv-review-list">
                                        <div class="inv-review-row">
                                            <span>مبلغ سرمایه‌گذاری</span>
                                            <strong class="inv-num" id="reviewAmount">—</strong>
                                        </div>
                                        <div class="inv-review-row">
                                            <span>کارمزد پلتفرم روی سود</span>
                                            <strong class="inv-num" id="reviewFee">—</strong>
                                        </div>
                                        <div class="inv-review-row">
                                            <span>مبلغ درگیر در پلن</span>
                                            <strong class="inv-num inv-text-gold" id="reviewNet">—</strong>
                                        </div>
                                        <div class="inv-review-row">
                                            <span>پذیرش ریسک</span>
                                            <strong class="inv-text-up">تأیید شده</strong>
                                        </div>
                                    </div>
                                    <div class="inv-notice-box">
                                        <i class="material-icons">info</i>
                                        <span>با ثبت نهایی، مبلغ از کیف پول USDT شما برای ایجاد پلن سرمایه‌گذاری برداشت/قفل می‌شود.</span>
                                    </div>
                                </section>

                                <section class="inv-form-step inv-success-step" data-step="4">
                                    <div class="inv-success-icon"><i class="material-icons">check</i></div>
                                    <h3>درخواست سرمایه‌گذاری ثبت شد</h3>
                                    <p id="successMessage">پلن شما با موفقیت ایجاد شد و تا لحظاتی دیگر به مرکز سرمایه‌گذاری منتقل می‌شوید.</p>
                                    <a href="<?= url('/investment') ?>" class="inv-btn inv-btn-primary">بازگشت به مرکز</a>
                                </section>
                            </div>

                            <div class="inv-step-card__foot" id="investFoot">
                                <button type="button" class="inv-btn inv-btn-secondary" id="btnBack" style="display:none;">بازگشت</button>
                                <button type="button" class="inv-btn inv-btn-primary" id="btnNext" disabled>
                                    <span class="inv-btn-spinner"></span>
                                    <span class="inv-btn-label">ادامه</span>
                                </button>
                                <button type="submit" class="inv-btn inv-btn-primary" id="submitBtn" style="display:none;" disabled>
                                    <span class="inv-btn-spinner"></span>
                                    <span class="inv-btn-label">تأیید و ثبت سرمایه‌گذاری</span>
                                </button>
                            </div>
                        </form>
                    </section>

                    <aside class="inv-risk-card">
                        <div class="inv-risk-card__header">
                            <i class="material-icons">shield</i>
                            راهنمای سریع
                        </div>
                        <ul class="inv-risk-list">
                            <li><i class="material-icons">wallet</i><span>موجودی تقریبی USDT شما: <strong class="inv-num"><?= number_format($walletUsdt, 4) ?></strong></span></li>
                            <li><i class="material-icons">verified_user</i><span>قبل از ثبت، مبلغ و کارمزد را در مرحله بازبینی کنترل کنید.</span></li>
                            <li><i class="material-icons">warning</i><span>اگر با ریسک بازار موافق نیستید، سرمایه‌گذاری را ثبت نکنید.</span></li>
                            <li><i class="material-icons">support_agent</i><span>در صورت خطای پرداخت یا قفل موجودی، از پشتیبانی پیگیری کنید.</span></li>
                        </ul>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userinvestment.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userinvestmentcreate.js') . '"></script>';
include view_path('layouts.user');
?>
