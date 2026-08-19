<?php
$game = $game ?? null;
$bets = $bets ?? [];
$dist = $dist ?? (object)[];
$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v, int $dec = 4): string => number_format((float)($v ?? 0), $dec);
$count = static fn($v): string => number_format((int)($v ?? 0));
$short = static fn($v): string => $v ? e(substr((string)$v, 0, 16)) : '—';
$predMap = ['home' => 'برد میزبان', 'away' => 'برد مهمان', 'draw' => 'مساوی'];
$statusMeta = static function (?string $status): array {
    return match ((string)$status) {
        'open' => ['باز', 'pa-badge-ok', 'radio_button_checked'],
        'closed' => ['بسته', 'pa-badge-warn', 'lock_clock'],
        'finished' => ['پایان/تسویه', 'pa-badge-info', 'flag'],
        'cancelled' => ['لغوشده', 'pa-badge-danger', 'cancel'],
        'pending' => ['در انتظار', 'pa-badge-warn', 'hourglass_top'],
        'won' => ['برنده', 'pa-badge-ok', 'emoji_events'],
        'lost' => ['بازنده', 'pa-badge-danger', 'close'],
        'refunded' => ['برگشت', 'pa-badge-muted', 'keyboard_return'],
        default => [$status ?: 'نامشخص', 'pa-badge-muted', 'help'],
    };
};
$poolPct = static fn(float $value, float $pool): int => $pool > 0 ? (int)round(($value / $pool) * 100) : 0;
$pool = (float)($dist->total_pool ?? $game->total_pool ?? 0);
$ph = (float)($dist->pool_home ?? $game->pool_home ?? 0);
$pd = (float)($dist->pool_draw ?? $game->pool_draw ?? 0);
$pa = (float)($dist->pool_away ?? $game->pool_away ?? 0);
$homePct = $poolPct($ph, $pool);
$drawPct = $poolPct($pd, $pool);
$awayPct = $poolPct($pa, $pool);
[$statusLabel, $statusClass, $statusIcon] = $statusMeta($game->status ?? '');
$settlement = [];
if (!empty($game->settlement_summary)) {
    $decoded = json_decode((string)$game->settlement_summary, true);
    $settlement = is_array($decoded) ? $decoded : [];
}
$settlementNote = '';
if (!empty($settlement['no_winners'])) {
    $settlementNote = 'بدون برنده: همه پیش‌بینی‌ها بازنده شدند؛ ۵۰٪ استخر به ذخیره بازی بعدی و ۵۰٪ سهم سایت ثبت شد.';
} elseif (!empty($settlement['all_winners'])) {
    $settlementNote = 'همه درست پیش‌بینی کرده‌اند؛ اصل مبلغ‌ها برگشت داده شد و کمیسیون صفر بوده است.';
} elseif (($game->status ?? '') === 'cancelled') {
    $settlementNote = 'بازی لغو شده و مبالغ در انتظار نتیجه کامل برگشت داده شده‌اند.';
}

ob_start();
?>
<div class="pa-wrap" id="predictionAdminShow">
  <section class="pa-hero pa-detail-hero">
    <div>
      <div class="pa-kicker"><span class="material-icons">sports_score</span> جزئیات بازی #<?= (int)$game->id ?></div>
      <h1><?= $h($game->title) ?></h1>
      <p><?= $h($game->team_home) ?> در برابر <?= $h($game->team_away) ?> · مدیریت پیش‌بینی‌ها، نتیجه و تسویه مالی.</p>
    </div>
    <div class="pa-hero-actions">
      <a href="<?= e(url('/admin/prediction')) ?>" class="pa-btn pa-btn-ghost"><span class="material-icons">arrow_back</span> بازگشت</a>
      <a href="<?= e(url('/prediction/' . (int)$game->id)) ?>" class="pa-btn pa-btn-ghost" target="_blank"><span class="material-icons">open_in_new</span> نمای کاربر</a>
    </div>
  </section>

  <section class="pa-detail-grid">
    <aside class="pa-card pa-side-card">
      <div class="pa-match-box"><div><strong><?= $h($game->team_home) ?></strong><small>میزبان</small></div><span>VS</span><div><strong><?= $h($game->team_away) ?></strong><small>مهمان</small></div></div>
      <div class="pa-info-list">
        <div><small>وضعیت</small><span class="pa-badge <?= e($statusClass) ?>"><span class="material-icons"><?= e($statusIcon) ?></span><?= e($statusLabel) ?></span></div>
        <div><small>زمان بازی</small><strong><?= $short($game->match_date ?? null) ?></strong></div>
        <div><small>مهلت ثبت</small><strong><?= $short($game->bet_deadline ?? null) ?></strong></div>
        <div><small>محدوده مبلغ</small><strong><?= $money($game->min_bet_usdt ?? 0, 2) ?> تا <?= $money($game->max_bet_usdt ?? 0, 2) ?> USDT</strong></div>
        <div><small>کمیسیون</small><strong><?= $money($game->commission_percent ?? 0, 2) ?>٪ فقط از پول بازنده‌ها</strong></div>
        <div><small>پاداش انتقالی مصرف‌شده</small><strong><?= $money($game->bonus_pool_usdt ?? 0, 4) ?> USDT</strong></div>
      </div>
      <?php if (!empty($game->description)): ?><div class="pa-note"><span class="material-icons">description</span><?= $h($game->description) ?></div><?php endif; ?>

      <?php if(in_array((string)$game->status, ['open','closed'], true)): ?>
      <div class="pa-action-stack">
        <?php if((string)$game->status === 'open'): ?><button type="button" class="pa-btn pa-btn-ghost" data-admin-action="close" data-url="<?= e(url('/admin/prediction/' . (int)$game->id . '/close-betting')) ?>"><span class="material-icons">lock</span> بستن ثبت پیش‌بینی</button><?php endif; ?>
        <button type="button" class="pa-btn pa-btn-primary" data-admin-action="settle" data-url="<?= e(url('/admin/prediction/' . (int)$game->id . '/settle')) ?>" data-title="<?= $h($game->title) ?>" data-home="<?= $h($game->team_home) ?>" data-away="<?= $h($game->team_away) ?>"><span class="material-icons">flag</span> ثبت نتیجه و تسویه</button>
        <button type="button" class="pa-btn pa-btn-danger" data-admin-action="cancel" data-url="<?= e(url('/admin/prediction/' . (int)$game->id . '/cancel')) ?>"><span class="material-icons">cancel</span> لغو و برگشت کامل</button>
      </div>
      <?php endif; ?>
    </aside>

    <main class="pa-main-stack">
      <section class="pa-stats detail">
        <article><span class="material-icons">payments</span><small>استخر کل</small><strong><?= $money($pool, 4) ?> <b>USDT</b></strong></article>
        <article><span class="material-icons">groups</span><small>شرکت‌کننده</small><strong><?= $count($dist->total_bets ?? $game->total_bets ?? 0) ?></strong></article>
        <article><span class="material-icons">account_balance</span><small>سهم سایت</small><strong><?= $money($game->site_fee_usdt ?? 0, 4) ?> <b>USDT</b></strong></article>
        <article><span class="material-icons">sync_alt</span><small>انتقال بعدی</small><strong><?= $money($game->rollover_amount_usdt ?? 0, 4) ?> <b>USDT</b></strong></article>
      </section>

      <section class="pa-card">
        <div class="pa-card-head"><h2><span class="material-icons">stacked_bar_chart</span> توزیع پیش‌بینی‌ها</h2><p>مبنای محاسبه سود برنده‌ها از استخر بازنده‌ها است.</p></div>
        <div class="pa-dist-large"><div class="pa-dist"><i class="home pa-pct-<?= $homePct ?>"></i><i class="draw pa-pct-<?= $drawPct ?>"></i><i class="away pa-pct-<?= $awayPct ?>"></i></div><div><span>میزبان <?= $homePct ?>٪ · <?= $money($ph, 4) ?> USDT</span><span>مساوی <?= $drawPct ?>٪ · <?= $money($pd, 4) ?> USDT</span><span>مهمان <?= $awayPct ?>٪ · <?= $money($pa, 4) ?> USDT</span></div></div>
        <?php if($game->result): ?><div class="pa-settlement-box"><span class="pa-badge pa-badge-info"><span class="material-icons">flag</span><?= e($predMap[$game->result] ?? $game->result) ?></span><?php if($game->winners_paid): ?><span class="pa-badge pa-badge-ok"><span class="material-icons">task_alt</span>تسویه انجام شده</span><?php endif; ?><?php if($settlementNote): ?><p><?= e($settlementNote) ?></p><?php endif; ?></div><?php endif; ?>
      </section>

      <section class="pa-card">
        <div class="pa-card-head"><h2><span class="material-icons">receipt_long</span> پیش‌بینی‌های ثبت‌شده</h2><p><?= $count(count($bets)) ?> رکورد در این بازی</p></div>
        <?php if(empty($bets)): ?>
          <div class="pa-empty"><span class="material-icons">playlist_add</span><h3>هنوز پیش‌بینی ثبت نشده است</h3></div>
        <?php else: ?>
        <div class="pa-table-wrap">
          <table class="pa-table">
            <thead><tr><th>کاربر</th><th>انتخاب</th><th>مبلغ</th><th>وضعیت</th><th>دریافتی</th><th>زمان</th></tr></thead>
            <tbody>
            <?php foreach($bets as $bet): [$betStatusLabel,$betStatusClass,$betStatusIcon] = $statusMeta($bet->status ?? 'pending'); ?>
              <tr>
                <td class="pa-game-cell"><strong><?= $h($bet->full_name ?? $bet->username ?? 'ناشناس') ?></strong><small><?= $h($bet->email ?? '') ?></small></td>
                <td><span class="pa-badge pa-badge-info"><span class="material-icons">how_to_vote</span><?= e($predMap[$bet->prediction] ?? $bet->prediction) ?></span></td>
                <td><strong><?= $money($bet->amount_usdt ?? 0, 4) ?></strong><small>USDT</small></td>
                <td><span class="pa-badge <?= e($betStatusClass) ?>"><span class="material-icons"><?= e($betStatusIcon) ?></span><?= e($betStatusLabel) ?></span></td>
                <td><strong class="<?= (float)($bet->payout_usdt ?? 0) > 0 ? 'pa-green' : '' ?>"><?= (float)($bet->payout_usdt ?? 0) > 0 ? $money($bet->payout_usdt, 4) . ' USDT' : '—' ?></strong></td>
                <td><?= $short($bet->created_at ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
    </main>
  </section>
</div>

<div class="modal fade" id="predictionSettleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content pa-modal">
      <div class="modal-header"><h6 class="modal-title">ثبت نتیجه و تسویه</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="pa-muted" id="paSettleTitle">—</p>
        <div class="pa-result-buttons">
          <button type="button" class="pa-btn pa-btn-ghost" data-result="home"><span class="material-icons">home</span><strong id="paHomeLabel">میزبان</strong> برنده شد</button>
          <button type="button" class="pa-btn pa-btn-ghost" data-result="draw"><span class="material-icons">drag_handle</span> مساوی</button>
          <button type="button" class="pa-btn pa-btn-ghost" data-result="away"><span class="material-icons">flight_takeoff</span><strong id="paAwayLabel">مهمان</strong> برنده شد</button>
        </div>
        <div class="pa-warning"><span class="material-icons">warning</span>این عملیات پرداخت/تکمیل نگهداری‌ها را طبق قوانین مالی جدید انجام می‌دهد و قابل برگشت نیست.</div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminprediction.css') . '?v=' . e(config('app.version','1.0.0')) . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/predictionshow.js') . '?v=' . e(config('app.version','1.0.0')) . '"></script>';
include view_path('layouts.admin');
?>
