<?php
$title = 'سطح کاربری من';
ob_start();
?>

<div class="content-header">
  <h4 class="page-title mb-1">
    <span class="material-icons text-primary">workspace_premium</span>
    سطح کاربری من
  </h4>
  <p class="text-muted mb-0 small">با فعالیت بیشتر یا خرید، سطح خود را ارتقا دهید و از مزایای ویژه بهره‌مند شوید</p>
</div>

<?php if ($progress && $progress->current): ?>
<!-- سطح فعلی -->
<div class="card mt-3">
  <div class="card-body text-center py-4">
    <div class="level-icon-circle mx-auto mb-3" data-level-color="<?= e($progress->current->color ?? '#ccc') ?>">
      <span class="material-icons level-icon" data-level-color="<?= e($progress->current->color ?? '#ccc') ?>">
        <?= e($progress->current->icon ?? 'workspace_premium') ?>
      </span>
    </div>
    <h4 class="level-name mb-2" data-level-color="<?= e($progress->current->color ?? '#333') ?>">
      <?= e($progress->current->name ?? 'نامشخص') ?>
    </h4>

    <?php if (isset($progress->level_type) && $progress->level_type === 'purchased' && !empty($progress->level_expires_at)): ?>
    <span class="badge bg-info small">خریداری‌شده — انقضا: <?= to_jalali($progress->level_expires_at) ?></span>
    <?php else: ?>
    <span class="badge bg-success bg-opacity-15 text-success small">کسب شده با فعالیت</span>
    <?php endif; ?>

    <p class="text-muted mt-2 mb-0 small">
      فعالیت ماهانه: <strong><?= $progress->monthly_active_days ?? 0 ?></strong> روز
    </p>
  </div>
</div>

<!-- پاداش‌های فعلی -->
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <span class="material-icons text-success icon-lg align-middle">card_giftcard</span>
      مزایای سطح فعلی شما
    </h6>
  </div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="text-primary mb-1"><?= e($bonuses->earning_bonus_percent) ?>%</h5>
        <small class="text-muted">افزایش درآمد تسک</small>
      </div>
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="text-info mb-1"><?= e($bonuses->referral_bonus_percent) ?>%</h5>
        <small class="text-muted">افزایش کمیسیون معرفی</small>
      </div>
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="text-warning mb-1">+<?= e($bonuses->daily_task_limit_bonus) ?></h5>
        <small class="text-muted">سقف تسک روزانه</small>
      </div>
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="text-success mb-1"><?= number_format($bonuses->withdrawal_limit_bonus) ?></h5>
        <small class="text-muted">افزایش سقف برداشت</small>
      </div>
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="mb-1"><?= $bonuses->priority_support ? '✅' : '❌' ?></h5>
        <small class="text-muted">پشتیبانی اولویت‌دار</small>
      </div>
      <div class="col-md-4 col-6 mb-3 text-center">
        <h5 class="mb-1"><?= $bonuses->special_badge ? '✅' : '❌' ?></h5>
        <small class="text-muted">نشان ویژه</small>
      </div>
    </div>
  </div>
</div>

<!-- پیشرفت تا سطح بعدی -->
<?php if (!$progress->is_max && $progress->next): ?>
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <span class="material-icons text-warning icon-lg align-middle">trending_up</span>
      پیشرفت تا سطح «<?= e($progress->next->name) ?>»
    </h6>
  </div>
  <div class="card-body">
    <!-- نوار پیشرفت کلی -->
    <div class="mb-3">
      <div class="d-flex justify-content-between mb-1 small">
        <span>پیشرفت کلی</span>
        <span class="fw-bold"><?= e($progress->progress) ?>%</span>
      </div>
      <div class="progress progress-thin">
        <div class="progress-bar" data-width="<?= e($progress->progress) ?>%" data-level-color="<?= e($progress->next->color ?? '#4fc3f7') ?>"></div>
      </div>
    </div>

    <!-- جزئیات -->
    <?php foreach ($progress->details as $detail): ?>
    <div class="mb-2">
      <div class="d-flex justify-content-between small">
        <span><?= e($detail->label) ?></span>
        <span>
          <strong><?= is_float($detail->current) ? number_format($detail->current) : $detail->current ?></strong>
          / <?= is_float($detail->required) ? number_format($detail->required) : $detail->required ?>
        </span>
      </div>
      <div class="progress progress-thin">
        <div class="progress-bar bg-info" data-width="<?= e($detail->percent) ?>%"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- تمام سطوح + خرید -->
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <span class="material-icons text-primary icon-lg align-middle">stars</span>
      سطوح موجود
    </h6>
  </div>
  <div class="card-body">
    <div class="row">
      <?php foreach ($allLevels as $level): ?>
      <?php $isCurrent = ($progress && $progress->current && $progress->current->slug === $level->slug); ?>
      <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100 level-card <?= $isCurrent ? 'border-primary' : '' ?>" data-level-color="<?= e($level->color) ?>">
          <div class="card-body text-center">
            <div class="level-icon-circle mx-auto mb-2" data-level-color="<?= e($level->color) ?>">
              <span class="material-icons level-icon" data-level-color="<?= e($level->color) ?>">
                <?= e($level->icon ?? 'workspace_premium') ?>
              </span>
            </div>
            <h6 class="level-name mb-1" data-level-color="<?= e($level->color) ?>"><?= e($level->name) ?></h6>

            <?php if ($isCurrent): ?>
            <span class="badge bg-success mb-2">سطح فعلی شما</span>
            <?php endif; ?>

            <ul class="list-unstyled text-start small mb-2">
              <li>📈 افزایش درآمد: <strong><?= e($level->earning_bonus_percent) ?>%</strong></li>
              <li>👥 کمیسیون معرفی: <strong>+<?= e($level->referral_bonus_percent) ?>%</strong></li>
              <li>📋 سقف تسک: <strong>+<?= e($level->daily_task_limit_bonus) ?></strong></li>
              <?php if ($level->priority_support): ?><li>⭐ پشتیبانی اولویت‌دار</li><?php endif; ?>
              <?php if ($level->special_badge): ?><li>🏅 نشان ویژه</li><?php endif; ?>
            </ul>

            <hr class="my-2">

            <div class="small text-muted">
              <strong>شرایط فعالیت:</strong><br>
              <?= e($level->min_active_days) ?> روز | <?= e($level->min_completed_tasks) ?> تسک
              <?php if ($level->min_total_earning > 0): ?>
              <br><?= number_format($level->min_total_earning) ?> تومان درآمد
              <?php endif; ?>
            </div>

            <?php
            $canBuy = !$isCurrent && ($level->purchase_price_irt > 0 || $level->purchase_price_usdt > 0);
            $price = $currencyMode === 'usdt' ? $level->purchase_price_usdt : $level->purchase_price_irt;
            $priceLabel = $currencyMode === 'usdt' ? number_format($price, 2) . ' USDT' : number_format($price) . ' تومان';
            ?>

            <?php if ($canBuy && $price > 0): ?>
            <button class="btn btn-sm btn-outline-primary mt-2 w-100 btn-buy-level"
                    data-slug="<?= e($level->slug) ?>"
                    data-name="<?= e($level->name) ?>"
                    data-price="<?= e($price) ?>"
                    data-price-label="<?= e($priceLabel) ?>"
                    data-currency="<?= e($currencyMode) ?>">
              خرید <?= e($priceLabel) ?>
            </button>
            <?php elseif ($level->slug === 'bronze'): ?>
            <span class="text-muted small">سطح پیش‌فرض</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- هشدار سقوط سطح -->
<div class="alert mt-3 level-warning">
  <div class="d-flex align-items-start">
    <span class="material-icons me-2 text-warning">warning</span>
    <div class="small text-warning">
      <strong>توجه مهم:</strong>
      اگر در یک ماه کمتر از <strong><?= setting('level_downgrade_inactive_days', 3) ?> روز</strong> فعالیت داشته باشید، سطح شما (در صورت کسب با فعالیت) به سطح برنز بازمی‌گردد.
      سطوح خریداری‌شده تا پایان مدت اعتبار حفظ می‌شوند.
    </div>
  </div>
</div>

<!-- تاریخچه -->
<div class="card mt-3 mb-4">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <span class="material-icons text-primary icon-lg align-middle">history</span>
      تاریخچه تغییرات سطح
    </h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead>
          <tr><th>#</th><th>از سطح</th><th>به سطح</th><th>نوع</th><th>دلیل</th><th>تاریخ</th></tr>
        </thead>
        <tbody>
          <?php if (empty($history)): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">تاریخچه‌ای وجود ندارد.</td></tr>
          <?php else: ?>
          <?php
          $typeLabels = ['upgrade'=>'ارتقا','downgrade'=>'سقوط','purchase'=>'خرید','expire'=>'انقضا','admin'=>'ادمین','reset'=>'ریست'];
          $typeClasses = ['upgrade'=>'badge-success','downgrade'=>'badge-danger','purchase'=>'badge-info','expire'=>'badge-warning','admin'=>'badge-secondary','reset'=>'badge-secondary'];
          ?>
          <?php foreach ($history as $idx => $h): ?>
          <tr>
            <td class="text-muted"><?= $idx + 1 ?></td>
            <td><?= e($h->from_level_name ?? $h->from_level ?? '—') ?></td>
            <td><strong><?= e($h->to_level_name ?? $h->to_level) ?></strong></td>
            <td><span class="badge <?= $typeClasses[$h->change_type] ?? '' ?>"><?= e($typeLabels[$h->change_type] ?? $h->change_type) ?></span></td>
            <td class="small"><?= e($h->reason ?? '—') ?></td>
            <td class="small"><?= to_jalali($h->created_at ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userlevelindex.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userlevelindex.js') . '" data-purchase-url="' . e(url('/level/purchase')) . '"></script>';
include view_path('layouts.user');
?>
