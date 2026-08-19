<?php
$title = 'داشبورد برنامه معرفی';
ob_start();
?>

<div class="referral-dashboard">

  <!-- Header -->
  <div class="dashboard-header">
    <h1>داشبورد برنامه معرفی</h1>
    <p>با معرفی دوستان خود درآمد کسب کنید!</p>
  </div>

  <!-- Referral Link -->
  <div class="referral-link-box">
    <label>لینک اختصاصی شما:</label>
    <div class="link-container">
      <input type="text" id="referralLink" value="<?= $referralLink ?>" readonly>
      <button data-action="copy-referral-link">کپی لینک</button>
    </div>
  </div>

  <!-- Current Tier -->
  <div class="tier-section">
    <h2>سطح فعلی شما</h2>
    <div class="tier-card">
      <div class="tier-badge tier-<?= strtolower($current_tier->slug ?? 'bronze') ?>">
        <?= e($current_tier->name_fa ?? 'برنز') ?>
      </div>
      <p>افزایش کمیسیون: <strong>+<?= $current_tier->commission_boost_percent ?? 0 ?>%</strong></p>

      <?php if ($next_tier_progress && $next_tier_progress['has_next']): ?>
        <div class="tier-progress">
          <h3>پیشرفت تا سطح بعدی: <?= e($next_tier_progress['next_tier']->name_fa) ?></h3>

          <div class="progress-item">
            <span>تعداد رفرال فعال:</span>
            <div class="progress-bar">
              <div class="progress-fill" data-width="<?= $next_tier_progress[progress][referrals][percent] ?>%"></div>
            </div>
            <span><?= $next_tier_progress['progress']['referrals']['current'] ?> / <?= $next_tier_progress['progress']['referrals']['required'] ?></span>
          </div>

          <div class="progress-item">
            <span>کل درآمد:</span>
            <div class="progress-bar">
              <div class="progress-fill" data-width="<?= $next_tier_progress[progress][earnings][percent] ?>%"></div>
            </div>
            <span><?= number_format($next_tier_progress['progress']['earnings']['current']) ?> / <?= number_format($next_tier_progress['progress']['earnings']['required']) ?> تومان</span>
          </div>
        </div>
      <?php else: ?>
        <p class="tier-max">🏆 شما در بالاترین سطح هستید!</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats Overview -->
  <div class="stats-grid">
    <div class="stat-card">
      <h3>کل درآمد (تومان)</h3>
      <p class="stat-value"><?= number_format($stats->total_earned_irt ?? 0) ?></p>
      <small>در انتظار: <?= number_format($stats->pending_irt ?? 0) ?></small>
    </div>

    <div class="stat-card">
      <h3>کل درآمد (USDT)</h3>
      <p class="stat-value"><?= number_format($stats->total_earned_usdt ?? 0, 2) ?></p>
      <small>در انتظار: <?= number_format($stats->pending_usdt ?? 0, 2) ?></small>
    </div>

    <div class="stat-card">
      <h3>تعداد کمیسیون‌ها</h3>
      <p class="stat-value"><?= $stats->total_count ?? 0 ?></p>
      <small>پرداخت شده: <?= $stats->paid_count ?? 0 ?></small>
    </div>

    <div class="stat-card">
      <h3>امتیاز کیفیت</h3>
      <p class="stat-value quality-score-<?= $quality_interpretation['level'] ?>">
        <?= round($quality_score, 1) ?>/100
      </p>
      <small><?= $quality_interpretation['label'] ?></small>
    </div>
  </div>

  <!-- Quality Score -->
  <div class="quality-section">
    <h2>کیفیت رفرال‌های شما</h2>
    <div class="quality-gauge">
      <div class="gauge-bar">
        <div class="gauge-fill" data-width="<?= $quality_score ?>%" data-color="<?= e($quality_interpretation['color'] ?? '') ?>"></div>
      </div>
      <p><?= e($quality_interpretation['description']) ?></p>
    </div>

    <?php if (!empty($improvement_suggestions)): ?>
      <div class="suggestions">
        <h3>پیشنهادات بهبود:</h3>
        <ul>
          <?php foreach ($improvement_suggestions as $suggestion): ?>
            <li class="suggestion-<?= $suggestion['type'] ?>">
              <strong><?= e($suggestion['message']) ?></strong>
              <?php if (isset($suggestion['action'])): ?>
                <br><small>💡 <?= e($suggestion['action']) ?></small>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <!-- Milestones -->
  <div class="milestones-section">
    <h2>دستاوردها (Milestones)</h2>

    <?php if ($next_milestone): ?>
      <div class="next-milestone">
        <h3>🎯 نزدیک‌ترین دستاورد:</h3>
        <div class="milestone-card">
          <h4><?= e($next_milestone->title_fa) ?></h4>
          <div class="milestone-progress">
            <div class="progress-bar">
              <div class="progress-fill" data-width="<?= min(100, $next_milestone->progress_percent) ?>%"></div>
            </div>
            <span><?= round($next_milestone->progress_percent, 1) ?>%</span>
          </div>
          <p>جایزه: <?= number_format($next_milestone->reward_value) ?> <?= $next_milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان' ?></p>
        </div>
      </div>
    <?php endif; ?>

    <?php $achieved_milestones = is_array($achieved_milestones ?? null) ? $achieved_milestones : []; ?>
    <div class="achieved-milestones">
      <h3>دستاوردهای کسب شده: <?= count($achieved_milestones) ?></h3>
      <div class="milestones-grid">
        <?php foreach (array_slice($achieved_milestones, 0, 6) as $milestone): ?>
          <div class="milestone-badge achieved">
            <span class="badge-icon">🏅</span>
            <span class="badge-title"><?= e($milestone->title_fa) ?></span>
            <small><?= to_jalali($milestone->achieved_at) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($achieved_milestones) > 6): ?>
        <a href="/user/referral/milestones" class="view-all-link">مشاهده همه دستاوردها</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Analytics Quick View -->
  <div class="analytics-section">
    <h2>آمار و تحلیل</h2>
    <div class="analytics-grid">
      <div class="analytics-card">
        <h4>نرخ تبدیل (30 روز)</h4>
        <p class="analytics-value"><?= $analytics['conversion']['overall_conversion_rate'] ?? 0 ?>%</p>
        <small><?= $analytics['conversion']['signups'] ?? 0 ?> ثبت‌نام از <?= $analytics['conversion']['clicks'] ?? 0 ?> کلیک</small>
      </div>

      <div class="analytics-card">
        <h4>میانگین ارزش هر رفرال</h4>
        <p class="analytics-value"><?= number_format($analytics['ltv']['ltv_irt'] ?? 0) ?> تومان</p>
        <small>از <?= $analytics['ltv']['total_referrals'] ?? 0 ?> رفرال</small>
      </div>

      <div class="analytics-card">
        <h4>نرخ ماندگاری</h4>
        <p class="analytics-value"><?= $analytics['retention']['retention_rate'] ?? 0 ?>%</p>
        <small><?= $analytics['retention']['still_active'] ?? 0 ?> از <?= $analytics['retention']['total_referrals'] ?? 0 ?> نفر فعال</small>
      </div>

      <div class="analytics-card">
        <h4>پیش‌بینی ماه آینده</h4>
        <p class="analytics-value"><?= number_format($analytics['prediction']['predicted_irt'] ?? 0) ?> تومان</p>
        <small>روند: <?= $analytics['prediction']['trend'] ?? 'نامشخص' ?></small>
      </div>
    </div>
    <a href="/user/referral/analytics" class="btn btn-secondary">مشاهده تحلیل کامل</a>
  </div>

  <!-- Multi-tier Earnings -->
  <?php if ($multi_tier_earnings): ?>
  <div class="multi-tier-section">
    <h2>درآمد چند سطحی</h2>
    <div class="tier-earnings-chart">
      <div class="tier-earning-item">
        <span class="tier-label"><?= $multi_tier_earnings['direct']['label'] ?></span>
        <span class="tier-amount"><?= number_format($multi_tier_earnings['direct']['earned_irt']) ?> تومان</span>
      </div>
      <?php foreach ($multi_tier_earnings['indirect'] as $indirect): ?>
        <div class="tier-earning-item">
          <span class="tier-label"><?= $indirect['label'] ?></span>
          <span class="tier-amount"><?= number_format($indirect['earned_irt']) ?> تومان</span>
        </div>
      <?php endforeach; ?>
    </div>
    <a href="/user/referral/network" class="btn btn-secondary">مشاهده شبکه کامل</a>
  </div>
  <?php endif; ?>

</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userreferraldashboard.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userreferraldashboard.js') . '"></script>';
include view_path('layouts.user');
?>
