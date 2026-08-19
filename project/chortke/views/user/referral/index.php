<?php
$title = 'زیرمجموعه‌گیری و کمیسیون (هاب تک‌صفحه‌ای)';
$hideSidebar = true;
ob_start();
?>

<div class="fin-wrap ref-wrap">

  <!-- Hero -->
  <section class="fin-hero">
    <div class="fin-hero__main">
      <div class="fin-hero__icon" style="background:rgba(16,185,129,0.15); color:#10b981; border:1px solid #10b981;">
        <span class="material-icons">diversity_3</span>
      </div>
      <div>
        <div class="fin-hero__eyebrow" style="color:#10b981;">Referral Hub</div>
        <h1 class="fin-hero__title">داشبورد دعوت از دوستان و کمیسیون</h1>
        <p class="fin-hero__sub">با معرفی دوستان خود به صرافی چرتکه، تا ۳۰٪ از کارمزد معاملات و فعالیت‌های آن‌ها را به صورت مادام‌العمر دریافت کنید.</p>
      </div>
    </div>
    <div class="fin-hero__side">
      <a href="<?= url('/wallet') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">account_balance_wallet</i> کیف پول من</a>
      <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
    </div>
  </section>

  <!-- Hub Layout -->
  <div class="fin-hub-layout">
    <?php $activeSpoke = 'overview'; include view_path('user.referral._referral-nav'); ?>

    <main class="fin-hub-main">
      <!-- Invite Card -->
      <div class="ref-invite-card">
        <div class="ref-invite-card__body">
          <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
          <?php $referralBoostPercent = (float)config('video_rewards.referral_boost_percent', setting('referral_boost_percent', 2.0)); ?>
          <div class="reward-modal-wrap" id="refWelcomeModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
              <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="refWelcomeModalBox">
                  <div style="width: 72px; height: 72px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 20px;">
                      <span class="material-icons" style="font-size: 2.8rem;">military_tech</span>
                  </div>
                  <h3 style="font-size: 1.45rem; font-weight: 700; margin-bottom: 12px; color: #fff;">💎 شتاب‌دهنده ۲۴ ساعته کمیسیون</h3>
                  <span style="display: inline-block; background: rgba(16,185,129,0.15); color: #10b981; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                  <p style="font-size: 1.05rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">
                      آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، سهم کمیسیون شما از فعالیت زیرمجموعه‌ها به مدت ۲۴ ساعت آینده <strong style="color:#10b981; font-size:1.15rem;"><?= $referralBoostPercent ?>٪</strong> افزایش یابد؟
                  </p>
                  <div style="display: flex; gap: 14px; flex-direction: column;">
                      <button type="button" onclick="acceptRefWelcome()" style="background: #10b981; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(16,185,129,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                          <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                      </button>
                      <button type="button" onclick="dismissRefWelcome()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                          خیر، متشکرم (انصراف)
                      </button>
                  </div>
              </div>
          </div>

      <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S زیرمجموعه‌گیری -->
      <div class="reward-modal-wrap" id="refRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
          <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="refRewardModalBox">
              <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="refRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="refRewardModalIconTxt">hourglass_empty</span></div>
              <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="refRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
              <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="refRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="refRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
              <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="refRewardCloseBtn" onclick="closeRefRewardModal()">بستن و اعمال شتاب‌دهنده کمیسیون</button>
          </div>
      </div>

      <script nonce="<?= e(csp_nonce()) ?>">
      function startReferralRewardedVideo(network, duration) {
          const modal = document.getElementById('refRewardModalWrap');
          const box = document.getElementById('refRewardModalBox');
          const title = document.getElementById('refRewardModalTitle');
          const body = document.getElementById('refRewardModalBody');
          const icon = document.getElementById('refRewardModalIconTxt');
          const iconBox = document.getElementById('refRewardModalIcon');
          const countTxt = document.getElementById('refRewardCountdown');
          const closeBtn = document.getElementById('refRewardCloseBtn');

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
                  body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ شتاب‌دهنده کمیسیون شما فعال شد.';
                  closeBtn.style.display = 'block';
              }
          }, 1000);
      }
      function closeRefRewardModal() {
          document.getElementById('refRewardModalWrap').style.opacity = '0';
          document.getElementById('refRewardModalWrap').style.pointerEvents = 'none';
          alert('شتاب‌دهنده ۲۴ ساعته کمیسیون با موفقیت فعال شد.');
      }
      function openRefWelcomeModal() {
          const wrap = document.getElementById('refWelcomeModalWrap');
          const box = document.getElementById('refWelcomeModalBox');
          if (wrap && box) { wrap.style.opacity = '1'; wrap.style.pointerEvents = 'auto'; box.style.transform = 'scale(1)'; }
      }
      function dismissRefWelcome() {
          const wrap = document.getElementById('refWelcomeModalWrap');
          if (wrap) { wrap.style.opacity = '0'; wrap.style.pointerEvents = 'none'; }
          try { sessionStorage.setItem('ref_boost_popup_shown_v1', '1'); } catch(e){}
      }
      function acceptRefWelcome() {
          dismissRefWelcome();
          setTimeout(() => { startReferralRewardedVideo('tapsell', 15); }, 200);
      }
      window.addEventListener('DOMContentLoaded', () => {
          try { if (!sessionStorage.getItem('ref_boost_popup_shown_v1')) { setTimeout(() => openRefWelcomeModal(), 1000); } } catch(e){}
      });
      </script>

      <div class="ref-invite-card__info">
        <div class="ref-invite-card__label">لینک دعوت اختصاصی شما</div>
        <div class="ref-invite-link-row">
          <input type="text" id="referralLink" class="ref-invite-link-input" value="<?= e($referralLink) ?>" readonly dir="ltr">
          <button class="ref-copy-btn" data-action="copy-referral-link">
            <span class="material-icons icon-sm">content_copy</span> کپی لینک
          </button>
        </div>
        <div class="ref-invite-code">
          کد دعوت: <strong dir="ltr"><?= e($user->referral_code ?? '—') ?></strong>
        </div>
      </div>

      <!-- Share Buttons -->
      <div class="ref-share-btns">
        <a href="https://t.me/share/url?url=<?= urlencode($referralLink) ?>&amp;text=<?= urlencode('با این لینک ثبت‌نام کن و کسب درآمد کن!') ?>" target="_blank" class="ref-share-btn ref-share-btn--telegram">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.562 8.248l-2.04 9.61c-.15.672-.543.836-1.1.52l-3.04-2.24-1.467 1.41c-.162.163-.298.298-.61.298l.218-3.087 5.622-5.08c.245-.217-.053-.338-.38-.12L7.36 14.12l-3.02-.943c-.657-.205-.67-.657.137-.973l11.8-4.55c.547-.2 1.025.12.845.595z"/></svg>
          تلگرام
        </a>
        <a href="whatsapp://send?text=<?= urlencode('با این لینک ثبت‌نام کن: ' . $referralLink) ?>" class="ref-share-btn ref-share-btn--whatsapp">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          واتساپ
        </a>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="ref-stats">
    <div class="ref-stat ref-stat--green">
      <div class="ref-stat__icon"><span class="material-icons">group</span></div>
      <div class="ref-stat__body">
        <span class="ref-stat__val"><?= number_format($referredCount) ?></span>
        <span class="ref-stat__lbl">زیرمجموعه فعال</span>
      </div>
    </div>
    <div class="ref-stat ref-stat--gold">
      <div class="ref-stat__icon"><span class="material-icons">payments</span></div>
      <div class="ref-stat__body">
        <span class="ref-stat__val"><?= number_format($stats->total_earned_irt ?? 0) ?></span>
        <span class="ref-stat__lbl">کل درآمد (تومان)</span>
      </div>
    </div>
    <div class="ref-stat ref-stat--blue">
      <div class="ref-stat__icon"><span class="material-icons">currency_bitcoin</span></div>
      <div class="ref-stat__body">
        <span class="ref-stat__val" dir="ltr"><?= number_format($stats->total_earned_usdt ?? 0, 2) ?></span>
        <span class="ref-stat__lbl">کل درآمد (USDT)</span>
      </div>
    </div>
    <div class="ref-stat ref-stat--orange">
      <div class="ref-stat__icon"><span class="material-icons">schedule</span></div>
      <div class="ref-stat__body">
        <span class="ref-stat__val"><?= number_format($stats->pending_irt ?? 0) ?></span>
        <span class="ref-stat__lbl">در انتظار پرداخت (تومان)</span>
      </div>
    </div>
    <?php if (($stats->pending_usdt ?? 0) > 0): ?>
    <div class="ref-stat ref-stat--purple">
      <div class="ref-stat__icon"><span class="material-icons">pending</span></div>
      <div class="ref-stat__body">
        <span class="ref-stat__val" dir="ltr"><?= number_format($stats->pending_usdt ?? 0, 2) ?></span>
        <span class="ref-stat__lbl">در انتظار (USDT)</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Commission Rates -->
  <div class="ref-section">
    <div class="ref-section__header">
      <span class="material-icons">percent</span>
      <h2>درصد کمیسیون شما</h2>
    </div>
    <div class="ref-rates">
      <?php
      $rateIcons = [
        'task_reward'  => 'task_alt',
        'investment'   => 'trending_up',
        'vip_purchase' => 'workspace_premium',
        'story_order'  => 'auto_stories',
      ];
      foreach ($percents as $type => $percent):
      ?>
      <div class="ref-rate-card">
        <div class="ref-rate-card__icon">
          <span class="material-icons"><?= $rateIcons[$type] ?? 'percent' ?></span>
        </div>
        <div class="ref-rate-card__pct"><?= e($percent) ?>%</div>
        <div class="ref-rate-card__lbl"><?= e($sourceTypes[$type] ?? $type) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ref-rate-note">
      <span class="material-icons">info_outline</span>
      با هر فعالیت درآمدزای زیرمجموعه مستقیم شما، درصد مشخصی به کیف پول شما واریز می‌شود.
    </div>
  </div>

  <!-- Referred Users -->
  <div class="ref-section">
    <div class="ref-section__header">
      <span class="material-icons">group</span>
      <h2>زیرمجموعه‌های شما</h2>
      <span class="ref-section__count"><?= number_format($referredCount) ?> نفر</span>
    </div>

    <?php if (empty($referredUsers)): ?>
    <div class="ref-empty">
      <div class="ref-empty__icon"><span class="material-icons">person_add</span></div>
      <h3>هنوز زیرمجموعه‌ای ندارید</h3>
      <p>لینک دعوت خود را به اشتراک بگذارید تا کمیسیون دریافت کنید.</p>
    </div>
    <?php else: ?>
    <div class="ref-table-wrap">
      <table class="ref-table">
        <thead>
          <tr>
            <th>#</th>
            <th>نام</th>
            <th>تاریخ عضویت</th>
            <th>درآمد شما (تومان)</th>
            <th>درآمد شما (USDT)</th>
            <th>تعداد کمیسیون</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($referredUsers as $idx => $ru): ?>
          <tr>
            <td class="ref-td-num"><?= $idx + 1 ?></td>
            <td>
              <div class="ref-user-cell">
                <div class="ref-user-avatar"><?= mb_substr($ru->full_name ?? 'ک', 0, 1, 'UTF-8') ?></div>
                <span><?= e($ru->full_name ?? '—') ?></span>
              </div>
            </td>
            <td class="ref-td-date"><?= to_jalali($ru->joined_at ?? '') ?></td>
            <td class="ref-td-earn ref-text-irt"><?= number_format($ru->earned_irt ?? 0) ?></td>
            <td class="ref-td-earn ref-text-usdt" dir="ltr"><?= number_format($ru->earned_usdt ?? 0, 2) ?></td>
            <td><span class="ref-count-chip"><?= number_format($ru->commission_count ?? 0) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($referredCount > 10): ?>
    <div class="ref-load-more">
      <button class="ref-load-btn" data-action="load-more-users">
        <span class="material-icons">expand_more</span> نمایش بیشتر
      </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Recent Commissions -->
  <div class="ref-section">
    <div class="ref-section__header">
      <span class="material-icons">receipt_long</span>
      <h2>آخرین کمیسیون‌ها</h2>
      <span class="ref-section__count"><?= number_format($stats->total_count ?? 0) ?> تراکنش</span>
    </div>

    <?php if (empty($recentCommissions)): ?>
    <div class="ref-empty">
      <div class="ref-empty__icon"><span class="material-icons">hourglass_empty</span></div>
      <h3>هنوز کمیسیونی ثبت نشده</h3>
      <p>پس از اولین فعالیت زیرمجموعه، کمیسیون شما اینجا نمایش داده می‌شود.</p>
    </div>
    <?php else: ?>
    <div class="ref-table-wrap">
      <table class="ref-table">
        <thead>
          <tr>
            <th>#</th>
            <th>زیرمجموعه</th>
            <th>منبع</th>
            <th>مبلغ اصلی</th>
            <th>درصد</th>
            <th>کمیسیون</th>
            <th>ارز</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentCommissions as $idx => $c): ?>
          <tr>
            <td class="ref-td-num"><?= $idx + 1 ?></td>
            <td class="ref-td-name"><?= e($c->referred_name ?? '—') ?></td>
            <td><span class="ref-source-chip"><?= e($c->source_label ?? $c->source_type) ?></span></td>
            <?php
              $sourceAmount = (float)($c->source_amount ?? $c->amount ?? 0);
              $ctx = [];
              if (!empty($c->context)) {
                $decoded = json_decode((string)$c->context, true);
                if (is_array($decoded)) $ctx = $decoded;
              }
              $commissionPercent = $c->commission_percent ?? ($ctx['percentage'] ?? null);
            ?>
            <td dir="ltr" class="ref-td-amount">
              <?= $c->currency === 'usdt' ? number_format($sourceAmount, 2) : number_format($sourceAmount) ?>
            </td>
            <td class="ref-td-pct"><?= e($commissionPercent ?? '—') ?><?= $commissionPercent !== null ? '%' : '' ?></td>
            <td dir="ltr" class="ref-td-comm">
              <strong class="ref-text-earn">
                <?= $c->currency === 'usdt' ? number_format((float)$c->commission_amount, 2) : number_format((float)$c->commission_amount) ?>
              </strong>
            </td>
            <td>
              <span class="ref-currency-chip ref-currency-chip--<?= $c->currency ?>">
                <?= $c->currency === 'usdt' ? 'USDT' : 'تومان' ?>
              </span>
            </td>
            <td>
              <span class="ref-badge <?= $c->status_class ?>"><?= e($c->status_label) ?></span>
            </td>
            <td class="ref-td-date"><?= to_jalali($c->created_at ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (($stats->total_count ?? 0) > 10): ?>
    <div class="ref-load-more">
      <button class="ref-load-btn" data-action="load-more-commissions">
        <span class="material-icons">expand_more</span> نمایش بیشتر
      </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

    </main>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/userreferral.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userreferralindex.js') . '" data-users-url="' . e(url('/referral/referred-users')) . '" data-commissions-url="' . e(url('/referral/commissions')) . '"></script>';
include view_path('layouts.user');
?>
