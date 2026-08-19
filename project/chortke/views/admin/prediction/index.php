<?php
$summary = $summary ?? (object)[];
$filters = $filters ?? ['status' => '', 'sport_type' => '', 'search' => ''];
$games = $games ?? [];
$sportTypes = $sportTypes ?? [];
$rolloverReserve = (float)($rolloverReserve ?? 0);

$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v, int $dec = 2): string => number_format((float)($v ?? 0), $dec);
$count = static fn($v): string => number_format((int)($v ?? 0));
$short = static fn($v): string => $v ? e(substr((string)$v, 0, 16)) : '—';
$statusMeta = static function (?string $status): array {
    return match ((string)$status) {
        'open' => ['باز', 'pa-badge-ok', 'radio_button_checked'],
        'closed' => ['بسته', 'pa-badge-warn', 'lock_clock'],
        'finished' => ['پایان/تسویه', 'pa-badge-info', 'flag'],
        'cancelled' => ['لغوشده', 'pa-badge-danger', 'cancel'],
        default => [$status ?: 'نامشخص', 'pa-badge-muted', 'help'],
    };
};
$resultMap = ['home' => 'برد میزبان', 'away' => 'برد مهمان', 'draw' => 'مساوی'];
$poolPct = static fn(float $value, float $pool): int => $pool > 0 ? (int)round(($value / $pool) * 100) : 0;

ob_start();
?>
<div class="pa-wrap" id="predictionAdminIndex">
  <section class="pa-hero">
    <div>
      <div class="pa-kicker"><span class="material-icons">admin_panel_settings</span> مدیریت پیش‌بینی</div>
      <h1>بازی‌ها، استخرها و تسویه‌ها</h1>
      <p>مدیریت بازی‌های پیش‌بینی با مدل مالی جدید: کمیسیون فقط از پول بازنده‌ها، حالت بدون برنده با تقسیم ۵۰/۵۰ و لغو با برگشت کامل.</p>
    </div>
    <div class="pa-hero-actions">
      <a href="<?= e(url('/admin/prediction/create')) ?>" class="pa-btn pa-btn-primary"><span class="material-icons">add_circle</span> تعریف بازی جدید</a>
      <a href="<?= e(url('/prediction')) ?>" class="pa-btn pa-btn-ghost" target="_blank"><span class="material-icons">open_in_new</span> مشاهده Hub کاربر</a>
    </div>
  </section>

  <?php if(!empty($flashSuccess)): ?><div class="pa-alert ok"><span class="material-icons">task_alt</span><?= e($flashSuccess) ?></div><?php endif; ?>
  <?php if(!empty($flashError)): ?><div class="pa-alert err"><span class="material-icons">error</span><?= e($flashError) ?></div><?php endif; ?>

  <section class="pa-stats">
    <article><span class="material-icons">sports_soccer</span><small>کل بازی‌ها</small><strong><?= $count($summary->total_games ?? 0) ?></strong></article>
    <article><span class="material-icons">radio_button_checked</span><small>باز</small><strong><?= $count($summary->open_games ?? 0) ?></strong></article>
    <article><span class="material-icons">payments</span><small>کل استخرها</small><strong><?= $money($summary->total_pool ?? 0, 4) ?> <b>USDT</b></strong></article>
    <article><span class="material-icons">account_balance</span><small>سهم سایت ثبت‌شده</small><strong><?= $money($summary->site_fee_usdt ?? 0, 4) ?> <b>USDT</b></strong></article>
    <article><span class="material-icons">sync_alt</span><small>انتقالی ثبت‌شده</small><strong><?= $money($summary->rollover_amount_usdt ?? 0, 4) ?> <b>USDT</b></strong></article>
    <article><span class="material-icons">savings</span><small>ذخیره بازی بعدی</small><strong><?= $money($rolloverReserve, 4) ?> <b>USDT</b></strong></article>
  </section>

  <section class="pa-card pa-filter-card">
    <form method="GET" class="pa-filters">
      <label><span>وضعیت</span><select name="status"><option value="">همه وضعیت‌ها</option><?php foreach(['open'=>'باز','closed'=>'بسته','finished'=>'پایان یافته','cancelled'=>'لغو شده'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= (($filters['status'] ?? '')===$k)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label><span>ورزش</span><select name="sport_type"><option value="">همه ورزش‌ها</option><?php foreach($sportTypes as $k=>$v): ?><option value="<?= e($k) ?>" <?= (($filters['sport_type'] ?? '')===$k)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="wide"><span>جستجو</span><input type="text" name="search" value="<?= e((string)($filters['search'] ?? '')) ?>" placeholder="عنوان بازی یا نام تیم..."></label>
      <button class="pa-btn pa-btn-primary" type="submit"><span class="material-icons">filter_alt</span> اعمال</button>
      <a class="pa-btn pa-btn-ghost" href="<?= e(url('/admin/prediction')) ?>"><span class="material-icons">restart_alt</span> پاک</a>
    </form>
  </section>

  <section class="pa-card">
    <div class="pa-card-head"><h2><span class="material-icons">list_alt</span> لیست بازی‌ها</h2><p><?= $count($total ?? 0) ?> مورد مطابق فیلتر فعلی</p></div>
    <?php if(empty($games)): ?>
      <div class="pa-empty"><span class="material-icons">sports_soccer</span><h3>بازی‌ای یافت نشد</h3><p>می‌توانید اولین بازی را با دکمه تعریف بازی جدید ثبت کنید.</p></div>
    <?php else: ?>
      <div class="pa-table-wrap">
        <table class="pa-table">
          <thead><tr><th>بازی</th><th>زمان‌ها</th><th>استخر</th><th>توزیع</th><th>وضعیت</th><th>نتیجه</th><th>عملیات</th></tr></thead>
          <tbody>
          <?php foreach($games as $g):
            [$statusLabel, $statusClass, $statusIcon] = $statusMeta($g->status ?? '');
            $pool = (float)($g->total_pool ?? 0);
            $ph = (float)($g->pool_home ?? 0);
            $pd = (float)($g->pool_draw ?? 0);
            $pa = (float)($g->pool_away ?? 0);
            $homePct = $poolPct($ph, $pool);
            $drawPct = $poolPct($pd, $pool);
            $awayPct = $poolPct($pa, $pool);
          ?>
            <tr>
              <td class="pa-game-cell"><strong><?= $h($g->team_home) ?> <span>vs</span> <?= $h($g->team_away) ?></strong><small>#<?= (int)$g->id ?> · <?= $h($g->title) ?> · <?= $h($sportTypes[$g->sport_type] ?? $g->sport_type) ?></small></td>
              <td><small>بازی: <?= $short($g->match_date ?? null) ?></small><small>مهلت: <?= $short($g->bet_deadline ?? null) ?></small></td>
              <td><strong class="pa-green"><?= $money($pool, 4) ?></strong><small><?= $count($g->total_bets ?? 0) ?> شرکت‌کننده</small></td>
              <td class="pa-dist-cell"><div class="pa-dist"><i class="home pa-pct-<?= $homePct ?>"></i><i class="draw pa-pct-<?= $drawPct ?>"></i><i class="away pa-pct-<?= $awayPct ?>"></i></div><small>میزبان <?= $homePct ?>٪ · مساوی <?= $drawPct ?>٪ · مهمان <?= $awayPct ?>٪</small></td>
              <td><span class="pa-badge <?= e($statusClass) ?>"><span class="material-icons"><?= e($statusIcon) ?></span><?= e($statusLabel) ?></span></td>
              <td><?php if($g->result): ?><span class="pa-badge pa-badge-info"><span class="material-icons">flag</span><?= e($resultMap[$g->result] ?? $g->result) ?></span><?php if($g->winners_paid): ?><span class="pa-badge pa-badge-ok"><span class="material-icons">task_alt</span>تسویه</span><?php endif; ?><?php else: ?><span class="pa-muted">—</span><?php endif; ?></td>
              <td>
                <div class="pa-actions">
                  <a href="<?= e(url('/admin/prediction/' . (int)$g->id)) ?>" class="pa-icon-btn" title="جزئیات"><span class="material-icons">visibility</span></a>
                  <?php if(in_array((string)$g->status, ['open','closed'], true)): ?>
                    <?php if((string)$g->status === 'open'): ?><button type="button" class="pa-icon-btn warn" data-admin-action="close" data-url="<?= e(url('/admin/prediction/' . (int)$g->id . '/close-betting')) ?>" title="بستن پیش‌بینی"><span class="material-icons">lock</span></button><?php endif; ?>
                    <button type="button" class="pa-icon-btn gold" data-admin-action="settle" data-url="<?= e(url('/admin/prediction/' . (int)$g->id . '/settle')) ?>" data-home="<?= $h($g->team_home) ?>" data-away="<?= $h($g->team_away) ?>" data-title="<?= $h($g->title) ?>" title="ثبت نتیجه و تسویه"><span class="material-icons">flag</span></button>
                    <button type="button" class="pa-icon-btn danger" data-admin-action="cancel" data-url="<?= e(url('/admin/prediction/' . (int)$g->id . '/cancel')) ?>" title="لغو و برگشت کامل"><span class="material-icons">cancel</span></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if(($totalPages ?? 1) > 1): ?>
      <div class="pa-pagination">
        <small>صفحه <?= $count($page ?? 1) ?> از <?= $count($totalPages ?? 1) ?></small>
        <div>
          <?php for($p = max(1, ($page ?? 1)-2); $p <= min((int)$totalPages, ($page ?? 1)+2); $p++): ?>
            <a class="<?= $p===(int)($page ?? 1)?'active':'' ?>" href="?<?= e(http_build_query(array_merge($filters, ['page'=>$p]))) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
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
        <div class="pa-warning"><span class="material-icons">warning</span>بعد از تأیید، نگهداری مبالغ کامل/لغو می‌شود و پرداخت‌ها طبق قوانین جدید ثبت خواهد شد.</div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminprediction.css') . '?v=' . e(config('app.version','1.0.0')) . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/predictionindex.js') . '?v=' . e(config('app.version','1.0.0')) . '"></script>';
include view_path('layouts.admin');
?>
