<?php
$title = 'برداشت وجه';
$hideSidebar = true;
$summary = $summary ?? null;
$cards = $cards ?? [];
$siteCurrency = $siteCurrency ?? 'irt';
$minWithdrawal = (float)($minWithdrawal ?? 0);
$balance = $summary ? ($siteCurrency === 'irt' ? (float)($summary->balance_irt ?? 0) : (float)($summary->balance_usdt ?? 0)) : 0.0;
$feePercent = (float)config('withdrawal_fee_' . $siteCurrency, 0);
$unit = $siteCurrency === 'irt' ? 'تومان' : 'USDT';

ob_start();
?>

<div id="financeWithdrawRoot"
     class="fin-wrap"
     data-currency="<?= e(strtoupper($siteCurrency)) ?>"
     data-min="<?= e((string)$minWithdrawal) ?>"
     data-max="<?= e((string)$balance) ?>"
     data-fee="<?= e((string)$feePercent) ?>"
     data-action="<?= e(url('/wallet/withdraw')) ?>"
     data-redirect="<?= e(url('/withdrawals')) ?>"
     data-limits-url="<?= e(url('/withdrawal/limits')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">

    <section class="fin-hero">
        <div class="fin-hero__main">
            <div class="fin-hero__icon"><i class="material-icons">payments</i></div>
            <div>
                <div class="fin-hero__eyebrow">Withdraw Spoke</div>
                <h1 class="fin-hero__title">برداشت وجه</h1>
                <p class="fin-hero__sub">درخواست برداشت خود را با کنترل مقصد، مبلغ و تأیید نهایی ثبت کنید.</p>
            </div>
        </div>
        <div class="fin-hero__side">
            <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز مالی</a>
            <a href="<?= url('/withdrawals') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">pending_actions</i> برداشت‌ها</a>
        </div>
    </section>

    <div class="fin-hub-layout">
        <?php $activeSpoke = 'withdraw'; include view_path('user.wallet._finance-nav'); ?>
        <main class="fin-hub-main">
            <?php if ($summary): ?>
                <section class="fin-stats">
                    <div class="fin-stat fin-stat--gold">
                        <div class="fin-stat__icon"><i class="material-icons">account_balance_wallet</i></div>
                        <div><span class="fin-stat__lbl">موجودی قابل برداشت</span><span class="fin-stat__val fin-num"><?= $siteCurrency === 'irt' ? number_format($balance) : number_format($balance, 4) ?></span><span class="fin-stat__unit"><?= e($unit) ?></span></div>
                    </div>
                    <div class="fin-stat fin-stat--blue">
                        <div class="fin-stat__icon"><i class="material-icons">price_check</i></div>
                        <div><span class="fin-stat__lbl">حداقل برداشت</span><span class="fin-stat__val fin-num"><?= $siteCurrency === 'irt' ? number_format($minWithdrawal) : number_format($minWithdrawal, 4) ?></span><span class="fin-stat__unit"><?= e($unit) ?></span></div>
                    </div>
                    <div class="fin-stat fin-stat--green">
                        <div class="fin-stat__icon"><i class="material-icons">percent</i></div>
                        <div><span class="fin-stat__lbl">کارمزد برداشت</span><span class="fin-stat__val fin-num"><?= number_format($feePercent, 2) ?>%</span><span class="fin-stat__unit">در صورت فعال بودن</span></div>
                    </div>
                    <div class="fin-stat <?= !empty($summary->can_withdraw_today) ? 'fin-stat--green' : 'fin-stat--red' ?>">
                        <div class="fin-stat__icon"><i class="material-icons">event_available</i></div>
                        <div><span class="fin-stat__lbl">وضعیت برداشت امروز</span><span class="fin-stat__val"><?= !empty($summary->can_withdraw_today) ? 'مجاز' : 'غیرفعال' ?></span><span class="fin-stat__unit">محدودیت روزانه</span></div>
                    </div>
                </section>

                <div id="withdrawalLimitsBox" class="fin-alert fin-alert-info" style="display:none;">
                    <i class="material-icons">info</i>
                    <div>
                        <strong id="limitsProfileLabel"></strong>
                        <div id="limitsDetail" style="margin-top:8px;"></div>
                    </div>
                </div>

                <?php if (empty($summary->can_withdraw_today)): ?>
                    <div class="fin-alert fin-alert-warning"><i class="material-icons">schedule</i><div><strong>محدودیت برداشت:</strong> امروز یکبار برداشت انجام داده‌اید. برداشت بعدی از فردا امکان‌پذیر است.</div></div>
                <?php endif; ?>

                <div class="fin-alert fin-alert-danger">
                    <i class="material-icons">warning</i>
                    <div>
                        <strong>توجه مهم:</strong> پس از ثبت درخواست، مبلغ برداشت تا زمان تأیید یا رد توسط تیم مالی قفل می‌شود. اطلاعات مقصد را با دقت بررسی کنید.
                    </div>
                </div>

                <!-- 🛡️ OPT-IN REWARD VIDEO BANNER (تسریع در واریز برداشت) -->
                <?php $withdrawPriorityHours = (int)config('video_rewards.withdraw_priority_hours', setting('withdraw_priority_hours', 2)); ?>
                

                <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S برداشت -->
                
            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="with_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="with_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">🚀 تسریع در واریز برداشت وجه</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، درخواست برداشت شما در اولویت پرداخت تسویه پایا/ساتنا قرار گرفته و سریع‌تر واریز شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_with_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_with_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="withdrawRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                    <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="withdrawRewardModalBox">
                        <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="withdrawRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="withdrawRewardModalIconTxt">hourglass_empty</span></div>
                        <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="withdrawRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
                        <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="withdrawRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="withdrawRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
                        <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="withdrawRewardCloseBtn" onclick="closeWithdrawRewardModal()">بستن و اعمال اولویت پردازش</button>
                    </div>
                </div>

                <script nonce="<?= e(csp_nonce()) ?>">
                function startWithdrawalRewardedVideo(network, duration) {
                    const modal = document.getElementById('withdrawRewardModalWrap');
                    const box = document.getElementById('withdrawRewardModalBox');
                    const title = document.getElementById('withdrawRewardModalTitle');
                    const body = document.getElementById('withdrawRewardModalBody');
                    const icon = document.getElementById('withdrawRewardModalIconTxt');
                    const iconBox = document.getElementById('withdrawRewardModalIcon');
                    const countTxt = document.getElementById('withdrawRewardCountdown');
                    const closeBtn = document.getElementById('withdrawRewardCloseBtn');

                    modal.style.opacity = '1';
                    modal.style.pointerEvents = 'auto';
                    box.style.transform = 'scale(1)';
                    title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
                    iconBox.style.borderColor = '#10b981';
                    iconBox.style.background = 'rgba(16,185,129,0.2)';
                    iconBox.style.color = '#10b981';
                    icon.innerText = 'hourglass_empty';
                    closeBtn.style.display = 'none';

                    let timer = duration;
                    countTxt.innerText = timer;
                    
                    const interval = setInterval(() => {
                        timer--;
                        countTxt.innerText = timer;
                        if (timer <= 0) {
                            clearInterval(interval);
                            iconBox.style.borderColor = '#10b981';
                            iconBox.style.background = 'rgba(16,185,129,0.2)';
                            iconBox.style.color = '#10b981';
                            icon.innerText = 'verified_user';
                            title.innerText = 'نمایش ویدیو با موفقیت به اتمام رسید!';
                            body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ درخواست برداشت شما در اولویت واریز قرار گرفت.';
                            closeBtn.style.display = 'block';
                        }
                    }, 1000);
                }
                function closeWithdrawRewardModal() {
                    document.getElementById('withdrawRewardModalWrap').style.opacity = '0';
                    document.getElementById('withdrawRewardModalWrap').style.pointerEvents = 'none';
                    alert('درخواست برداشت شما با موفقیت در اولویت واریز قرار گرفت.');
                }
                </script>

                <section class="fin-form-card">
                    <div class="fin-form-card__head"><span><i class="material-icons">edit_note</i> اطلاعات برداشت</span></div>
                    <div class="fin-form-card__body">
                        <form method="POST" action="<?= url('/wallet/withdraw') ?>" id="withdrawalForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="idempotency_key" id="idempotencyKey" value="">
                            <input type="hidden" name="device_fingerprint" id="deviceFingerprint" value="">
                            <input type="hidden" name="request_timestamp" id="requestTimestamp" value="">
                            <input type="hidden" name="currency" value="<?= e(strtoupper($siteCurrency)) ?>">

                            <?php if ($siteCurrency === 'irt'): ?>
                                <div class="fin-form-row one">
                                    <div class="fin-form-group">
                                        <label for="card_id">کارت مقصد</label>
                                        <select id="card_id" name="bank_card_id" class="fin-form-control" required>
                                            <option value="">انتخاب کنید</option>
                                            <?php foreach ($cards as $card): ?>
                                                <option value="<?= e($card->id) ?>" data-bank="<?= e($card->bank_name) ?>">
                                                    <?= e(substr($card->card_number, 0, 4)) ?>-****-****-<?= e(substr($card->card_number, -4)) ?> (<?= e($card->bank_name) ?>) <?= !empty($card->is_default) ? '⭐ پیش‌فرض' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="fin-form-text">برداشت به کارت تأییدشده انتخابی واریز می‌شود.</small>
                                    </div>
                                </div>
                                <div class="fin-alert fin-alert-info" id="card_info" style="display:none;"><i class="material-icons">credit_card</i><div><strong>کارت انتخابی:</strong><div id="card_details"></div></div></div>
                            <?php else: ?>
                                <div class="fin-form-row">
                                    <div class="fin-form-group">
                                        <label for="network">شبکه انتقال</label>
                                        <select id="network" name="crypto_network" class="fin-form-control" required>
                                            <option value="">انتخاب کنید</option>
                                            <option value="BNB20">BNB Smart Chain (BEP20)</option>
                                            <option value="TRC20">TRON Network (TRC20)</option>
                                        </select>
                                    </div>
                                    <div class="fin-form-group">
                                        <label for="wallet_address">آدرس کیف پول USDT</label>
                                        <input type="text" id="wallet_address" name="crypto_wallet" class="fin-form-control" dir="ltr" placeholder="0x... یا T..." required>
                                        <small class="fin-form-text">آدرس و شبکه باید کاملاً مطابق باشند.</small>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="fin-form-row">
                                <div class="fin-form-group">
                                    <label for="amount">مبلغ برداشت (<?= e($unit) ?>)</label>
                                    <input type="number" id="amount" name="amount" class="fin-form-control" min="<?= e((string)$minWithdrawal) ?>" max="<?= e((string)$balance) ?>" step="<?= $siteCurrency === 'irt' ? '1000' : '0.01' ?>" placeholder="<?= $siteCurrency === 'irt' ? '100000' : '50' ?>" required>
                                    <small class="fin-form-text">حداقل: <?= $siteCurrency === 'irt' ? number_format($minWithdrawal) : number_format($minWithdrawal, 4) ?> — حداکثر: <?= $siteCurrency === 'irt' ? number_format($balance) : number_format($balance, 4) ?></small>
                                </div>
                                <div class="fin-form-group">
                                    <label>مبلغ قابل دریافت</label>
                                    <div class="fin-form-control" style="display:flex;align-items:center;justify-content:space-between;"><strong class="fin-num" id="receive_amount">0</strong><span><?= e($unit) ?></span></div>
                                    <small class="fin-form-text">پس از کسر کارمزد احتمالی</small>
                                </div>
                            </div>

                            <div class="fin-actions">
                                <?php if ($siteCurrency === 'irt'): ?>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(100000, $balance)) ?>">100,000</button>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(250000, $balance)) ?>">250,000</button>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(500000, $balance)) ?>">500,000</button>
                                <?php else: ?>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(50, $balance)) ?>">50</button>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(100, $balance)) ?>">100</button>
                                    <button type="button" class="fin-btn fin-btn-secondary" data-action="set-quick-amount" data-value="<?= e((string)min(500, $balance)) ?>">500</button>
                                <?php endif; ?>
                                <button type="button" class="fin-btn fin-btn-secondary" data-action="set-max-amount"><i class="material-icons">select_all</i> همه موجودی</button>
                            </div>

                            <label class="fin-alert fin-alert-warning" style="cursor:pointer;margin-top:16px;">
                                <input type="checkbox" id="confirm_withdrawal" required style="margin-top:6px;">
                                <span>اطلاعات مقصد و مبلغ را بررسی کردم و می‌دانم پس از ثبت درخواست، موجودی قفل می‌شود.</span>
                            </label>

                            <div class="fin-actions">
                                <button type="submit" class="fin-btn fin-btn-danger" id="submit_btn" disabled><i class="material-icons">send</i> ثبت درخواست برداشت</button>
                                <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-secondary">انصراف</a>
                            </div>
                        </form>
                    </div>
                </section>
            <?php else: ?>
                <div class="fin-alert fin-alert-danger"><i class="material-icons">error</i> خطا در دریافت اطلاعات کیف پول</div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userwithdrawalcreate.js') . '"></script>';
include view_path('layouts.user');
?>
