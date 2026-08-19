<?php
$title = 'پیش‌بینی بازی‌های ورزشی';
$bodyClass = trim((string)($bodyClass ?? '') . ' prediction-hub-page');

$games = $games ?? [];
$userBets = $userBets ?? [];
$recentBets = $recentBets ?? [];
$recentGames = $recentGames ?? [];
$summary = $summary ?? (object)[];
$rolloverReserve = (float)($rolloverReserve ?? 0);

$allowedSections = ['open', 'my-bets', 'results', 'rules'];
$initialSection = in_array(($_GET['section'] ?? ''), $allowedSections, true) ? (string)$_GET['section'] : 'open';
$focusGame = max(0, (int)($_GET['game'] ?? 0));

$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v, int $dec = 2): string => number_format((float)($v ?? 0), $dec);
$count = static fn($v): string => number_format((int)($v ?? 0));
$timeShort = static fn($v): string => $v ? e(substr((string)$v, 0, 16)) : '—';

$predMap = ['home' => 'برد میزبان', 'away' => 'برد مهمان', 'draw' => 'مساوی'];
$resultMap = ['home' => 'برد میزبان', 'away' => 'برد مهمان', 'draw' => 'مساوی'];
$statusMeta = static function (?string $status): array {
    return match ((string)$status) {
        'open' => ['باز', 'pred-badge-ok', 'radio_button_checked'],
        'closed' => ['بسته', 'pred-badge-warn', 'lock_clock'],
        'finished' => ['تسویه‌شده', 'pred-badge-info', 'flag'],
        'cancelled' => ['لغوشده', 'pred-badge-danger', 'cancel'],
        'pending' => ['در انتظار نتیجه', 'pred-badge-warn', 'hourglass_top'],
        'won' => ['برنده', 'pred-badge-ok', 'emoji_events'],
        'lost' => ['بازنده', 'pred-badge-danger', 'close'],
        'refunded' => ['برگشت کامل', 'pred-badge-muted', 'keyboard_return'],
        default => [$status ?: 'نامشخص', 'pred-badge-muted', 'help'],
    };
};
$sportMeta = static function (?string $sport): array {
    return match ((string)$sport) {
        'football' => ['فوتبال', 'sports_soccer'],
        'basketball' => ['بسکتبال', 'sports_basketball'],
        'tennis' => ['تنیس', 'sports_tennis'],
        'volleyball' => ['والیبال', 'sports_volleyball'],
        'baseball' => ['بیسبال', 'sports_baseball'],
        'hockey' => ['هاکی', 'sports_hockey'],
        'cricket' => ['کریکت', 'sports_cricket'],
        default => ['سایر', 'sports'],
    };
};
$poolPct = static fn(float $value, float $pool): int => $pool > 0 ? (int)round(($value / $pool) * 100) : 0;

$totalStake = (float)($summary->total_stake_usdt ?? 0);
$totalPayout = (float)($summary->total_payout_usdt ?? 0);
$totalRefunded = (float)($summary->total_refunded_usdt ?? 0);

ob_start();
?>
<div class="pred-wrap" id="predictionHub"
     data-base="<?= e(url('/prediction')) ?>"
     data-initial-section="<?= e($initialSection) ?>"
     data-focus-game="<?= (int)$focusGame ?>">

  <section class="pred-hero">
    <div class="pred-hero-main">
      <div class="pred-kicker"><span class="material-icons">sports_score</span> مرکز پیش‌بینی شفاف</div>
      <h1>پیش‌بینی بازی‌ها با قوانین مالی روشن</h1>
      <p>هر پیش‌بینی با USDT ثبت می‌شود. مبلغ در مسیر امن مالی نگهداری می‌شود و پس از نتیجه، طبق قوانین شفاف همین صفحه تسویه می‌شود.</p>
      <div class="pred-hero-actions">
        <button type="button" class="pred-btn pred-btn-primary" data-pred-tab="open"><span class="material-icons">how_to_vote</span> شرکت در پیش‌بینی</button>
        <button type="button" class="pred-btn pred-btn-ghost" data-pred-tab="rules"><span class="material-icons">rule</span> قوانین کامل</button>
        <!-- 🛡️ OPT-IN REWARD VIDEO BUTTON (معافیت از کارمزد پیش‌بینی) -->
        <button type="button" class="pred-btn" style="background:#10b981; color:#fff; border:none;" onclick="startPredictionRewardedVideo('admob', 15)"><span class="material-icons">price_check</span> پیش‌بینی بدون کارمزد با ویدیو</button>
      </div>

      <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S پیش‌بینی -->
      <div class="reward-modal-wrap" id="predRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
          <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="predRewardModalBox">
              <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="predRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="predRewardModalIconTxt">hourglass_empty</span></div>
              <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="predRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
              <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="predRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="predRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
              <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="predRewardCloseBtn" onclick="closePredRewardModal()">بستن و اعمال معافیت کارمزد</button>
          </div>
      </div>

      <script nonce="<?= e(csp_nonce()) ?>">
      function startPredictionRewardedVideo(network, duration) {
          const modal = document.getElementById('predRewardModalWrap');
          const box = document.getElementById('predRewardModalBox');
          const title = document.getElementById('predRewardModalTitle');
          const body = document.getElementById('predRewardModalBody');
          const icon = document.getElementById('predRewardModalIconTxt');
          const iconBox = document.getElementById('predRewardModalIcon');
          const countTxt = document.getElementById('predRewardCountdown');
          const closeBtn = document.getElementById('predRewardCloseBtn');

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
                  body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ معافیت از کارمزد پلتفرم برای شرط بعدی شما فعال شد.';
                  closeBtn.style.display = 'block';
              }
          }, 1000);
      }
      function closePredRewardModal() {
          document.getElementById('predRewardModalWrap').style.opacity = '0';
          document.getElementById('predRewardModalWrap').style.pointerEvents = 'none';
          alert('معافیت از کارمزد پلتفرم با موفقیت اعمال شد. پیش‌بینی بعدی شما بدون کارمزد ثبت می‌شود.');
      }
      </script>
    </div>
    <div class="pred-hero-side">
      <span class="pred-live-dot"></span>
      <small>ذخیره انتقالی آماده برای بازی‌های بعدی</small>
      <strong><?= $money($rolloverReserve, 4) ?> USDT</strong>
      <p>اگر بازی بدون برنده تمام شود، ۵۰٪ استخر به این ذخیره اضافه می‌شود و در بازی بعدی به عنوان پاداش اضافه مصرف خواهد شد.</p>
    </div>
  </section>

  <nav class="pred-tabs" aria-label="بخش‌های پیش‌بینی">
    <button type="button" class="pred-tab" data-pred-tab="open"><span class="material-icons">dashboard</span><strong>بازی‌های باز</strong><em><?= $count(count($games)) ?></em></button>
    <button type="button" class="pred-tab" data-pred-tab="my-bets"><span class="material-icons">receipt_long</span><strong>پیش‌بینی‌های من</strong><em><?= $count($summary->total_bets ?? 0) ?></em></button>
    <button type="button" class="pred-tab" data-pred-tab="results"><span class="material-icons">workspace_premium</span><strong>نتایج و تسویه‌ها</strong></button>
    <button type="button" class="pred-tab" data-pred-tab="rules"><span class="material-icons">verified_user</span><strong>قوانین شفاف</strong></button>
  </nav>

  <section class="pred-panel" data-pred-panel="open">
    <div class="pred-stats">
      <article><span class="material-icons">sports_soccer</span><small>بازی باز</small><strong><?= $count(count($games)) ?></strong></article>
      <article><span class="material-icons">payments</span><small>کل سهم‌های من</small><strong><?= $money($totalStake, 2) ?> <b>USDT</b></strong></article>
      <article><span class="material-icons">emoji_events</span><small>بردهای من</small><strong><?= $count($summary->won_bets ?? 0) ?></strong></article>
      <article><span class="material-icons">account_balance_wallet</span><small>دریافتی/برگشتی</small><strong><?= $money($totalPayout + $totalRefunded, 2) ?> <b>USDT</b></strong></article>
    </div>

    <div class="pred-rule-strip">
      <span class="material-icons">info</span>
      <p><strong>قانون اصلی:</strong> کمیسیون فقط از پول بازنده‌ها کسر می‌شود؛ اصل مبلغ برنده‌ها مشمول کمیسیون نیست. اگر همه درست بگویند، سود و کمیسیون صفر است.</p>
    </div>

    <?php if (empty($games)): ?>
      <div class="pred-empty">
        <span class="material-icons">sports_soccer</span>
        <h2>فعلاً بازی باز وجود ندارد</h2>
        <p>وقتی مدیر بازی جدید تعریف کند، از همین بخش می‌توانید بدون خروج از صفحه پیش‌بینی خود را ثبت کنید.</p>
      </div>
    <?php else: ?>
      <div class="pred-game-grid">
        <?php foreach ($games as $game):
          $gameId = (int)$game->id;
          $hasBet = !empty($userBets[$gameId]);
          $pool = (float)($game->total_pool ?? 0);
          $bonus = (float)($game->bonus_pool_usdt ?? 0);
          $ph = (float)($game->pool_home ?? 0);
          $pa = (float)($game->pool_away ?? 0);
          $pd = (float)($game->pool_draw ?? 0);
          $homePct = $poolPct($ph, $pool);
          $drawPct = $poolPct($pd, $pool);
          $awayPct = $poolPct($pa, $pool);
          [$sportLabel, $sportIcon] = $sportMeta($game->sport_type ?? 'football');
          [$statusLabel, $statusClass, $statusIcon] = $statusMeta($game->status ?? 'open');
          $deadlineTs = strtotime((string)($game->bet_deadline ?? '')) ?: time();
          $diff = max(0, $deadlineTs - time());
          $remaining = $diff < 3600 ? ceil($diff / 60) . ' دقیقه' : ($diff < 86400 ? ceil($diff / 3600) . ' ساعت' : ceil($diff / 86400) . ' روز');
        ?>
        <article class="pred-game-card<?= $hasBet ? ' is-joined' : '' ?>" data-game-card="<?= $gameId ?>">
          <div class="pred-card-head">
            <span class="pred-sport"><span class="material-icons"><?= e($sportIcon) ?></span><?= e($sportLabel) ?></span>
            <span class="pred-badge <?= e($statusClass) ?>"><span class="material-icons"><?= e($statusIcon) ?></span><?= e($statusLabel) ?></span>
          </div>

          <div class="pred-match">
            <div><strong><?= $h($game->team_home) ?></strong><small>میزبان</small></div>
            <span>VS</span>
            <div><strong><?= $h($game->team_away) ?></strong><small>مهمان</small></div>
          </div>
          <h2><?= $h($game->title) ?></h2>
          <?php if (!empty($game->description)): ?><p class="pred-desc"><?= $h($game->description) ?></p><?php endif; ?>

          <div class="pred-card-stats">
            <div><small>استخر فعلی</small><strong><?= $money($pool, 4) ?></strong><em>USDT</em></div>
            <div><small>پاداش انتقالی</small><strong><?= $money($bonus, 4) ?></strong><em>USDT</em></div>
            <div><small>شرکت‌کننده</small><strong><?= $count($game->total_bets ?? 0) ?></strong><em>نفر</em></div>
          </div>

          <div class="pred-distribution" aria-label="توزیع پیش‌بینی‌ها">
            <div class="pred-dist-labels"><span>میزبان <?= $homePct ?>٪</span><span>مساوی <?= $drawPct ?>٪</span><span>مهمان <?= $awayPct ?>٪</span></div>
            <div class="pred-dist-bar">
              <i class="home pred-pct-<?= $homePct ?>"></i>
              <i class="draw pred-pct-<?= $drawPct ?>"></i>
              <i class="away pred-pct-<?= $awayPct ?>"></i>
            </div>
          </div>

          <div class="pred-deadline"><span class="material-icons">schedule</span> مهلت ثبت: <?= $timeShort($game->bet_deadline ?? null) ?> · حدود <?= e($remaining) ?> مانده</div>

          <?php if ($hasBet): ?>
            <div class="pred-joined-box">
              <span class="material-icons">task_alt</span>
              <div><strong>شما در این بازی پیش‌بینی ثبت کرده‌اید.</strong><small>جزئیات آن در بخش «پیش‌بینی‌های من» همین صفحه قابل مشاهده است.</small></div>
              <button type="button" class="pred-btn pred-btn-soft" data-pred-tab="my-bets">مشاهده</button>
            </div>
          <?php else: ?>
            <form class="pred-bet-form" method="POST" action="<?= e(url('/prediction/' . $gameId . '/bet')) ?>"
                  data-total-pool="<?= e((string)$pool) ?>"
                  data-bonus-pool="<?= e((string)$bonus) ?>"
                  data-commission="<?= e((string)((float)$game->commission_percent / 100)) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="idempotency_key" value="<?= e('prediction_' . $gameId . '_' . bin2hex(random_bytes(8))) ?>">
              <div class="pred-choice-grid">
                <label class="pred-choice" data-pool="<?= e((string)$ph) ?>">
                  <input type="radio" name="prediction" value="home" required>
                  <strong><?= $h($game->team_home) ?></strong><small>برد میزبان · <?= $homePct ?>٪</small>
                </label>
                <label class="pred-choice" data-pool="<?= e((string)$pd) ?>">
                  <input type="radio" name="prediction" value="draw">
                  <strong>مساوی</strong><small>نتیجه مساوی · <?= $drawPct ?>٪</small>
                </label>
                <label class="pred-choice" data-pool="<?= e((string)$pa) ?>">
                  <input type="radio" name="prediction" value="away">
                  <strong><?= $h($game->team_away) ?></strong><small>برد مهمان · <?= $awayPct ?>٪</small>
                </label>
              </div>
              <div class="pred-form-row">
                <label><span>مبلغ پیش‌بینی (USDT)</span><input type="number" name="amount_usdt" class="pred-amount" min="<?= e((string)(float)$game->min_bet_usdt) ?>" max="<?= e((string)(float)$game->max_bet_usdt) ?>" step="0.01" placeholder="<?= $money($game->min_bet_usdt, 2) ?> تا <?= $money($game->max_bet_usdt, 2) ?>" required></label>
                <button type="submit" class="pred-btn pred-btn-primary"><span class="material-icons">check_circle</span> ثبت پیش‌بینی</button>
              </div>
              <div class="pred-preview" hidden><span class="material-icons">calculate</span><p>دریافتی تقریبی در صورت درست بودن: <strong data-preview-amount>—</strong> USDT</p><small>محاسبه تقریبی است و با ورود پیش‌بینی‌های جدید تغییر می‌کند.</small></div>
            </form>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="pred-panel" data-pred-panel="my-bets">
    <div class="pred-section-head">
      <div><span class="material-icons">receipt_long</span><h2>پیش‌بینی‌های من</h2><p>آخرین ۵۰ پیش‌بینی شما همراه با وضعیت و دریافتی ثبت‌شده.</p></div>
      <button type="button" class="pred-btn pred-btn-ghost" data-pred-tab="open">بازگشت به بازی‌های باز</button>
    </div>

    <div class="pred-stats compact">
      <article><span class="material-icons">format_list_numbered</span><small>کل پیش‌بینی</small><strong><?= $count($summary->total_bets ?? 0) ?></strong></article>
      <article><span class="material-icons">hourglass_top</span><small>در انتظار نتیجه</small><strong><?= $count($summary->pending_bets ?? 0) ?></strong></article>
      <article><span class="material-icons">emoji_events</span><small>برد</small><strong><?= $count($summary->won_bets ?? 0) ?></strong></article>
      <article><span class="material-icons">keyboard_return</span><small>برگشت کامل</small><strong><?= $count($summary->refunded_bets ?? 0) ?></strong></article>
    </div>

    <?php if (empty($recentBets)): ?>
      <div class="pred-empty"><span class="material-icons">playlist_add_check</span><h2>هنوز پیش‌بینی ثبت نکرده‌اید</h2><p>از تب بازی‌های باز، یک بازی را انتخاب کنید و پیش‌بینی خود را ثبت کنید.</p></div>
    <?php else: ?>
      <div class="pred-bet-list">
        <?php foreach ($recentBets as $bet):
          [$betStatusLabel, $betStatusClass, $betStatusIcon] = $statusMeta($bet->status ?? 'pending');
          $roi = ((float)($bet->amount_usdt ?? 0) > 0 && (float)($bet->payout_usdt ?? 0) > 0)
              ? (((float)$bet->payout_usdt / (float)$bet->amount_usdt) - 1) * 100
              : 0;
        ?>
        <article class="pred-bet-row">
          <div class="pred-bet-title">
            <a href="<?= e(url('/prediction/' . (int)$bet->game_id)) ?>"><?= $h($bet->game_title ?? ('بازی #' . (int)$bet->game_id)) ?></a>
            <small><?= $h($bet->team_home ?? '') ?> <span>vs</span> <?= $h($bet->team_away ?? '') ?> · <?= $timeShort($bet->match_date ?? null) ?></small>
          </div>
          <div><small>انتخاب من</small><strong><?= e($predMap[$bet->prediction] ?? (string)$bet->prediction) ?></strong></div>
          <div><small>مبلغ</small><strong><?= $money($bet->amount_usdt ?? 0, 4) ?> USDT</strong></div>
          <div><small>نتیجه</small><strong><?= !empty($bet->game_result) ? e($resultMap[$bet->game_result] ?? (string)$bet->game_result) : '—' ?></strong></div>
          <div><span class="pred-badge <?= e($betStatusClass) ?>"><span class="material-icons"><?= e($betStatusIcon) ?></span><?= e($betStatusLabel) ?></span></div>
          <div><small>دریافتی</small><strong class="<?= (float)($bet->payout_usdt ?? 0) > 0 ? 'pred-green' : '' ?>"><?= (float)($bet->payout_usdt ?? 0) > 0 ? $money($bet->payout_usdt, 4) . ' USDT' : (($bet->status ?? '') === 'refunded' ? $money($bet->amount_usdt, 4) . ' USDT' : '—') ?></strong><?php if ($roi > 0): ?><em>+<?= number_format($roi, 1) ?>٪</em><?php endif; ?></div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="pred-panel" data-pred-panel="results">
    <div class="pred-section-head">
      <div><span class="material-icons">workspace_premium</span><h2>نتایج و تسویه‌ها</h2><p>نمایش آخرین بازی‌های تسویه‌شده یا لغوشده با سهم سایت و انتقال به چرخه بعدی.</p></div>
    </div>
    <?php if (empty($recentGames)): ?>
      <div class="pred-empty"><span class="material-icons">flag</span><h2>هنوز نتیجه‌ای ثبت نشده است</h2><p>پس از تسویه بازی‌ها، جزئیات شفاف اینجا نمایش داده می‌شود.</p></div>
    <?php else: ?>
      <div class="pred-result-grid">
        <?php foreach ($recentGames as $game):
          [$gameStatusLabel, $gameStatusClass, $gameStatusIcon] = $statusMeta($game->status ?? 'finished');
          $settlement = [];
          if (!empty($game->settlement_summary)) {
              $decoded = json_decode((string)$game->settlement_summary, true);
              $settlement = is_array($decoded) ? $decoded : [];
          }
          $specialText = '';
          if (!empty($settlement['no_winners'])) {
              $specialText = 'بدون برنده: ۵۰٪ انتقال به بازی بعدی و ۵۰٪ سهم سایت.';
          } elseif (!empty($settlement['all_winners'])) {
              $specialText = 'همه درست گفته‌اند: اصل مبلغ برگشت و کمیسیون صفر بوده است.';
          } elseif (($game->status ?? '') === 'cancelled') {
              $specialText = 'لغو بازی: همه مبالغ کامل برگشت داده شده‌اند.';
          }
        ?>
        <article class="pred-result-card">
          <div class="pred-card-head"><span class="pred-badge <?= e($gameStatusClass) ?>"><span class="material-icons"><?= e($gameStatusIcon) ?></span><?= e($gameStatusLabel) ?></span><small>#<?= (int)$game->id ?></small></div>
          <h3><?= $h($game->title) ?></h3>
          <p><?= $h($game->team_home) ?> <span>vs</span> <?= $h($game->team_away) ?></p>
          <div class="pred-result-main"><strong><?= !empty($game->result) ? e($resultMap[$game->result] ?? (string)$game->result) : '—' ?></strong><small>نتیجه ثبت‌شده</small></div>
          <?php if ($specialText): ?><div class="pred-special-note"><span class="material-icons">policy</span><?= e($specialText) ?></div><?php endif; ?>
          <div class="pred-card-stats small">
            <div><small>استخر</small><strong><?= $money($game->total_pool ?? 0, 4) ?></strong><em>USDT</em></div>
            <div><small>سهم سایت</small><strong><?= $money($game->site_fee_usdt ?? 0, 4) ?></strong><em>USDT</em></div>
            <div><small>انتقالی</small><strong><?= $money($game->rollover_amount_usdt ?? 0, 4) ?></strong><em>USDT</em></div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="pred-panel" data-pred-panel="rules">
    <div class="pred-section-head">
      <div><span class="material-icons">verified_user</span><h2>قوانین مالی پیش‌بینی</h2><p>این قوانین قبل از ثبت پیش‌بینی باید کاملاً روشن باشد؛ همین متن مبنای نمایش محصول به کاربر است.</p></div>
    </div>
    <div class="pred-rules-grid">
      <article><span class="material-icons">account_balance_wallet</span><h3>۱) نگهداری مبلغ</h3><p>بعد از ثبت پیش‌بینی، مبلغ از موجودی USDT شما کم و در مسیر امن مالی نگهداری می‌شود تا بازی تسویه یا لغو شود.</p></article>
      <article><span class="material-icons">payments</span><h3>۲) کمیسیون فقط از بازنده‌ها</h3><p>اصل مبلغ برنده‌ها مشمول کمیسیون نیست. سود برنده‌ها فقط از پول بازنده‌ها محاسبه می‌شود و کمیسیون سایت فقط از همان پول بازنده‌ها کسر می‌شود.</p></article>
      <article><span class="material-icons">groups</span><h3>۳) اگر همه درست بگویند</h3><p>در این حالت پول بازنده‌ای وجود ندارد؛ بنابراین همه فقط اصل مبلغ خود را پس می‌گیرند، سود صفر است و کمیسیون سایت هم صفر ثبت می‌شود.</p></article>
      <article><span class="material-icons">sync_alt</span><h3>۴) اگر هیچ برنده‌ای نباشد</h3><p>همه پیش‌بینی‌ها بازنده محسوب می‌شوند. ۵۰٪ کل استخر به چرخه بازی‌های بعدی منتقل می‌شود و ۵۰٪ برای هزینه‌های سایت ثبت می‌شود.</p></article>
      <article><span class="material-icons">cancel</span><h3>۵) لغو بازی</h3><p>اگر بازی لغو شود یا مدیر آن را نامعتبر اعلام کند، همه پیش‌بینی‌های در انتظار نتیجه به صورت کامل برگشت داده می‌شوند.</p></article>
      <article><span class="material-icons">calculate</span><h3>۶) عدد پیش‌نمایش قطعی نیست</h3><p>پیش‌نمایش دریافتی بر اساس استخر فعلی است. با ورود پیش‌بینی‌های جدید یا تغییر ترکیب انتخاب‌ها، دریافتی نهایی می‌تواند تغییر کند.</p></article>
    </div>
  </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userpredictionhub.css') . '?v=' . e(config('app.version','1.0.0')) . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userpredictionhub.js') . '?v=' . e(config('app.version','1.0.0')) . '"></script>';
include view_path('layouts.user');
?>
