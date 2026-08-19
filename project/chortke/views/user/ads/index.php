<?php
$title = 'مرکز مدیریت تبلیغات و کمپین‌ها (Binance Style)';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' ads-index-page ads-hub-page ads-create-page');
$initialSection = (($_GET['section'] ?? '') === 'create') ? 'create' : 'manage';
ob_start();

$summary = $summaryData ?? ($summary ?? []);
$ads = $ads ?? [];

$activeAds  = array_filter($ads, fn($a) => in_array((string)($a->status ?? ''), ['active', 'approved', 'pending', 'pending_review', 'paused'], true));
$historyAds = array_filter($ads, fn($a) => in_array((string)($a->status ?? ''), ['completed', 'cancelled', 'rejected', 'expired', 'exhausted'], true));

$totalViews     = array_sum(array_map(fn($a) => (int)($a->completed_count ?? 0), $ads));
$totalSpentReal = array_sum(array_map(fn($a) => (float)($a->spent_budget ?? 0), $ads));
$totalRemReal   = array_sum(array_map(fn($a) => (float)($a->remaining_budget ?? 0), $ads));
$avgProgress    = count($ads) > 0 ? round(array_sum(array_map(fn($a) => !empty($a->total_count) ? ((float)($a->completed_count ?? 0) / $a->total_count * 100) : 0, $ads)) / count($ads), 1) : 0;

$val = static function ($row, string $key, $default = null) {
    if (is_array($row)) return $row[$key] ?? $default;
    if (is_object($row)) return $row->{$key} ?? $default;
    return $default;
};
$typeMeta = static function (string $type): array {
    return match ($type) {
        'banner' => ['label' => 'بنر تبلیغاتی', 'icon' => 'view_carousel', 'class' => 'type-banner'],
        'notification' => ['label' => 'پیام تبلیغاتی', 'icon' => 'notifications_active', 'class' => 'type-notification'],
        'seo' => ['label' => 'سئو و کلیک', 'icon' => 'travel_explore', 'class' => 'type-seo'],
        'social_task' => ['label' => 'شبکه‌های اجتماعی', 'icon' => 'groups', 'class' => 'type-social'],
        'custom_task' => ['label' => 'تسک سفارشی', 'icon' => 'assignment_turned_in', 'class' => 'type-custom'],
        'adtube' => ['label' => 'تبلیغ ویدیویی AdTube', 'icon' => 'smart_display', 'class' => 'type-adtube'],
        default => ['label' => $type ?: 'تبلیغ عمومی', 'icon' => 'campaign', 'class' => 'type-default'],
    };
};
$statusMeta = static function (string $status): array {
    return match ($status) {
        'active', 'approved' => ['label' => 'فعال و در حال نمایش', 'class' => 'status-active', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)'],
        'pending', 'pending_review' => ['label' => 'در انتظار بررسی تیم مالی', 'class' => 'status-pending', 'color' => '#F0B90B', 'bg' => 'rgba(240,185,11,0.15)'],
        'paused' => ['label' => 'متوقف توسط شما', 'class' => 'status-paused', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)'],
        'completed' => ['label' => 'تکمیل‌شده و موفق', 'class' => 'status-completed', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.15)'],
        'cancelled' => ['label' => 'لغو شده و تسویه‌شده', 'class' => 'status-closed', 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.15)'],
        'rejected' => ['label' => 'رد شده توسط مدیریت', 'class' => 'status-rejected', 'color' => '#f6465d', 'bg' => 'rgba(246,70,93,0.15)'],
        'expired' => ['label' => 'منقضی‌شده', 'class' => 'status-expired', 'color' => '#64748b', 'bg' => 'rgba(100,116,139,0.15)'],
        'exhausted' => ['label' => 'اتمام بودجه', 'class' => 'status-exhausted', 'color' => '#f6465d', 'bg' => 'rgba(246,70,93,0.15)'],
        default => ['label' => $status ?: 'نامشخص', 'class' => 'status-default', 'color' => '#848e9c', 'bg' => 'rgba(132,142,156,0.15)'],
    };
};
$money = static fn($n): string => number_format((float)$n);
$intFmt = static fn($n): string => number_format((int)$n);
$total = (int)($summary['total'] ?? count($ads));
$active = (int)($summary['active'] ?? count($activeAds));
$spent = (float)($summary['spent_budget'] ?? $totalSpentReal);
$remaining = (float)($summary['remaining_budget'] ?? $totalRemReal);
$totalBudget = (float)($summary['total_budget'] ?? $summary['total_invested'] ?? ($spent + $remaining));
?>
<style>
/* ── استایل‌های تکمیلی هاب تبلیغات بایننس استایل ── */
.ads-hub-panel { display: none; }
.ads-hub-panel.active { display: block; }
.campaign-card { background: var(--fin-surface, #11161F); border: 1px solid var(--fin-border-soft, #181F2A); border-radius: 18px; padding: 24px; margin-bottom: 20px; box-shadow: 0 10px 28px rgba(0,0,0,0.05); transition: transform 0.2s ease, border-color 0.2s ease; }
.campaign-card:hover { transform: translateY(-2px); border-color: var(--gold, #F0B90B); }
.campaign-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid var(--fin-border-soft, #181F2A); padding-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.campaign-type-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: rgba(240,185,11,0.15); color: #F0B90B; flex-shrink: 0; }
.type-seo .campaign-type-icon { background: rgba(59,130,246,0.15); color: #3b82f6; }
.type-social .campaign-type-icon { background: rgba(236,72,153,0.15); color: #ec4899; }
.type-banner .campaign-type-icon { background: rgba(16,185,129,0.15); color: #10b981; }
.type-adtube .campaign-type-icon { background: rgba(239,68,68,0.15); color: #ef4444; }
.campaign-title-block h2 { font-size: 1.2rem; font-weight: 800; margin: 0 0 6px 0; color: inherit; }
.campaign-type-label { font-size: 0.85rem; opacity: 0.7; display: flex; align-items: center; gap: 6px; }
.campaign-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; background: var(--fin-surface-2, #161C27); padding: 18px; border-radius: 16px; margin-bottom: 18px; border: 1px solid var(--fin-border-soft, #181F2A); }
.campaign-foot { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; }
.campaign-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.ads-perf-table { width: 100%; border-collapse: collapse; margin-top: 18px; text-align: right; font-size: 0.95rem; }
.ads-perf-table th { padding: 14px; background: var(--fin-surface-2, #161C27); color: var(--text-dim, #848e9c); font-weight: 700; border-bottom: 1px solid var(--fin-border-soft, #181F2A); }
.ads-perf-table td { padding: 16px 14px; border-bottom: 1px solid var(--fin-border-soft, #181F2A); vertical-align: middle; }
.ads-perf-table tr:hover { background: rgba(255,255,255,0.02); }
</style>

<div class="ads-hub-shell" id="adsHubRoot"
     data-initial-section="<?= e($initialSection) ?>"
     data-type-info-url="<?= e(url('/ads/api/type-info')) ?>"
     data-validate-field-url="<?= e(url('/ads/api/validate-field')) ?>"
     data-preview-cost-url="<?= e(url('/ads/api/preview-cost')) ?>"
     data-store-url="<?= e(url('/ads/store')) ?>">

  <!-- Hero Section -->
  <section class="ads-hero" style="background: linear-gradient(135deg, var(--fin-surface, #11161F) 0%, var(--fin-surface-2, #161C27) 100%); border: 1px solid var(--fin-border-soft, #181F2A); border-radius: 20px; padding: 28px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);">
    <div class="ads-hero__main" style="display:flex; align-items:center; gap:18px;">
      <div class="ads-hero__icon" style="width:60px; height:60px; background:rgba(240,185,11,0.15); border:2px solid #F0B90B; border-radius:18px; display:flex; align-items:center; justify-content:center; color:#F0B90B; flex-shrink:0;"><span class="material-icons" style="font-size:2.4rem;">campaign</span></div>
      <div>
        <span class="ads-hero__kicker" style="color:#F0B90B; font-weight:800; font-size:0.85rem; display:block; margin-bottom:4px;">Binance Advertising Hub</span>
        <h1 style="font-size:1.6rem; font-weight:800; margin:0 0 6px 0;">مرکز تبلیغات و کمپین‌های من</h1>
        <p style="opacity:0.7; font-size:0.95rem; margin:0;">ایجاد، مدیریت و پیگیری نتایج تبلیغات بنری، ویدیویی، سئو و شبکه‌های اجتماعی در یک هاب واحد.</p>
      </div>
    </div>
    <div class="ads-hero__actions" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="ads-btn ads-btn-primary" data-ads-section="create" style="background:#F0B90B; color:#0b0e11; font-weight:800; border:none; padding:12px 24px; border-radius:14px; cursor:pointer; display:flex; align-items:center; gap:8px; box-shadow:0 6px 18px rgba(240,185,11,0.25);">
            <span class="material-icons">add_circle</span> ثبت تبلیغ جدید
        </button>
        <button type="button" class="ads-btn ads-btn-ghost" data-ads-section="manage" style="border:1px solid rgba(255,255,255,0.2); padding:12px 20px; border-radius:14px; cursor:pointer; color:inherit; background:transparent; display:flex; align-items:center; gap:8px;">
            <span class="material-icons">dashboard_customize</span> مدیریت تبلیغات
        </button>
        <a href="<?= e(url('/dashboard')) ?>" class="ads-btn ads-btn-ghost" style="border:1px solid rgba(255,255,255,0.2); padding:12px 20px; border-radius:14px; cursor:pointer; color:inherit; background:transparent; text-decoration:none; display:flex; align-items:center; gap:8px;">
            <span class="material-icons">arrow_forward</span> پنل کاربری
        </a>
    </div>
  </section>

  <!-- تب‌های ناوبری هاب تبلیغات -->
  <nav class="ads-subnav" style="display:flex; gap:12px; margin-bottom:28px; border-bottom:1px solid var(--fin-border-soft, #181F2A); padding-bottom:16px; flex-wrap:wrap;">
    <button type="button" class="<?= $initialSection === 'manage' ? 'active' : '' ?>" data-ads-section="manage" style="background:transparent; border:none; padding:12px 22px; font-weight:700; font-size:1.02rem; color:inherit; cursor:pointer; border-radius:12px; display:flex; align-items:center; gap:8px; transition:all 0.2s ease;"><span class="material-icons" style="font-size:20px; color:#F0B90B;">dashboard_customize</span> کمپین‌های فعال (<?= count($activeAds) ?>)</button>
    <button type="button" class="<?= $initialSection === 'create' ? 'active' : '' ?>" data-ads-section="create" style="background:transparent; border:none; padding:12px 22px; font-weight:700; font-size:1.02rem; color:inherit; cursor:pointer; border-radius:12px; display:flex; align-items:center; gap:8px; transition:all 0.2s ease;"><span class="material-icons" style="font-size:20px; color:#10b981;">add_circle</span> ثبت تبلیغ جدید</button>
    <button type="button" class="<?= $initialSection === 'performance' ? 'active' : '' ?>" data-ads-section="performance" style="background:transparent; border:none; padding:12px 22px; font-weight:700; font-size:1.02rem; color:inherit; cursor:pointer; border-radius:12px; display:flex; align-items:center; gap:8px; transition:all 0.2s ease;"><span class="material-icons" style="font-size:20px; color:#3b82f6;">insights</span> آمار و عملکرد کلی</button>
    <button type="button" class="<?= $initialSection === 'history' ? 'active' : '' ?>" data-ads-section="history" style="background:transparent; border:none; padding:12px 22px; font-weight:700; font-size:1.02rem; color:inherit; cursor:pointer; border-radius:12px; display:flex; align-items:center; gap:8px; transition:all 0.2s ease;"><span class="material-icons" style="font-size:20px; color:#94a3b8;">history</span> تاریخچه و آرشیو (<?= count($historyAds) ?>)</button>
  </nav>

  <!-- پنل ۱: مدیریت تبلیغات فعال -->
  <section class="ads-hub-panel <?= $initialSection === 'manage' ? 'active' : '' ?>" data-ads-panel="manage">
    <section class="ads-stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px; margin-bottom:28px;">
      <article class="ads-stat-card stat-total" style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); padding:20px; border-radius:16px; display:flex; align-items:center; gap:16px;"><span class="stat-icon" style="width:50px; height:50px; border-radius:14px; background:rgba(59,130,246,0.15); color:#3b82f6; display:flex; align-items:center; justify-content:center;"><span class="material-icons">apps</span></span><div><small style="opacity:0.7; display:block;">کل کمپین‌های شما</small><strong style="font-size:1.5rem; font-weight:800;"><?= $intFmt($total) ?></strong></div></article>
      <article class="ads-stat-card stat-active" style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); padding:20px; border-radius:16px; display:flex; align-items:center; gap:16px;"><span class="stat-icon" style="width:50px; height:50px; border-radius:14px; background:rgba(16,185,129,0.15); color:#10b981; display:flex; align-items:center; justify-content:center;"><span class="material-icons">bolt</span></span><div><small style="opacity:0.7; display:block;">کمپین‌های فعال و در جریان</small><strong style="font-size:1.5rem; font-weight:800; color:#10b981;"><?= $intFmt($active) ?></strong></div></article>
      <article class="ads-stat-card stat-view" style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); padding:20px; border-radius:16px; display:flex; align-items:center; gap:16px;"><span class="stat-icon" style="width:50px; height:50px; border-radius:14px; background:rgba(246,70,93,0.15); color:#f6465d; display:flex; align-items:center; justify-content:center;"><span class="material-icons">account_balance_wallet</span></span><div><small style="opacity:0.7; display:block;">بودجه مصرف‌شده</small><strong style="font-size:1.35rem; font-weight:800; color:#f6465d;"><?= $money($spent) ?></strong> <small style="font-size:0.75rem;">تومان</small></div></article>
      <article class="ads-stat-card stat-click" style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); padding:20px; border-radius:16px; display:flex; align-items:center; gap:16px;"><span class="stat-icon" style="width:50px; height:50px; border-radius:14px; background:rgba(240,185,11,0.15); color:#F0B90B; display:flex; align-items:center; justify-content:center;"><span class="material-icons">account_balance</span></span><div><small style="opacity:0.7; display:block;">بودجه باقی‌مانده</small><strong style="font-size:1.35rem; font-weight:800; color:#0ecb81;"><?= $money($remaining) ?></strong> <small style="font-size:0.75rem;">تومان</small></div></article>
    </section>

    <!-- لیست کمپین‌های فعال کاربر -->
    <?php if (empty($activeAds)): ?>
      <div class="ads-empty-state" style="text-align:center; padding:50px 20px; background:var(--fin-surface, #11161F); border:1px dashed var(--fin-border-soft, #181F2A); border-radius:18px; margin-top:24px;">
        <span class="material-icons" style="font-size:58px; opacity:0.5; margin-bottom:12px; color:#F0B90B;">campaign</span>
        <h3 style="font-size:1.2rem; font-weight:800; margin:0 0 6px 0;">هیچ کمپین فعالی ندارید</h3>
        <p style="opacity:0.7; font-size:0.95rem; margin:0 0 22px 0;">برای جذب مشتری، افزایش بازدید یا تبلیغ محصول خود، اولین کمپین را بسازید.</p>
        <button type="button" class="ads-btn ads-btn-primary" data-ads-section="create" style="background:#F0B90B; color:#0b0e11; font-weight:800; border:none; padding:12px 26px; border-radius:12px; cursor:pointer;">
          <span class="material-icons">add_circle</span> ثبت تبلیغ جدید
        </button>
      </div>
    <?php else: ?>
      <div class="campaign-list" style="display:flex; flex-direction:column; gap:18px; margin-top:24px;">
        <?php foreach ($activeAds as $ad): ?>
          <?php
            $tMeta = $typeMeta((string)($ad->type ?? ''));
            $sMeta = $statusMeta((string)($ad->status ?? 'pending'));
            $adTitle = (string)($ad->title ?? $ad->name ?? $ad->target_url ?? 'کمپین تبلیغاتی');
            $totBud = (float)($ad->total_budget ?? $ad->total_invested ?? 0);
            $spnBud = (float)($ad->spent_budget ?? 0);
            $remBud = (float)($ad->remaining_budget ?? ($totBud - $spnBud));
            $isPaused = (string)($ad->status ?? '') === 'paused' || (int)($ad->is_active ?? 1) === 0;
            $canToggle = in_array((string)($ad->status ?? ''), ['active', 'approved', 'paused'], true);
          ?>
          <div class="campaign-card <?= e($tMeta['class']) ?>">
            <div class="campaign-head">
              <div style="display:flex; align-items:center; gap:14px;">
                <div class="campaign-type-icon"><span class="material-icons"><?= e($tMeta['icon']) ?></span></div>
                <div class="campaign-title-block">
                  <h2 style="font-size:1.15rem; font-weight:800; margin:0 0 4px 0;"><?= e(mb_substr($adTitle, 0, 65)) ?></h2>
                  <span class="campaign-type-label"><span class="material-icons" style="font-size:14px; vertical-align:middle;">folder_open</span> <?= e($tMeta['label']) ?> • ثبت: <?= to_jalali($ad->created_at ?? '') ?></span>
                </div>
              </div>
              <div>
                <span class="badge" style="background:<?= e($sMeta['bg']) ?>; border:1px solid <?= e($sMeta['color']) ?>; color:<?= e($sMeta['color']) ?>; padding:6px 14px; border-radius:12px; font-weight:700; font-size:0.8rem;"><?= e($sMeta['label']) ?></span>
              </div>
            </div>

            <div class="campaign-metrics">
              <div>
                <small style="opacity:0.7; font-size:0.8rem; display:block;">بودجه کل اختصاص‌یافته</small>
                <strong style="font-size:1.15rem; color:#fff; font-family:monospace;"><?= $money($totBud) ?> <small style="font-size:0.75rem; font-family:inherit;">تومان</small></strong>
              </div>
              <div>
                <small style="opacity:0.7; font-size:0.8rem; display:block;">مصرف‌شده تا این لحظه</small>
                <strong style="font-size:1.15rem; color:#f6465d; font-family:monospace;"><?= $money($spnBud) ?> <small style="font-size:0.75rem; font-family:inherit;">تومان</small></strong>
              </div>
              <div>
                <small style="opacity:0.7; font-size:0.8rem; display:block;">بودجه باقی‌مانده</small>
                <strong style="font-size:1.15rem; color:#0ecb81; font-family:monospace;"><?= $money($remBud) ?> <small style="font-size:0.75rem; font-family:inherit;">تومان</small></strong>
              </div>
              <?php if (!empty($ad->total_count)): ?>
              <div>
                <small style="opacity:0.7; font-size:0.8rem; display:block;">پیشرفت اجرا</small>
                <strong style="font-size:1.15rem; color:#F0B90B;"><?= $intFmt($ad->completed_count ?? 0) ?> از <?= $intFmt($ad->total_count) ?></strong>
              </div>
              <?php endif; ?>
            </div>

            <div class="campaign-foot">
              <small style="opacity:0.7; font-size:0.8rem;">شناسه کمپین: #<?= (int)($ad->id ?? 0) ?></small>
              <div class="campaign-actions">
                <?php if ($canToggle): ?>
                  <button type="button" class="btn btn-sm" onclick="toggleAdStatus(<?= (int)$ad->id ?>, this)" style="border-radius:10px; font-weight:700; padding:8px 16px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; border:none; background:<?= $isPaused ? '#10b981' : '#f59e0b' ?>; color:#fff;">
                    <span class="material-icons" style="font-size:16px;"><?= $isPaused ? 'play_arrow' : 'pause' ?></span>
                    <?= $isPaused ? 'فعال‌سازی مجدد' : 'توقف موقت' ?>
                  </button>
                <?php endif; ?>
                <a href="<?= url('/ads/' . (int)($ad->id ?? 0)) ?>" class="btn btn-sm btn-outline-light" style="border-radius:10px; font-weight:600; padding:8px 16px; display:inline-flex; align-items:center; gap:6px; border:1px solid rgba(255,255,255,0.2); color:inherit; text-decoration:none;">
                  <span class="material-icons" style="font-size:16px;">visibility</span> آمار و جزئیات تکمیلی
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- پنل ۲: ثبت تبلیغ جدید -->
  <section class="ads-hub-panel <?= $initialSection === 'create' ? 'active' : '' ?>" data-ads-panel="create">
    <?php include view_path('user.ads._create-panel'); ?>
  </section>

  <!-- پنل ۳: عملکرد و آمار کلی -->
  <section class="ads-hub-panel <?= $initialSection === 'performance' ? 'active' : '' ?>" data-ads-panel="performance">
      <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:18px; padding:26px; margin-bottom:24px;">
          <h3 style="font-size:1.25rem; font-weight:800; margin-bottom:20px; display:flex; align-items:center; gap:8px;"><span class="material-icons" style="color:#3b82f6;">insights</span> خلاصه آمار عملکرد کمپین‌های شما</h3>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:18px;">
              <div style="background:var(--fin-surface-2, #161C27); padding:22px; border-radius:16px; text-align:center; border:1px solid var(--fin-border-soft, #181F2A);"><div style="font-size:1.8rem; font-weight:800; color:#3b82f6;"><?= $intFmt($totalViews) ?></div><div style="font-size:0.85rem; opacity:0.7; margin-top:6px;">مجموع نمایش و انجام‌های موفق</div></div>
              <div style="background:var(--fin-surface-2, #161C27); padding:22px; border-radius:16px; text-align:center; border:1px solid var(--fin-border-soft, #181F2A);"><div style="font-size:1.8rem; font-weight:800; color:#f6465d;"><?= $money($totalSpentReal) ?> <small style="font-size:0.8rem;">تومان</small></div><div style="font-size:0.85rem; opacity:0.7; margin-top:6px;">مجموع بودجه مصرف‌شده</div></div>
              <div style="background:var(--fin-surface-2, #161C27); padding:22px; border-radius:16px; text-align:center; border:1px solid var(--fin-border-soft, #181F2A);"><div style="font-size:1.8rem; font-weight:800; color:#F0B90B;"><?= $avgProgress ?>٪</div><div style="font-size:0.85rem; opacity:0.7; margin-top:6px;">میانگین پیشرفت تکمیل کمپین‌ها</div></div>
          </div>
      </div>

      <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:18px; padding:26px;">
          <h3 style="font-size:1.2rem; font-weight:800; margin-bottom:18px; display:flex; align-items:center; gap:8px;"><span class="material-icons" style="color:#10b981;">table_chart</span> تفکیک عملکرد به تفکیک کمپین‌ها</h3>
          <?php if (empty($ads)): ?>
              <p style="text-align:center; padding:30px; opacity:0.6; margin:0;">هیچ کمپین تبلیغاتی برای نمایش آمار وجود ندارد.</p>
          <?php else: ?>
              <div style="overflow-x:auto;">
                  <table class="ads-perf-table">
                      <thead>
                          <tr>
                              <th>#</th>
                              <th>عنوان کمپین</th>
                              <th>نوع</th>
                              <th>بودجه کل</th>
                              <th>مصرف‌شده</th>
                              <th>تعداد انجام‌شده</th>
                              <th>پیشرفت</th>
                              <th>وضعیت</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($ads as $idx => $ad): ?>
                              <?php
                                $tMeta = $typeMeta((string)($ad->type ?? ''));
                                $sMeta = $statusMeta((string)($ad->status ?? 'pending'));
                                $adTitle = (string)($ad->title ?? $ad->name ?? $ad->target_url ?? 'کمپین');
                                $totBud = (float)($ad->total_budget ?? $ad->total_invested ?? 0);
                                $spnBud = (float)($ad->spent_budget ?? 0);
                                $compCnt = (int)($ad->completed_count ?? 0);
                                $totCnt  = (int)($ad->total_count ?? 0);
                                $prog    = $totCnt > 0 ? round(($compCnt / $totCnt) * 100) : 0;
                              ?>
                              <tr>
                                  <td><?= $idx + 1 ?></td>
                                  <td style="font-weight:700;"><a href="<?= url('/ads/' . (int)$ad->id) ?>" style="color:inherit; text-decoration:none;"><?= e(mb_substr($adTitle, 0, 40)) ?></a></td>
                                  <td><?= e($tMeta['label']) ?></td>
                                  <td style="font-family:monospace;"><?= $money($totBud) ?></td>
                                  <td style="font-family:monospace; color:#f6465d;"><?= $money($spnBud) ?></td>
                                  <td><?= $intFmt($compCnt) ?><?= $totCnt > 0 ? (" / " . $intFmt($totCnt)) : '' ?></td>
                                  <td><span style="color:#F0B90B; font-weight:800;"><?= $prog ?>٪</span></td>
                                  <td><span style="background:<?= e($sMeta['bg']) ?>; color:<?= e($sMeta['color']) ?>; padding:4px 10px; border-radius:8px; font-size:0.75rem; font-weight:700;"><?= e($sMeta['label']) ?></span></td>
                              </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          <?php endif; ?>
      </div>
  </section>

  <!-- پنل ۴: تاریخچه و آرشیو کمپین‌ها -->
  <section class="ads-hub-panel <?= $initialSection === 'history' ? 'active' : '' ?>" data-ads-panel="history">
      <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:18px; padding:24px; margin-bottom:20px;">
          <h3 style="font-size:1.2rem; font-weight:800; margin:0 0 6px 0; display:flex; align-items:center; gap:8px;"><span class="material-icons" style="color:#94a3b8;">history</span> تاریخچه کمپین‌های پایان‌یافته و آرشیو</h3>
          <p style="opacity:0.7; font-size:0.9rem; margin:0;">کمپین‌های پایان‌یافته، لغوشده و آرشیوی شما در این صفحه نمایش داده می‌شوند.</p>
      </div>

      <?php if (empty($historyAds)): ?>
          <div class="ads-empty-state" style="text-align:center; padding:45px 20px; background:var(--fin-surface, #11161F); border:1px dashed var(--fin-border-soft, #181F2A); border-radius:18px;">
              <span class="material-icons" style="font-size:48px; opacity:0.4; margin-bottom:10px; color:#94a3b8;">history_toggle_off</span>
              <h3 style="font-size:1.1rem; font-weight:700; margin:0 0 6px 0;">هیچ کمپین پایان‌یافته‌ای در تاریخچه وجود ندارد</h3>
              <p style="opacity:0.6; font-size:0.9rem; margin:0;">تمامی کمپین‌های شما در حال حاضر فعال یا در انتظار هستند.</p>
          </div>
      <?php else: ?>
          <div class="campaign-list" style="display:flex; flex-direction:column; gap:18px;">
              <?php foreach ($historyAds as $ad): ?>
                  <?php
                    $tMeta = $typeMeta((string)($ad->type ?? ''));
                    $sMeta = $statusMeta((string)($ad->status ?? 'pending'));
                    $adTitle = (string)($ad->title ?? $ad->name ?? $ad->target_url ?? 'کمپین تبلیغاتی');
                    $totBud = (float)($ad->total_budget ?? $ad->total_invested ?? 0);
                    $spnBud = (float)($ad->spent_budget ?? 0);
                  ?>
                  <div class="campaign-card <?= e($tMeta['class']) ?>" style="opacity:0.85;">
                      <div class="campaign-head">
                          <div style="display:flex; align-items:center; gap:14px;">
                              <div class="campaign-type-icon" style="background:rgba(148,163,184,0.15); color:#94a3b8;"><span class="material-icons"><?= e($tMeta['icon']) ?></span></div>
                              <div class="campaign-title-block">
                                  <h2 style="font-size:1.1rem; font-weight:800; margin:0 0 4px 0;"><?= e(mb_substr($adTitle, 0, 65)) ?></h2>
                                  <span class="campaign-type-label"><?= e($tMeta['label']) ?> • ثبت: <?= to_jalali($ad->created_at ?? '') ?></span>
                              </div>
                          </div>
                          <div>
                              <span class="badge" style="background:<?= e($sMeta['bg']) ?>; border:1px solid <?= e($sMeta['color']) ?>; color:<?= e($sMeta['color']) ?>; padding:6px 14px; border-radius:12px; font-weight:700; font-size:0.8rem;"><?= e($sMeta['label']) ?></span>
                          </div>
                      </div>

                      <div class="campaign-metrics" style="opacity:0.9;">
                          <div>
                              <small style="opacity:0.7; font-size:0.8rem; display:block;">بودجه کل</small>
                              <strong style="font-size:1.05rem; color:#fff; font-family:monospace;"><?= $money($totBud) ?> <small style="font-size:0.75rem;">تومان</small></strong>
                          </div>
                          <div>
                              <small style="opacity:0.7; font-size:0.8rem; display:block;">مصرف‌شده نهایی</small>
                              <strong style="font-size:1.05rem; color:#f6465d; font-family:monospace;"><?= $money($spnBud) ?> <small style="font-size:0.75rem;">تومان</small></strong>
                          </div>
                          <?php if (!empty($ad->total_count)): ?>
                          <div>
                              <small style="opacity:0.7; font-size:0.8rem; display:block;">تعداد انجام</small>
                              <strong style="font-size:1.05rem; color:#F0B90B;"><?= $intFmt($ad->completed_count ?? 0) ?> از <?= $intFmt($ad->total_count) ?></strong>
                          </div>
                          <?php endif; ?>
                      </div>

                      <div class="campaign-foot">
                          <small style="opacity:0.7; font-size:0.8rem;">شناسه کمپین: #<?= (int)($ad->id ?? 0) ?></small>
                          <a href="<?= url('/ads/' . (int)($ad->id ?? 0)) ?>" class="btn btn-sm btn-outline-light" style="border-radius:10px; font-weight:600; padding:6px 14px; display:inline-flex; align-items:center; gap:6px; border:1px solid rgba(255,255,255,0.2); color:inherit; text-decoration:none;">
                              <span class="material-icons" style="font-size:16px;">visibility</span> آمار و گزارش نهایی
                          </a>
                      </div>
                  </div>
              <?php endforeach; ?>
          </div>
      <?php endif; ?>
  </section>
</div>

<script nonce="<?= e($cspNonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function() {
    const sidebarItems = document.querySelectorAll('.hub-nav-item');
    const panels = document.querySelectorAll('.ads-hub-panel');
    const subnavBtns = document.querySelectorAll('.ads-subnav button');
    const heroBtns = document.querySelectorAll('.ads-hero__actions [data-ads-section], .ads-empty-state [data-ads-section]');

    function showSection(section) {
        panels.forEach(p => p.classList.remove('active'));
        const target = document.querySelector(`.ads-hub-panel[data-ads-panel="${section}"]`);
        if (target) target.classList.add('active');

        sidebarItems.forEach(i => i.classList.remove('active'));
        const activeSidebar = document.querySelector(`.hub-nav-item[data-ads-section="${section}"]`);
        if (activeSidebar) activeSidebar.classList.add('active');

        subnavBtns.forEach(b => {
            b.classList.remove('active');
            if (b.dataset.adsSection === section) {
                b.classList.add('active');
                b.style.background = 'rgba(240,185,11,0.15)';
                b.style.color = '#F0B90B';
            } else {
                b.style.background = 'transparent';
                b.style.color = 'inherit';
            }
        });
    }

    subnavBtns.forEach(b => {
        if (b.classList.contains('active')) {
            b.style.background = 'rgba(240,185,11,0.15)';
            b.style.color = '#F0B90B';
        }
    });

    const allTriggers = [...sidebarItems, ...subnavBtns, ...heroBtns];
    allTriggers.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const section = this.dataset.adsSection;
            if (section) {
                if (section === 'create' && window.WIZARD_RESET_TO_STEP1) {
                    window.WIZARD_RESET_TO_STEP1();
                }
                showSection(section);
                try { history.pushState(null, '', '?section=' + section); } catch(err){}
            }
        });
    });

    window.toggleAdStatus = function(adId, btn) {
        if (!confirm('آیا از تغییر وضعیت این کمپین اطمینان دارید؟')) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        btn.disabled = true;
        fetch('<?= url('/ads/toggle-status') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ ad_id: adId, _csrf_token: csrf })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                alert(data.message || 'انجام شد');
                location.reload();
            } else {
                alert((data && data.message) ? data.message : 'خطا در تغییر وضعیت');
                btn.disabled = false;
            }
        })
        .catch(() => {
            alert('خطا در ارتباط با سرور');
            btn.disabled = false;
        });
    };

    // Initial load
    const urlSection = new URLSearchParams(window.location.search).get('section') || '<?= e($initialSection) ?>';
    showSection(urlSection);
});
</script>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/useradsindex.css') . '"><link rel="stylesheet" href="' . asset('assets/css/wizard.css') . '">';
$scripts = '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/useradscreate.js') . '"></script>';
include view_path('layouts.user');
?>
