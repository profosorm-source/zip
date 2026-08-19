<?php
$title = 'دستاوردهای من';
ob_start();
?>
<div class="milestones-page">
    <div class="page-header">
        <h1><span class="material-icons align-middle me-2 icon-md">emoji_events</span> دستاوردها (Milestones)</h1>
        <p>با رسیدن به هر دستاورد، جوایز ویژه دریافت کنید!</p>
    </div>

    <?php $achieved = is_array($achieved ?? null) ? $achieved : []; ?>
    <!-- Achieved Milestones -->
    <section class="achieved-section">
        <h2>دستاوردهای کسب شده (<?= count($achieved) ?>)</h2>
        
        <?php if (empty($achieved)): ?>
            <div class="empty-state">
                <p>هنوز دستاوردی کسب نکرده‌اید. شروع کنید!</p>
            </div>
        <?php else: ?>
            <div class="milestones-list">
                <?php foreach ($achieved as $milestone): ?>
                    <div class="milestone-card achieved">
                        <div class="milestone-icon">
                            <?php if ($milestone->badge_icon): ?>
                                <img src="<?= e($milestone->badge_icon) ?>" alt="<?= e($milestone->title_fa) ?>">
                            <?php else: ?>
                                <span class="default-icon">🏅</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="milestone-content">
                            <h3><?= e($milestone->title_fa) ?></h3>
                            <?php if ($milestone->description): ?>
                                <p class="description"><?= e($milestone->description) ?></p>
                            <?php endif; ?>
                            
                            <div class="milestone-reward">
                                <span class="reward-label">جایزه دریافتی:</span>
                                <span class="reward-value">
                                    <?php if ($milestone->reward_type === 'cash'): ?>
                                        <?= number_format($milestone->reward_value) ?>
                                        <?= $milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان' ?>
                                    <?php elseif ($milestone->reward_type === 'bonus_percent'): ?>
                                        +<?= $milestone->reward_value ?>% افزایش کمیسیون
                                    <?php else: ?>
                                        <?= $milestone->reward_type ?>
                                    <?php endif; ?>
                                </span>
                                
                                <?php if ($milestone->reward_paid): ?>
                                    <span class="reward-status paid">✓ پرداخت شده</span>
                                <?php else: ?>
                                    <span class="reward-status pending">در انتظار پرداخت</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="milestone-date">
                                <small>تاریخ دستیابی: <?= to_jalali($milestone->achieved_at) ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Available Milestones -->
    <section class="available-section">
        <h2>دستاوردهای در دسترس</h2>
        
        <?php if (empty($available)): ?>
            <div class="empty-state">
                <p>🎉 شما همه دستاوردها را کسب کرده‌اید!</p>
            </div>
        <?php else: ?>
            <!-- Group by type -->
            <?php 
            $grouped = [];
            foreach ($available as $milestone) {
                $type = $milestone->milestone_type;
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $milestone;
            }
            
            $typeLabels = [
                'referral_count' => 'بر اساس تعداد رفرال',
                'total_earned' => 'بر اساس کل درآمد',
                'active_referrals' => 'بر اساس رفرال‌های فعال'
            ];
            ?>
            
            <?php foreach ($grouped as $type => $milestones): ?>
                <div class="milestone-group">
                    <h3 class="group-title"><?= $typeLabels[$type] ?? $type ?></h3>
                    
                    <div class="milestones-list">
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="milestone-card available">
                                <div class="milestone-icon locked">
                                    <?php if ($milestone->badge_icon): ?>
                                        <img src="<?= e($milestone->badge_icon) ?>" alt="<?= e($milestone->title_fa) ?>">
                                    <?php else: ?>
                                        <span class="default-icon">🔒</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="milestone-content">
                                    <h3><?= e($milestone->title_fa) ?></h3>
                                    <?php if ($milestone->description): ?>
                                        <p class="description"><?= e($milestone->description) ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="milestone-requirement">
                                        <span class="req-label">هدف:</span>
                                        <span class="req-value">
                                            <?php if ($type === 'referral_count'): ?>
                                                <?= (int)$milestone->threshold_value ?> رفرال
                                            <?php elseif ($type === 'total_earned'): ?>
                                                <?= number_format($milestone->threshold_value) ?> تومان درآمد
                                            <?php elseif ($type === 'active_referrals'): ?>
                                                <?= (int)$milestone->threshold_value ?> رفرال فعال
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="milestone-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" 
                                                 data-width="<?= min(100, $milestone->progress_percent) ?>%">
                                            </div>
                                        </div>
                                        <div class="progress-text">
                                            <span>پیشرفت: <?= round($milestone->progress_percent, 1) ?>%</span>
                                            <span>فعلی: 
                                                <?php if ($type === 'total_earned'): ?>
                                                    <?= number_format($milestone->current_value) ?> تومان
                                                <?php else: ?>
                                                    <?= (int)$milestone->current_value ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="milestone-reward">
                                        <span class="reward-label">🎁 جایزه:</span>
                                        <span class="reward-value highlight">
                                            <?php if ($milestone->reward_type === 'cash'): ?>
                                                <?= number_format($milestone->reward_value) ?>
                                                <?= $milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان' ?>
                                            <?php elseif ($milestone->reward_type === 'bonus_percent'): ?>
                                                +<?= $milestone->reward_value ?>% افزایش کمیسیون
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userreferralmilestones.css') . '">';
include view_path('layouts.user');
?>