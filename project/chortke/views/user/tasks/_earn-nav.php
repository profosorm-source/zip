<?php
$activeSpoke = $activeSpoke ?? 'feed';
$totalTasks = (int)($totalTasks ?? 0);
$totalDone = (int)($totalDone ?? 0);

$spokes = [
    ['key'=>'feed','href'=>url('/tasks'),'icon'=>'monetization_on','label'=>'بازار تسک‌ها','desc'=>'همه تسک‌های قابل انجام'],
    ['key'=>'custom','href'=>url('/tasks?type=custom_task'),'icon'=>'assignment','label'=>'تسک‌های سفارشی','desc'=>'ماموریت‌های اختصاصی'],
    ['key'=>'social','href'=>url('/tasks?type=social'),'icon'=>'groups','label'=>'شبکه‌های اجتماعی','desc'=>'لایک، فالو، کامنت و تعامل'],
    ['key'=>'seo','href'=>url('/tasks?type=seo'),'icon'=>'search','label'=>'سئو و کلیک','desc'=>'جستجو و بازدید هدفمند'],
    ['key'=>'adtube','href'=>url('/adtube'),'icon'=>'play_circle_filled','label'=>'تماشای ویدیو (AdTube)','desc'=>'کسب درآمد از تماشای ویدیو'],
];
?>
<aside class="earn-hub-sidebar" aria-label="منوی داخلی بازار تسک‌ها">
    <div class="earn-side-card earn-module-card">
        <div class="earn-module-card__top">
            <div class="earn-module-card__icon"><i class="material-icons">hub</i></div>
            <div>
                <div class="earn-module-card__eyebrow">Task Marketplace</div>
                <h2 class="earn-module-card__title">بازار تسک‌ها</h2>
                <p class="earn-module-card__desc">تسک‌های سفارشی، سئو و شبکه‌های اجتماعی در یک بازار واحد.</p>
            </div>
        </div>
        <nav class="earn-module-nav">
            <?php foreach ($spokes as $spoke): ?>
                <a class="earn-module-nav__item <?= $activeSpoke === $spoke['key'] ? 'active' : '' ?>" href="<?= e($spoke['href']) ?>">
                    <span class="earn-module-nav__icon"><i class="material-icons"><?= e($spoke['icon']) ?></i></span>
                    <span class="earn-module-nav__body"><strong><?= e($spoke['label']) ?></strong><small><?= e($spoke['desc']) ?></small></span>
                    <i class="material-icons earn-module-nav__chev">chevron_left</i>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="earn-side-card earn-mini">
        <div class="earn-mini__head"><i class="material-icons">workspace_premium</i><span>خلاصه عملکرد</span></div>
        <div class="earn-mini-row"><span>تسک‌های موجود</span><strong class="earn-num"><?= number_format($totalTasks) ?></strong></div>
        <div class="earn-mini-row"><span>تسک‌های موفق</span><strong class="earn-num"><?= number_format($totalDone) ?></strong></div>
    </div>
</aside>
