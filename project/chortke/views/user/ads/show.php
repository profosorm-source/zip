<?php
$title = 'تحلیل جامع آگهی';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' ads-show-page');
ob_start();

$finance = $finance ?? [];
$activeEscrow = $finance['active_escrow'] ?? null;
$lastEscrow = $finance['escrows'][0] ?? null;
$displayEscrow = $activeEscrow ?: $lastEscrow;
$deliverySummary = $finance['delivery_summary'] ?? null;
$deliveryByType = $finance['delivery_by_type'] ?? [];
$financeTransactions = $finance['transactions'] ?? [];

$isUsdt = ((string)($ad->currency ?? 'irt')) === 'usdt';
$currencyLabel = $isUsdt ? 'USDT' : 'تومان';
$currencyShort = $isUsdt ? 'USDT' : 'تومان';

$typeMeta = [
  'banner' => ['label'=>'بنر تبلیغاتی','icon'=>'view_carousel','color'=>'#7c3aed'],
  'notification' => ['label'=>'نوتیفیکیشن','icon'=>'notifications_active','color'=>'#f59e0b'],
  'seo' => ['label'=>'سئو / کلیک','icon'=>'travel_explore','color'=>'#10b981'],
  'social_task' => ['label'=>'شبکه اجتماعی','icon'=>'groups','color'=>'#3b82f6'],
  'custom_task' => ['label'=>'تسک سفارشی','icon'=>'assignment_turned_in','color'=>'#ef4444'],
  'adtube' => ['label'=>'ویدیو AdTube','icon'=>'smart_display','color'=>'#ec4899'],
];
$tm = $typeMeta[$ad->type] ?? ['label'=>$ad->type,'icon'=>'campaign','color'=>'#64748b'];
$typeLabel = $tm['label'];

$statusMap = [
  'active'=>'فعال','approved'=>'فعال',
  'pending'=>'در انتظار','pending_review'=>'در انتظار بررسی',
  'paused'=>'متوقف',
  'completed'=>'تکمیل‌شده',
  'cancelled'=>'لغو شده',
  'rejected'=>'رد شده',
  'expired'=>'منقضی',
];
$statusLabel = $statusMap[$ad->status] ?? $ad->status;
$statusClass = match($ad->status) {
  'active','approved' => 'success',
  'pending','pending_review' => 'warning',
  'paused' => 'secondary',
  'completed' => 'primary',
  'cancelled','rejected','expired' => 'danger',
  default => 'secondary'
};

$totalBudget = (float)($ad->total_budget ?? $ad->budget ?? 0);
$remainingBudget = (float)($ad->remaining_budget ?? 0);
$spentBudget = max(0, $totalBudget - $remainingBudget);
$spendPct = $totalBudget > 0 ? min(100, round($spentBudget / $totalBudget * 100, 1)) : 0;

$impressions = (int)($ad->impressions ?? 0);
$clicks = (int)($ad->clicks ?? 0);
$ctr = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0;

$totalCount = (int)($ad->total_count ?? 0);
$completedCount = (int)($ad->completed_count ?? 0);
$pendingCount = (int)($ad->pending_count ?? 0);
$remainingCount = (int)($ad->remaining_count ?? 0);

$money = fn($n) => number_format((float)$n, $isUsdt ? 2 : 0);

$targetUrl = $ad->target_url ?? $ad->link ?? $ad->site_url ?? null;
$imagePath = $ad->image_path ?? null;
$isBanner = $ad->type === 'banner';

$execTotal = count($executions ?? []);
$execApproved = 0; $execPending = 0; $execRejected = 0;
foreach ($executions ?? [] as $e) {
  $s = strtolower((string)($e->status ?? ''));
  if (in_array($s, ['approved','completed','click'])) $execApproved++;
  elseif (in_array($s, ['submitted','pending','started','watching'])) $execPending++;
  else $execRejected++;
}
?>
<div class="ads-show-wrap" dir="rtl">
  <div class="container py-4">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="ads-bc mb-3">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= e(url('/ads')) ?>">تبلیغات من</a></li>
      <li class="breadcrumb-item active">تحلیل کمپین #<?= (int)$ad->id ?></li>
    </ol>
    <div class="ads-bc-actions">
      <button type="button" class="ads-icon-btn" id="copyAdLinkBtn" data-url="<?= e(url('/ads/'.$ad->id)) ?>" title="کپی لینک"><span class="material-icons">link</span></button>
      <a href="<?= e(url('/ads/create')) ?>" class="ads-icon-btn" title="کمپین مشابه"><span class="material-icons">content_copy</span></a>
    </div>
  </nav>

  <!-- Header Card -->
  <div class="ads-header-card" style="--accent:<?= e($tm['color']) ?>">
    <div class="ads-header-main">
      <div class="ads-type-icon"><span class="material-icons"><?= e($tm['icon']) ?></span></div>
      <div class="ads-header-text">
        <div class="ads-header-tags">
          <span class="ads-tag type"><?= e($typeLabel) ?></span>
          <span class="ads-tag status s-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
          <?php if(!empty($ad->placement)): ?><span class="ads-tag muted">placement: <?= e($ad->placement) ?></span><?php endif; ?>
        </div>
        <h1><?= e($ad->title) ?></h1>
        <?php if(!empty($ad->description)): ?>
        <p class="ads-desc"><?= e(mb_strimwidth((string)$ad->description, 0, 180, '…', 'UTF-8')) ?></p>
        <?php endif; ?>
        <?php if($targetUrl): ?>
        <a href="<?= e($targetUrl) ?>" target="_blank" rel="noopener" class="ads-target-link"><span class="material-icons">open_in_new</span> <?= e(mb_strimwidth($targetUrl,0,60,'…','UTF-8')) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="ads-header-side">
      <div class="ads-budget-ring" data-pct="<?= e((string)$spendPct) ?>">
        <svg viewBox="0 0 88 88"><circle class="bg" cx="44" cy="44" r="38"/><circle class="fg" cx="44" cy="44" r="38" stroke-dashoffset="<?= 238.76 * (1 - $spendPct/100) ?>"/></svg>
        <div class="ads-budget-ring-lbl"><b><?= $spendPct ?>%</b><small>مصرف</small></div>
      </div>
      <div class="ads-budget-txt">
        <div><small>بودجه کل</small><strong><?= $money($totalBudget) ?> <?= e($currencyLabel) ?></strong></div>
        <div><small>باقی‌مانده</small><strong><?= $money($remainingBudget) ?> <?= e($currencyLabel) ?></strong></div>
      </div>
    </div>
  </div>

  <!-- KPI Grid -->
  <div class="ads-kpi-grid">
    <div class="ads-kpi"><div class="kpi-ic ic-wallet"><span class="material-icons">account_balance_wallet</span></div><div><small>مانده بودجه</small><strong><?= $money($remainingBudget) ?> <span><?= e($currencyShort) ?></span></strong></div></div>
    <div class="ads-kpi"><div class="kpi-ic ic-eye"><span class="material-icons">visibility</span></div><div><small>نمایش</small><strong><?= number_format($impressions) ?></strong></div></div>
    <div class="ads-kpi"><div class="kpi-ic ic-click"><span class="material-icons">ads_click</span></div><div><small>کلیک / اجرا</small><strong><?= number_format($clicks) ?></strong><em>CTR <?= $ctr ?>%</em></div></div>
    <div class="ads-kpi"><div class="kpi-ic ic-task"><span class="material-icons">fact_check</span></div><div><small>ظرفیت</small><strong><?= $totalCount ? number_format($remainingCount) . ' / ' . number_format($totalCount) : '—' ?></strong><?php if($totalCount): ?><em>تکمیل <?= $completedCount ?> · انتظار <?= $pendingCount ?></em><?php endif; ?></div></div>
  </div>

  <?php if (((string)($ad->type ?? '')) === 'notification' && !empty($adStats)): ?>
  <!-- Notification Engagement Analytics -->
  <div class="ads-card" style="margin-top:20px">
    <div class="ads-card-h"><span class="material-icons">notifications_active</span> آمار خواندن نوتیفیکیشن (۳۰ روز)</div>
    <div class="ads-card-b">
      <div class="ads-kpi-grid">
        <div class="ads-kpi"><div class="kpi-ic ic-eye"><span class="material-icons">visibility</span></div><div><small>ارسال</small><strong><?= number_format((int)($adStats['sent'] ?? 0)) ?></strong></div></div>
        <div class="ads-kpi"><div class="kpi-ic ic-click"><span class="material-icons">done_all</span></div><div><small>خوانده‌شده</small><strong><?= number_format((int)($adStats['read_count'] ?? 0)) ?></strong><em>نرخ <?= ($adStats['read_rate'] ?? 0) ?>%</em></div></div>
        <div class="ads-kpi"><div class="kpi-ic ic-click"><span class="material-icons">ads_click</span></div><div><small>کلیک</small><strong><?= number_format((int)($adStats['clicked'] ?? 0)) ?></strong><em>CTR <?= ($adStats['ctr'] ?? 0) ?>%</em></div></div>
        <div class="ads-kpi"><div class="kpi-ic ic-task"><span class="material-icons">close</span></div><div><small>رد/نادیده</small><strong><?= number_format((int)($adStats['dismissed'] ?? 0)) ?></strong><em><?= ($adStats['dismissed_rate'] ?? 0) ?>%</em></div></div>
        <div class="ads-kpi"><div class="kpi-ic ic-time"><span class="material-icons">schedule</span></div><div><small>میانگین خواندن</small><strong><?= (int)($adStats['avg_read_sec'] ?? 0) ?>s</strong><em>تا <?= ($adStats['max_duration_sec'] ?? 0) ?>s</em></div></div>
        <div class="ads-kpi"><div class="kpi-ic ic-alert"><span class="material-icons">bolt</span></div><div><small>بستنِ زود</small><strong><?= number_format((int)($adStats['fast_close'] ?? 0)) ?></strong><em><?= ($adStats['fast_close_rate'] ?? 0) ?>%</em></div></div>
      </div>
      <p style="margin-top:12px;font-size:12px;color:#94a3b8">
        برای دیدن جزئیات به‌ازای هر کاربر، از اندپوینت <code>GET /ads/<?= (int)$ad->id ?>/notification-stats</code> استفاده کنید.
      </p>
    </div>
  </div>
  <?php endif; ?>

  <!-- 2-col: Chart + Details -->
  <div class="ads-two-col">
    <!-- Chart -->
    <div class="ads-card">
      <div class="ads-card-h"><span class="material-icons">show_chart</span> عملکرد ۷ روز اخیر</div>
      <div class="ads-card-b">
        <canvas id="performanceChart" height="190"></canvas>
        <div class="ads-chart-legend"><span><i style="background:#f0b90b"></i> نمایش</span><span><i style="background:#10b981"></i> کلیک</span><span><i style="background:#8b5cf6"></i> مصرف (<?= e($currencyLabel) ?>)</span></div>
      </div>
    </div>

    <!-- Ad details / banner preview -->
    <div class="ads-card">
      <div class="ads-card-h"><span class="material-icons">info</span> مشخصات کمپین</div>
      <div class="ads-card-b ads-detail-list">
        <?php if($isBanner && $imagePath): ?>
        <div class="ads-banner-preview">
          <img src="<?= e(str_starts_with($imagePath,'http') ? $imagePath : url('/' . ltrim($imagePath,'/'))) ?>" alt="بنر" onerror="this.parentElement.style.display='none'">
          <small>پیش‌نمایش بنر</small>
        </div>
        <?php endif; ?>
        <dl>
          <dt>شناسه</dt><dd>#<?= (int)$ad->id ?></dd>
          <dt>نوع</dt><dd><?= e($typeLabel) ?></dd>
          <dt>وضعیت</dt><dd><span class="ads-dot s-<?= e($statusClass) ?>"></span><?= e($statusLabel) ?></dd>
          <dt>ارز</dt><dd><?= e(strtoupper((string)($ad->currency ?? 'irt'))) ?></dd>
          <?php if(!empty($ad->placement)): ?><dt>placement</dt><dd><?= e($ad->placement) ?></dd><?php endif; ?>
          <?php if(!empty($ad->price_per_task)): ?><dt>پاداش هر تسک</dt><dd><?= $money($ad->price_per_task) ?> <?= e($currencyLabel) ?></dd><?php endif; ?>
          <dt>کمیسیون سایت</dt><dd><?= number_format((float)($ad->site_commission_percent ?? 0),1) ?>%</dd>
          <dt>تاریخ ثبت</dt><dd><?= !empty($ad->created_at) ? date('Y/m/d H:i', strtotime($ad->created_at)) : '—' ?></dd>
          <dt>آخرین بروزرسانی</dt><dd><?= !empty($ad->updated_at) ? date('Y/m/d H:i', strtotime($ad->updated_at)) : '—' ?></dd>
        </dl>
        <?php if($targetUrl): ?>
        <a href="<?= e($targetUrl) ?>" target="_blank" class="ads-open-target"><span class="material-icons">open_in_new</span> باز کردن لینک مقصد</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Finance -->
  <div class="ads-card finance-card">
    <div class="ads-card-h"><span class="material-icons">account_balance</span> شفافیت مالی / Escrow
      <span class="ads-card-badge"><?= e($finance['order_type'] ?? '—') ?></span>
    </div>
    <div class="ads-card-b">
      <div class="ads-finance-grid">
        <div class="fbox"><small>Escrow قفل‌شده</small><b><?= $displayEscrow ? $money($displayEscrow->amount) : '۰' ?> <?= e($currencyLabel) ?></b><em><?= e($displayEscrow->status ?? 'بدون رکورد') ?></em></div>
        <div class="fbox"><small>آزاد شده</small><b><?= $money($displayEscrow->partial_released ?? $deliverySummary->spent_amount ?? 0) ?> <?= e($currencyLabel) ?></b><em>بودجه + کارمزد</em></div>
        <div class="fbox"><small>مصرف واقعی</small><b><?= $money($deliverySummary->spent_amount ?? 0) ?> <?= e($currencyLabel) ?></b><em>کارمزد <?= $money($deliverySummary->platform_fee ?? 0) ?></em></div>
        <div class="fbox"><small>رویدادهای مالی</small><b><?= number_format((int)($deliverySummary->event_count ?? 0)) ?></b><em><?= !empty($deliverySummary->last_delivery_at) ? date('m/d H:i', strtotime($deliverySummary->last_delivery_at)) : '—' ?></em></div>
      </div>
      <?php if (!empty($displayEscrow->refund_reason)): ?>
      <div class="ads-alert warn"><b>دلیل refund:</b> <?= e($displayEscrow->refund_reason) ?><?php if(!empty($displayEscrow->refunded_at)): ?> <span class="muted">— <?= e(substr((string)$displayEscrow->refunded_at,0,16)) ?></span><?php endif; ?></div>
      <?php endif; ?>

      <?php if (!empty($deliveryByType)): ?>
      <div class="ads-table-wrap slim">
        <table class="ads-mini-table"><thead><tr><th>نوع delivery</th><th>واحد</th><th>بودجه</th><th>کارمزد</th></tr></thead>
        <tbody>
        <?php foreach($deliveryByType as $r): ?>
          <tr><td><?= e($r->event_type) ?></td><td><?= number_format((float)$r->units) ?></td><td><?= $money($r->amount) ?></td><td><?= $money($r->platform_fee) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>

      <?php if (!empty($financeTransactions)): ?>
      <details class="ads-finance-tx"><summary>۵ تراکنش اخیر (کلیک کنید)</summary>
      <div class="ads-table-wrap slim">
        <table class="ads-mini-table"><thead><tr><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>شرح</th></tr></thead><tbody>
        <?php foreach(array_slice($financeTransactions,0,5) as $tx): ?>
          <tr><td><?= e($tx->type ?? '—') ?></td><td><?= $money($tx->amount ?? 0) ?> <?= e(($tx->currency ?? 'irt')==='usdt'?'USDT':'تومان') ?></td><td><?= e($tx->status ?? '—') ?></td><td class="muted"><?= e(mb_strimwidth((string)($tx->description ?? '—'),0,70,'…','UTF-8')) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      </details>
      <?php endif; ?>
    </div>
  </div>

  <!-- Executions -->
  <div class="ads-card">
    <div class="ads-card-h"><span class="material-icons">history</span> اجراها و دریافتی‌ها
      <span class="ads-card-badge"><?= $execTotal ?> مورد · تأیید <?= $execApproved ?> · انتظار <?= $execPending ?> · رد <?= $execRejected ?></span>
    </div>
    <div class="ads-card-b">
      <div class="ads-exec-toolbar">
        <div class="ads-search"><span class="material-icons">search</span><input id="execSearch" type="search" placeholder="جستجو در کاربر / شناسه / وضعیت…"></div>
        <div class="ads-exec-filters" id="execFilters">
          <button data-f="all" class="on">همه</button>
          <button data-f="submitted">ارسال‌شده</button>
          <button data-f="approved">تأیید</button>
          <button data-f="rejected">رد</button>
          <button data-f="pending">در انتظار</button>
        </div>
      </div>

      <div class="ads-table-wrap">
      <table class="ads-exec-table" id="execTable">
        <thead><tr><th>#</th><th>کاربر</th><th>وضعیت</th><th>تاریخ</th><th>جزئیات / اقدام</th></tr></thead>
        <tbody>
        <?php if(empty($executions)): ?>
          <tr class="no-data"><td colspan="5"><span class="material-icons">inbox</span><p>هنوز اجرایی ثبت نشده است.</p></td></tr>
        <?php else: foreach($executions as $ex):
          $exStatus = strtolower((string)($ex->status ?? (($ad->type ?? '') === 'banner' ? 'click' : 'record')));
          $exDate = (string)($ex->created_at ?? $ex->clicked_at ?? $ex->completed_at ?? '');
          $exUser = trim((string)($ex->executor ?? $ex->full_name ?? $ex->username ?? 'کاربر ناشناس'));
          $exId = (int)$ex->id;
          $badgeClass = match($exStatus) {
            'completed','approved','click','delivery' => 'ok',
            'submitted','pending','started','watching' => 'wait',
            'rejected','fraud' => 'bad',
            default => 'muted'
          };
          $badgeLabel = match($exStatus) {
            'completed','approved' => 'موفق',
            'submitted' => 'ارسال‌شده',
            'pending' => 'در انتظار',
            'started','watching' => 'شروع',
            'rejected' => 'رد شده',
            'fraud' => 'تقلب',
            'click' => 'کلیک',
            default => $exStatus
          };
        ?>
        <tr data-status="<?= e($exStatus) ?>" data-search="<?= e(mb_strtolower($exUser.' '.$exId.' '.$badgeLabel,'UTF-8')) ?>">
          <td class="c-id">#<?= $exId ?></td>
          <td><div class="c-user"><span class="av"><?= e(mb_substr($exUser,0,1,'UTF-8')) ?></span><?= e($exUser) ?></div></td>
          <td><span class="st st-<?= $badgeClass ?>"><?= e($badgeLabel) ?></span></td>
          <td class="c-date"><?= $exDate ? date('Y/m/d H:i', strtotime($exDate)) : '—' ?></td>
          <td class="c-act">
            <?php if($ad->type === 'custom_task'): ?>
              <button type="button" class="btn-sm ghost" data-toggle-proof="#proof-<?= $exId ?>">مشاهده مدرک</button>
              <?php if($exStatus === 'submitted'): ?>
                <button type="button" class="btn-sm ok" data-open-approve="<?= $exId ?>">تأیید</button>
                <button type="button" class="btn-sm bad" data-open-reject="<?= $exId ?>">رد</button>
              <?php elseif($exStatus === 'approved'): ?>
                <span class="st st-ok">تأیید شده</span>
              <?php elseif($exStatus === 'rejected'): ?>
                <span class="st st-bad">رد شده</span>
              <?php elseif($exStatus === 'disputed'): ?>
                <a class="btn-sm warn" href="<?= e(url('/custom-tasks/disputes/'.(int)($ex->dispute_id ?? 0))) ?>">اختلاف</a>
              <?php endif; ?>
            <?php else:
              $exType = $ad->type;
              if ($exType === 'social_task') echo '<span class="muted-sm">امتیازی / بدون مدرک</span>';
              elseif ($exType === 'seo') { $sc = $ex->score ?? $ex->quality_score ?? null; echo $sc !== null ? '<span class="st st-info">امتیاز '.$sc.'</span>' : '<span class="muted-sm">SEO</span>'; }
              elseif ($exType === 'adtube') { $wd = $ex->watch_duration ?? null; echo $wd ? '<span class="muted-sm">تماشا '.$wd.'ث</span>' : '<span class="muted-sm">—</span>'; }
              elseif ($exType === 'banner') { $ip = $ex->ip_address ?? $ex->ip ?? null; echo $ip ? '<code class="ip">'.e($ip).'</code>' : '<span class="muted-sm">کلیک</span>'; }
              else echo '<span class="muted-sm">—</span>';
            endif; ?>
          </td>
        </tr>
        <?php if($ad->type === 'custom_task'): ?>
        <tr class="proof-row" id="proof-<?= $exId ?>" hidden>
          <td colspan="5">
            <div class="proof-box">
              <div class="proof-grid">
                <div><b>کد</b><span><?= e($ex->proof_code ?? '—') ?></span></div>
                <div><b>لینک</b><span><?php if(!empty($ex->proof_url)): ?><a href="<?= e($ex->proof_url) ?>" target="_blank">باز کردن</a><?php else: ?>—<?php endif; ?></span></div>
                <div><b>فایل</b><span><?= e($ex->proof_file ?? '—') ?></span></div>
                <div><b>زمان ارسال</b><span dir="ltr"><?= e(substr((string)($ex->submitted_at ?? $ex->created_at ?? ''),0,16)) ?></span></div>
              </div>
              <div class="proof-text"><b>متن مدرک:</b><p><?= nl2br(e($ex->proof_text ?? '—')) ?></p></div>
              <?php if($exStatus === 'submitted'): ?>
              <div class="proof-actions">
                <form method="POST" action="<?= e(url('/custom-tasks/ad/submissions/'.$exId.'/approve')) ?>" class="approve-form">
                  <?= csrf_field() ?>
                  <input type="text" name="note" placeholder="یادداشت تأیید (اختیاری)">
                  <button class="btn-sm ok" type="submit"><span class="material-icons">check_circle</span> تأیید</button>
                </form>
                <form method="POST" action="<?= e(url('/custom-tasks/ad/submissions/'.$exId.'/reject')) ?>" class="reject-form">
                  <?= csrf_field() ?>
                  <input type="text" name="reason" placeholder="دلیل رد (الزامی)" required>
                  <button class="btn-sm bad" type="submit"><span class="material-icons">cancel</span> رد</button>
                </form>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
      <div class="ads-exec-empty" id="execEmpty" hidden>نتیجه‌ای با این فیلتر پیدا نشد.</div>
    </div>
  </div>

  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useradsshow.css') . '">';
$chartData = [
  'impressions' => $impressions,
  'clicks' => $clicks,
  'spent' => (float)($deliverySummary->spent_amount ?? 0),
  'currency' => $currencyLabel,
];
$scripts = '<script nonce="<?= e(csp_nonce()) ?>">window.ADS_SHOW_DATA=' . json_encode($chartData, JSON_UNESCAPED_UNICODE) . ';</script>'
  . '<script nonce="<?= e(csp_nonce()) ?>" src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>'
  . '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/useradsshow.js') . '"></script>';
include view_path('layouts.user');
