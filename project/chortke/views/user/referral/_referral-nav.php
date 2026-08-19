<?php
$activeSpoke = $activeSpoke ?? 'overview';
$stats = $stats ?? null;
$referredCount = (int)($referredCount ?? 0);
$totalEarned = (float)($stats->total_earned_irt ?? 0);

$spokes = [
    ['key'=>'overview', 'href'=>url('/referral'), 'icon'=>'people', 'label'=>'داشبورد معرفی', 'desc'=>'لینک دعوت، آمار و کمیسیون‌ها'],
    ['key'=>'commissions', 'href'=>url('/referral#commissions-section'), 'icon'=>'percent', 'label'=>'کمیسیون‌های من', 'desc'=>'لیست درآمدها و پاداش‌ها'],
    ['key'=>'users', 'href'=>url('/referral#referred-users-section'), 'icon'=>'group_add', 'label'=>'زیرمجموعه‌های من', 'desc'=>'افراد معرفی‌شده و وضعیت'],
    ['key'=>'milestones', 'href'=>url('/referral/milestones'), 'icon'=>'emoji_events', 'label'=>'مراحل و پاداش‌ها', 'desc'=>'جوایز تصاعدی زیرمجموعه‌گیری'],
    ['key'=>'analytics', 'href'=>url('/referral/analytics'), 'icon'=>'analytics', 'label'=>'تحلیل و نمودارها', 'desc'=>'نرخ تبدیل و آمار تکمیلی'],
];
?>
<aside class="acc-hub-sidebar" aria-label="منوی داخلی معرفی دوستان">
    <div class="acc-side-card acc-module-card">
        <div class="acc-module-card__top">
            <div class="acc-module-card__icon"><i class="material-icons">share</i></div>
            <div>
                <div class="acc-module-card__eyebrow">Referral Hub</div>
                <h2 class="acc-module-card__title">دعوت از دوستان</h2>
                <p class="acc-module-card__desc">کسب کمیسیون مادام‌العمر از معاملات و فعالیت دوستان در یک هاب واحد.</p>
            </div>
        </div>
        <nav class="acc-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="acc-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="acc-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="acc-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
                    <i class="material-icons acc-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="acc-side-card acc-mini">
        <div class="acc-mini__head"><i class="material-icons">trending_up</i><span>خلاصه معرفی</span></div>
        <div class="acc-mini-row"><span>زیرمجموعه‌ها</span><strong class="acc-badge acc-badge--success"><?= number_format($referredCount) ?> نفر</strong></div>
        <div class="acc-mini-row"><span>درآمد کل</span><strong class="acc-badge acc-badge--success"><?= number_format($totalEarned) ?> تومان</strong></div>
    </div>
</aside>
