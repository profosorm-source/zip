<?php
$title = 'پیش‌بینی بازی';
ob_start();

$predMap   = ['home' => 'خانه', 'away' => 'مهمان', 'draw' => 'مساوی'];
$statusMap = ['pending' => 'در انتظار', 'won' => 'برنده 🎉', 'lost' => 'بازنده', 'refunded' => 'برگشت داده شد'];
$isOpen    = $game->status === 'open' && strtotime($game->bet_deadline) > time();
$pool      = (float)($game->total_pool ?? 0);
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <span class="material-icons text-primary icon-md">sports_soccer</span>
    <?= e($game->title) ?>
  </h4>
  <a href="<?= url('/prediction') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons icon-sm">arrow_forward</span>
    بازگشت
  </a>
</div>

<!-- اطلاعات بازی -->
<div class="card mt-3">
  <div class="card-body">
    <div class="d-flex justify-content-around align-items-center py-2 mb-3">
      <div class="text-center">
        <div class="h5 fw-bold mb-0"><?= e($game->team_home) ?></div>
        <small class="text-muted">خانه</small>
      </div>
      <span class="badge bg-secondary px-4 py-2 h6 mb-0">VS</span>
      <div class="text-center">
        <div class="h5 fw-bold mb-0"><?= e($game->team_away) ?></div>
        <small class="text-muted">مهمان</small>
      </div>
    </div>

    <div class="row text-center g-0 border-top pt-3">
      <div class="col-3">
        <div class="fw-bold text-success"><?= number_format($pool, 4) ?></div>
        <small class="text-muted">استخر (USDT)</small>
      </div>
      <div class="col-3 border-start">
        <div class="fw-bold"><?= number_format((int)($game->total_bets ?? 0)) ?></div>
        <small class="text-muted">شرکت‌کننده</small>
      </div>
      <div class="col-3 border-start">
        <div class="fw-bold"><?= number_format((float)$game->commission_percent, 1) ?>٪</div>
        <small class="text-muted">کمیسیون</small>
      </div>
      <div class="col-3 border-start">
        <?php
          $statusBadge = ['open'=>'<span class="badge bg-success">باز</span>',
                          'closed'=>'<span class="badge bg-warning">بسته</span>',
                          'finished'=>'<span class="badge bg-primary">پایان</span>',
                          'cancelled'=>'<span class="badge bg-danger">لغو</span>'];
        ?>
        <?= $statusBadge[$game->status] ?? '' ?>
        <br><small class="text-muted">وضعیت</small>
      </div>
    </div>

    <!-- توزیع پیش‌بینی‌ها -->
    <?php
      $ph = (float)($game->pool_home ?? 0);
      $pa = (float)($game->pool_away ?? 0);
      $pd = (float)($game->pool_draw ?? 0);
      $pct = fn($v) => $pool > 0 ? round($v / $pool * 100) : 33;
    ?>
    <div class="mt-3">
      <small class="text-muted">توزیع پیش‌بینی‌های فعلی</small>
      <div class="d-flex justify-content-between mt-1 small text-muted">
        <span>🏠 <?= $pct($ph) ?>٪ (<?= number_format($ph, 2) ?> USDT)</span>
        <span>🤝 <?= $pct($pd) ?>٪</span>
        <span>✈️ <?= $pct($pa) ?>٪ (<?= number_format($pa, 2) ?> USDT)</span>
      </div>
      <div class="progress mt-1 progress-thin">
        <div class="progress-bar bg-primary" data-width="<?= $pct($ph) ?>%" title="خانه"></div>
        <div class="progress-bar bg-secondary" data-width="<?= $pct($pd) ?>%" title="مساوی"></div>
        <div class="progress-bar bg-success" data-width="<?= $pct($pa) ?>%" title="مهمان"></div>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-info mt-3 small">
  <strong>قوانین شفاف پیش‌بینی:</strong>
  برنده‌ها اصل مبلغ خود را پس می‌گیرند و سودشان فقط از پول بازنده‌ها محاسبه می‌شود.
  کمیسیون سایت فقط از پول بازنده‌ها کسر می‌شود، نه از اصل پول برنده‌ها.
  اگر همه درست پیش‌بینی کنند، همه فقط اصل مبلغ خود را پس می‌گیرند و سود/کمیسیون صفر است.
  اگر هیچ‌کس درست پیش‌بینی نکند، همه پیش‌بینی‌ها بازنده محسوب می‌شوند؛ ۵۰٪ استخر به چرخه بازی‌های بعدی منتقل و ۵۰٪ برای هزینه‌های سایت ثبت می‌شود.
</div>

<!-- نتیجه بازی -->
<?php if($game->status === 'finished' && $game->result): ?>
<div class="alert alert-primary text-center mt-3">
  <strong>نتیجه نهایی:</strong>
  <?= ['home' => '🏠 ' . e($game->team_home) . ' برنده شد',
       'away' => '✈️ ' . e($game->team_away) . ' برنده شد',
       'draw' => '🤝 مساوی'][$game->result] ?? $game->result ?>
</div>
<?php endif; ?>

<!-- پیش‌بینی من -->
<?php if($hasBet && $myBet): ?>
<div class="card mt-3 border-success">
  <div class="card-header bg-success bg-opacity-10">
    <h6 class="mb-0 text-success">✓ پیش‌بینی شما</h6>
  </div>
  <div class="card-body">
    <div class="row text-center">
      <div class="col-3">
        <div class="fw-bold"><?= e($predMap[$myBet->prediction] ?? $myBet->prediction) ?></div>
        <small class="text-muted">پیش‌بینی</small>
      </div>
      <div class="col-3">
        <div class="fw-bold"><?= number_format((float)$myBet->amount_usdt, 4) ?></div>
        <small class="text-muted">مبلغ (USDT)</small>
      </div>
      <div class="col-3">
        <div class="fw-bold <?= $myBet->status === 'won' ? 'text-success' : ($myBet->status === 'lost' ? 'text-danger' : '') ?>">
          <?= $statusMap[$myBet->status] ?? $myBet->status ?>
        </div>
        <small class="text-muted">وضعیت</small>
      </div>
      <div class="col-3">
        <?php if(isset($myBet->payout_usdt) && (float)$myBet->payout_usdt > 0): ?>
          <div class="fw-bold text-success"><?= number_format((float)$myBet->payout_usdt, 4) ?></div>
          <small class="text-muted">پاداش (USDT)</small>
        <?php else: ?>
          <div class="text-muted">—</div>
          <small class="text-muted">پاداش</small>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- فرم پیش‌بینی -->
<?php elseif($isOpen): ?>
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <span class="material-icons icon-md">how_to_vote</span>
      ثبت پیش‌بینی
    </h6>
  </div>
  <div class="card-body">
    <div class="alert alert-warning small mb-3">
      <span class="material-icons icon-xs">info</span>
      پرداخت با USDT · کمیسیون <?= number_format((float)$game->commission_percent) ?>٪ ·
      ددلاین: <?= e(substr((string)$game->bet_deadline, 0, 16)) ?>
    </div>

    <!-- پیش‌نمایش بازده (محاسبه client-side) -->
    <div id="returnPreview" class="alert alert-info small mb-3 d-none">
      <strong>بازده تقریبی شما:</strong>
      <span id="previewAmount">—</span> USDT
      <small class="text-muted">(بر اساس توزیع فعلی — ممکن است تغییر کند)</small>
    </div>

    <form id="betForm" method="POST" action="<?= url('/prediction/' . (int)$game->id . '/bet') ?>" data-game-id="<?= (int)$game->id ?>" data-total-pool="<?= e((string)$pool) ?>" data-commission="<?= e((string)((float)$game->commission_percent / 100)) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="idempotency_key" value="<?= e('prediction_' . (int)$game->id . '_' . bin2hex(random_bytes(8))) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">پیش‌بینی شما <span class="text-danger">*</span></label>
        <div class="row g-2">
          <div class="col-4">
            <label class="d-block border rounded p-2 text-center cursor-pointer prediction-card" data-pool="<?= $ph ?>">
              <input type="radio" name="prediction" value="home" class="d-none" required>
              <div class="fw-bold"><?= e($game->team_home) ?></div>
              <small class="text-muted">خانه برنده · <?= $pct($ph) ?>٪</small>
            </label>
          </div>
          <div class="col-4">
            <label class="d-block border rounded p-2 text-center cursor-pointer prediction-card" data-pool="<?= $pd ?>">
              <input type="radio" name="prediction" value="draw" class="d-none">
              <div class="fw-bold">مساوی</div>
              <small class="text-muted">Draw · <?= $pct($pd) ?>٪</small>
            </label>
          </div>
          <div class="col-4">
            <label class="d-block border rounded p-2 text-center cursor-pointer prediction-card" data-pool="<?= $pa ?>">
              <input type="radio" name="prediction" value="away" class="d-none">
              <div class="fw-bold"><?= e($game->team_away) ?></div>
              <small class="text-muted">مهمان برنده · <?= $pct($pa) ?>٪</small>
            </label>
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">مبلغ پیش‌بینی (USDT) <span class="text-danger">*</span></label>
        <input type="number" name="amount_usdt" id="betAmount" class="form-control"
               min="<?= (float)$game->min_bet_usdt ?>"
               max="<?= (float)$game->max_bet_usdt ?>"
               step="0.01"
               placeholder="بین <?= (float)$game->min_bet_usdt ?> تا <?= (float)$game->max_bet_usdt ?>"
               required>
        <small class="text-muted">
          حداقل: <?= number_format((float)$game->min_bet_usdt, 2) ?> | حداکثر: <?= number_format((float)$game->max_bet_usdt, 2) ?> USDT
        </small>
      </div>

      <button type="submit" class="btn btn-primary w-100" id="submitBet">
        <span class="material-icons icon-md">check_circle</span>
        ثبت پیش‌بینی
      </button>
    </form>
  </div>
</div>

<?php elseif($game->status === 'cancelled'): ?>
  <div class="alert alert-danger text-center mt-3">
    <i class="material-icons">cancel</i> این بازی لغو شده است. پیش‌بینی‌ها کامل برگشت داده شده‌اند.
  </div>
<?php else: ?>
  <div class="alert alert-info text-center mt-3">
    <i class="material-icons">timer_off</i> مهلت ثبت پیش‌بینی برای این بازی تمام شده است.
  </div>
<?php endif; ?>



<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userpredictionshow.js') . '"></script>';
include view_path('layouts.user');
?>