<?php
$title = 'بازار تسک‌های درآمدزا';
$hideSidebar = true;
$tasks = $tasks ?? [];
$totalTasks = (int)($totalTasks ?? 0);
$totalDone = (int)($totalDone ?? 0);
$totalPages = (int)($totalPages ?? 1);
$currentPage = (int)($currentPage ?? 1);
$filters = $filters ?? [];
$platforms = $platforms ?? [];
$userStats = $userStats ?? (object)[];
$totalEarned = (float)($userStats->total_earned ?? $userStats->earned ?? $userStats->available_earnings ?? 0);
$pending = (int)($userStats->pending_total ?? $userStats->pending ?? $userStats->pending_count ?? 0);

$normalizeTaskType = static function (?string $type): string {
    $type = strtolower(trim((string)$type));
    return match ($type) {
        'social_task' => 'social',
        'custom' => 'custom_task',
        default => $type !== '' ? $type : 'custom_task',
    };
};

$typeMeta = static function (string $type) use ($normalizeTaskType): array {
    $type = $normalizeTaskType($type);
    return match ($type) {
        'social' => ['label' => 'شبکه اجتماعی', 'class' => 'social'],
        'seo' => ['label' => 'سئو و کلیک', 'class' => 'seo'],
        'custom_task' => ['label' => 'تسک سفارشی', 'class' => 'custom'],
        default => ['label' => 'تسک عمومی', 'class' => 'custom'],
    };
};

$platformMeta = static function (object $task) use ($normalizeTaskType): array {
    $type = $normalizeTaskType((string)($task->type ?? ''));
    $platform = strtolower((string)($task->platform ?? ''));
    $title = mb_strtolower((string)($task->title ?? ''), 'UTF-8');

    if ($platform === 'instagram' || str_contains($title, 'اینستاگرام')) {
        return ['key' => 'instagram', 'label' => 'Instagram', 'symbol' => 'tm-logo-instagram'];
    }
    if ($platform === 'telegram' || str_contains($title, 'تلگرام')) {
        return ['key' => 'telegram', 'label' => 'Telegram', 'symbol' => 'tm-logo-telegram'];
    }
    if ($platform === 'google' || $type === 'seo' || str_contains($title, 'گوگل')) {
        return ['key' => 'google', 'label' => 'Google', 'symbol' => 'tm-logo-google'];
    }
    if (str_contains($title, 'بازی') || str_contains($title, 'game')) {
        return ['key' => 'game', 'label' => 'Game', 'symbol' => 'tm-logo-game'];
    }
    if (str_contains($title, 'ثبت') || str_contains($title, 'فرم') || str_contains($title, 'نام')) {
        return ['key' => 'signup', 'label' => 'Signup', 'symbol' => 'tm-logo-signup'];
    }
    if ($platform === 'website' || $platform === 'web' || $type === 'custom_task') {
        return ['key' => 'web', 'label' => 'Website', 'symbol' => 'tm-logo-web'];
    }

    return ['key' => 'web', 'label' => $platform ? ucfirst($platform) : 'Task', 'symbol' => 'tm-logo-web'];
};

$actionMetaFor = static function (object $task) use ($normalizeTaskType): array {
    $id = (int)($task->id ?? 0);
    $type = $normalizeTaskType((string)($task->type ?? 'custom_task'));

    return match ($type) {
        'social' => [
            'mode' => 'start',
            'start_url' => url('/social-tasks/start'),
            'direct_url' => '#',
            'execute_template' => url('/social-tasks/__EXECUTION_ID__/execute'),
        ],
        'seo' => [
            'mode' => 'start',
            'start_url' => url('/seo/start'),
            'direct_url' => '#',
            'execute_template' => url('/seo/__EXECUTION_ID__/execute'),
        ],
        'custom_task' => [
            'mode' => 'direct',
            'start_url' => '',
            'direct_url' => url('/custom-tasks/' . $id),
            'execute_template' => '',
        ],
        default => [
            'mode' => 'disabled',
            'start_url' => '',
            'direct_url' => '#',
            'execute_template' => '',
        ],
    };
};

$riskMeta = static function (object $task) use ($normalizeTaskType): array {
    $type = $normalizeTaskType((string)($task->type ?? 'custom_task'));
    $title = mb_strtolower((string)($task->title ?? ''), 'UTF-8');
    $desc = mb_strtolower((string)($task->description ?? ''), 'UTF-8');
    if ($type === 'custom_task' || str_contains($title . $desc, 'ثبت') || str_contains($title . $desc, 'فرم')) {
        return ['label' => 'بالا', 'class' => 'high', 'badge' => 'tm-badge-red'];
    }
    if ($type === 'seo') {
        return ['label' => 'متوسط', 'class' => 'medium', 'badge' => 'tm-badge-gold'];
    }
    return ['label' => 'کم', 'class' => 'low', 'badge' => 'tm-badge-green'];
};

$flowMetaFor = static function (object $task) use ($normalizeTaskType): array {
    $type = $normalizeTaskType((string)($task->type ?? 'custom_task'));
    $proofType = strtolower((string)($task->proof_type ?? 'text'));
    $proofType = match ($proofType) {
        'link' => 'url',
        'image' => 'screenshot',
        default => $proofType,
    };

    if ($type === 'social') {
        return [
            'badge' => 'بدون مدرک دستی',
            'badge_class' => 'tm-badge-green',
            'cta' => 'شروع اجرای امتیازی',
            'flow_title' => 'SocialTask امتیازی',
            'note' => 'در SocialTask کاربر مدرک دستی نمی‌فرستد؛ سیستم با الگوی رفتاری، زمان فعال، تعامل‌ها و در اپ موبایل در صورت مشکوک بودن با camera signal تصمیم می‌گیرد.',
            'steps' => [
                'تسک را شروع کنید و مقصد اجتماعی را طبق دستور باز کنید.',
                'تعامل را طبیعی انجام دهید؛ سرعت خیلی بالا یا الگوی یکنواخت امتیاز را کم می‌کند.',
                'سیستم رفتار را ثبت می‌کند؛ نیازی به proof_text، لینک یا اسکرین‌شات دستی نیست.',
                'اگر امتیاز کافی باشد پاداش تأیید می‌شود؛ اگر مشکوک باشد اپ موبایل verification کمکی می‌گیرد.',
            ],
        ];
    }

    if ($type === 'seo') {
        return [
            'badge' => 'Engagement scoring',
            'badge_class' => 'tm-badge-blue',
            'cta' => 'شروع اجرای SEO',
            'flow_title' => 'SEOTask امتیازی',
            'note' => 'در SEO، تأیید بر اساس زمان فعال، باز کردن سایت هدف، scroll depth، تعامل، مکث‌ها و ریسک تقلب محاسبه می‌شود و payout از بودجه/escrow کمپین آزاد می‌شود.',
            'steps' => [
                'تسک را شروع کنید و سایت هدف را با دکمه رسمی باز کنید.',
                'مدت هدف، اسکرول، تعامل و بازگشت به صفحه اجرا ثبت می‌شود.',
                'امتیاز SEO بر اساس target_duration، scroll، interaction و quality محاسبه می‌شود.',
                'score بالا باعث تکمیل و payout خودکار می‌شود؛ score پایین reject/fraud می‌شود.',
            ],
        ];
    }

    $proofLabels = [
        'text' => 'متن',
        'code' => 'کد',
        'url' => 'لینک',
        'screenshot' => 'اسکرین‌شات',
        'file' => 'فایل',
        'video' => 'ویدیو',
    ];
    $proofLabel = $proofLabels[$proofType] ?? 'مدرک';
    return [
        'badge' => 'Proof: ' . $proofLabel,
        'badge_class' => 'tm-badge-gold',
        'cta' => 'مشاهده قرارداد',
        'flow_title' => 'CustomTask قراردادی',
        'note' => 'در CustomTask کارفرما نوع مدرک را تعیین می‌کند؛ فرم ارسال مدرک بر اساس proof_schema اعتبارسنجی می‌شود و سپس کارفرما یا سیستم auto-approve/review تصمیم می‌گیرد.',
        'steps' => [
            'جزئیات قرارداد، دستورالعمل و نوع مدرک موردنیاز را بررسی کنید.',
            'تسک را شروع کنید تا submission با deadline و ظرفیت قفل‌شده ساخته شود.',
            'مدرک فقط مطابق proof_type ثبت می‌شود؛ کد/لینک/فایل تکراری رد می‌شود.',
            'کارفرما approve/reject می‌کند؛ در صورت تأخیر auto-approve و در صورت رد، dispute فعال است.',
        ],
    ];
};

ob_start();
?>

<div class="earn-wrap task-market-wrap">
    <svg width="0" height="0" style="position:absolute;display:none" aria-hidden="true">
        <symbol id="tm-i-search" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M11 19a8 8 0 1 1 5.3-14A8 8 0 0 1 11 19Zm10 2-4.35-4.35"/></symbol>
        <symbol id="tm-i-filter" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M3 5h18M6 12h12M10 19h4"/></symbol>
        <symbol id="tm-i-grid" viewBox="0 0 24 24"><path d="M3 3h8v8H3V3Zm10 0h8v8h-8V3ZM3 13h8v8H3v-8Zm10 0h8v8h-8v-8Z"/></symbol>
        <symbol id="tm-i-star" viewBox="0 0 24 24"><path d="M12 2.5 14.9 8.5l6.6.95-4.75 4.62 1.12 6.53L12 17.5 6.13 20.6l1.12-6.53L2.5 9.45l6.6-.95L12 2.5Z"/></symbol>
        <symbol id="tm-i-eye-off" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M3 3l18 18M10.6 10.6A2 2 0 0 0 13.4 13.4"/><path class="tm-svg-stroke" d="M9.9 4.24A10.5 10.5 0 0 1 12 4c7 0 10 8 10 8a17 17 0 0 1-3.2 4.6M6.1 6.1C3.4 8 2 12 2 12s3 8 10 8c1.6 0 3-.4 4.2-1"/></symbol>
        <symbol id="tm-i-bolt" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 10-13h-7l0-7Z"/></symbol>
        <symbol id="tm-i-rocket" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M5 15c-1 1-1.5 3.2-1.5 5.5 2.3 0 4.5-.5 5.5-1.5M9 15l-2-2c3-6 7-9 13-10-1 6-4 10-10 13l-2-2Z"/><path class="tm-svg-stroke" d="M14 7h.01"/></symbol>
        <symbol id="tm-logo-instagram" viewBox="0 0 32 32"><path fill="currentColor" d="M16 10.2A5.8 5.8 0 1 0 16 21.8 5.8 5.8 0 0 0 16 10.2Zm0 9.5A3.7 3.7 0 1 1 16 12.3 3.7 3.7 0 0 1 16 19.7ZM22 9.7a1.35 1.35 0 1 1-2.7 0 1.35 1.35 0 0 1 2.7 0Z"/><path fill="currentColor" d="M21.7 4H10.3A6.3 6.3 0 0 0 4 10.3v11.4A6.3 6.3 0 0 0 10.3 28h11.4A6.3 6.3 0 0 0 28 21.7V10.3A6.3 6.3 0 0 0 21.7 4Zm4.1 17.7a4.1 4.1 0 0 1-4.1 4.1H10.3a4.1 4.1 0 0 1-4.1-4.1V10.3a4.1 4.1 0 0 1 4.1-4.1h11.4a4.1 4.1 0 0 1 4.1 4.1v11.4Z"/></symbol>
        <symbol id="tm-logo-telegram" viewBox="0 0 32 32"><path fill="currentColor" d="M27.5 6.1 23.4 26c-.3 1.4-1.1 1.7-2.2 1.1l-6.1-4.5-3 2.9c-.3.3-.6.6-1.3.6l.5-6.3L22.6 10c.5-.4-.1-.7-.7-.3L8.7 18 2.9 16.2c-1.3-.4-1.3-1.3.3-1.9L25.8 5.6c1.1-.4 2 .2 1.7.5Z"/></symbol>
        <symbol id="tm-logo-google" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="M6.3 14.7 12.9 19.5C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 38.4 44 33.2 44 24c0-1.3-.1-2.6-.4-3.9z"/></symbol>
        <symbol id="tm-logo-signup" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M9 11l2 2 4-5"/><path class="tm-svg-stroke" d="M5 4h14v16H5z"/></symbol>
        <symbol id="tm-logo-web" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path class="tm-svg-stroke" d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></symbol>
        <symbol id="tm-logo-game" viewBox="0 0 24 24"><path class="tm-svg-stroke" d="M7 14h.01M10 11h.01M16 13h.01M18 10h.01"/><path class="tm-svg-stroke" d="M6 8h12a4 4 0 0 1 4 4v3a3 3 0 0 1-5.1 2.1L15 15H9l-1.9 2.1A3 3 0 0 1 2 15v-3a4 4 0 0 1 4-4Z"/></symbol>
    </svg>

    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><svg class="tm-svg" style="width:32px;height:32px"><use href="#tm-i-grid"/></svg></div>
            <div>
                <div class="earn-hero__eyebrow">Unified Task Marketplace</div>
                <h1 class="earn-hero__title">بازار تسک‌های درآمدزا</h1>
                <p class="earn-hero__sub">سه جریان تسک‌های سفارشی، سئو و شبکه‌های اجتماعی در یک صفحه با فیلتر حرفه‌ای، کارت‌های جمع‌وجور، علاقه‌مندی، مخفی‌سازی و جزئیات سریع.</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/dashboard') ?>" class="earn-btn earn-btn-panel"><svg class="tm-svg"><use href="#tm-i-grid"/></svg> بازگشت به پنل کاربری</a>
            <a href="<?= url('/wallet') ?>" class="earn-btn earn-btn-ghost">کیف پول</a>
        </div>
    </section>

    <div class="earn-hub-layout task-market-layout">
        <?php $activeSpoke = 'feed'; include view_path('user.tasks._earn-nav'); ?>

        <main class="earn-hub-main">
            <section class="tm-spoke-grid">
                <a href="<?= url('/tasks?type=custom_task') ?>" class="tm-spoke-card"><span class="tm-brand-logo signup"><svg><use href="#tm-logo-signup"/></svg></span><span><strong>تسک سفارشی</strong><small>ماموریت‌های اختصاصی کارفرماها</small></span></a>
                <a href="<?= url('/tasks?type=social') ?>" class="tm-spoke-card"><span class="tm-brand-logo instagram"><svg><use href="#tm-logo-instagram"/></svg></span><span><strong>شبکه‌های اجتماعی</strong><small>لایک، فالو، کامنت و تعامل</small></span></a>
                <a href="<?= url('/tasks?type=seo') ?>" class="tm-spoke-card"><span class="tm-brand-logo google"><svg><use href="#tm-logo-google"/></svg></span><span><strong>سئو و کلیک</strong><small>جستجو و بازدید هدفمند</small></span></a>
                <a href="<?= url('/tasks?sort=highest_price') ?>" class="tm-spoke-card"><span class="tm-brand-logo web"><svg class="tm-svg"><use href="#tm-i-bolt"/></svg></span><span><strong>پاداش بالا</strong><small>مرتب‌سازی بر اساس درآمد</small></span></a>
            </section>

            <section class="earn-stats">
                <div class="earn-stat earn-stat--gold"><div class="earn-stat__icon"><i class="material-icons">dynamic_feed</i></div><div><span class="earn-stat__lbl">تسک‌های قابل انجام</span><span class="earn-stat__val earn-num"><?= number_format($totalTasks) ?></span><span class="earn-stat__unit">در فید فعلی</span></div></div>
                <div class="earn-stat earn-stat--green"><div class="earn-stat__icon"><i class="material-icons">task_alt</i></div><div><span class="earn-stat__lbl">تسک‌های موفق شما</span><span class="earn-stat__val earn-num"><?= number_format($totalDone) ?></span><span class="earn-stat__unit">تأیید شده</span></div></div>
                <div class="earn-stat earn-stat--blue"><div class="earn-stat__icon"><i class="material-icons">payments</i></div><div><span class="earn-stat__lbl">درآمد ثبت‌شده</span><span class="earn-stat__val earn-num"><?= number_format($totalEarned) ?></span><span class="earn-stat__unit">تومان</span></div></div>
                <div class="earn-stat earn-stat--red"><div class="earn-stat__icon"><i class="material-icons">hourglass_empty</i></div><div><span class="earn-stat__lbl">در انتظار بررسی</span><span class="earn-stat__val earn-num"><?= number_format($pending) ?></span><span class="earn-stat__unit">اجرا/ارسال</span></div></div>
            </section>

            <section class="tm-toolbar">
                <div class="tm-tabs">
                    <a class="tm-tab <?= empty($filters['sort']) || ($filters['sort'] ?? '') === 'newest' ? 'on' : '' ?>" href="<?= url('/tasks') ?>">🔥 پیشنهادی</a>
                    <a class="tm-tab <?= ($filters['sort'] ?? '') === 'highest_price' ? 'on' : '' ?>" href="<?= url('/tasks?sort=highest_price') ?>">💰 پاداش بالا</a>
                    <a class="tm-tab" href="<?= url('/tasks?max_price=25000') ?>">⏱ سریع</a>
                    <a class="tm-tab" href="<?= url('/tasks?min_price=10000') ?>">🛡 امن</a>
                </div>
                <select class="tm-sort" data-action="change-sort">
                    <option value="newest" <?= ($filters['sort'] ?? 'newest') === 'newest' ? 'selected' : '' ?>>جدیدترین</option>
                    <option value="highest_price" <?= ($filters['sort'] ?? '') === 'highest_price' ? 'selected' : '' ?>>بیشترین پاداش</option>
                    <option value="lowest_price" <?= ($filters['sort'] ?? '') === 'lowest_price' ? 'selected' : '' ?>>کمترین پاداش</option>
                    <option value="oldest" <?= ($filters['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>قدیمی‌ترین</option>
                </select>
            </section>

            <section class="tm-filter-card">
                <form action="<?= url('/tasks') ?>" method="GET" class="tm-filter-inline" id="filterForm">
                    <div class="tm-search"><svg class="tm-svg"><use href="#tm-i-search"/></svg><input type="text" name="q" placeholder="عنوان، کارفرما، کلمه کلیدی..." value="<?= e($filters['q'] ?? '') ?>"></div>
                    <select name="type" class="tm-select"><option value="">همه نوع‌ها</option><option value="social" <?= ($filters['type']??'')==='social'?'selected':'' ?>>سوشال</option><option value="seo" <?= ($filters['type']??'')==='seo'?'selected':'' ?>>سئو</option><option value="custom_task" <?= ($filters['type']??'')==='custom_task'?'selected':'' ?>>سفارشی</option></select>
                    <select name="platform" class="tm-select"><option value="">همه پلتفرم‌ها</option><?php foreach($platforms as $p): if(empty($p->platform)) continue; ?><option value="<?= e($p->platform) ?>" <?= ($filters['platform']??'')===$p->platform?'selected':'' ?>><?= e(ucfirst((string)$p->platform)) ?></option><?php endforeach; ?></select>
                    <input type="number" name="min_price" class="tm-small-input" placeholder="حداقل پاداش" value="<?= e($filters['min_price'] ?? '') ?>">
                    <input type="number" name="max_price" class="tm-small-input" placeholder="حداکثر پاداش" value="<?= e($filters['max_price'] ?? '') ?>">
                    <button type="submit" class="earn-btn earn-btn-primary"><svg class="tm-svg"><use href="#tm-i-filter"/></svg> اعمال فیلتر</button>
                    <?php if(!empty(array_filter($filters))): ?><a href="<?= url('/tasks') ?>" class="earn-btn earn-btn-secondary">حذف</a><?php endif; ?>
                </form>
            </section>

            <section class="tm-board">
                <div class="tm-list">
                    <?php if(empty($tasks)): ?>
                        <div class="earn-empty"><i class="material-icons">sentiment_neutral</i><h3>تسکی یافت نشد</h3><p>تسک جدیدی مطابق فیلترهای شما موجود نیست.</p><a href="<?= url('/tasks') ?>" class="earn-btn earn-btn-primary">پاک کردن فیلترها</a></div>
                    <?php else: ?>
                        <?php foreach($tasks as $idx => $task):
                            $type = $normalizeTaskType((string)($task->type ?? 'custom_task'));
                            $tm = $typeMeta($type);
                            $pm = $platformMeta($task);
                            $risk = $riskMeta($task);
                            $action = $actionMetaFor($task);
                            $flow = $flowMetaFor($task);
                            if ($type === 'seo') {
                                $reward = (float)($task->max_payout ?? $task->min_payout ?? $task->price_per_click ?? $task->price_per_task ?? 0);
                                $minPayoutForCapacity = max(1.0, (float)($task->min_payout ?? $task->price_per_click ?? $task->price_per_task ?? 1));
                                $capacity = (int)floor((float)($task->remaining_budget ?? $task->budget ?? $task->total_budget ?? 0) / $minPayoutForCapacity);
                            } else {
                                $reward = (float)($task->price_per_task ?? $task->reward_per_user ?? $task->reward_amount ?? 0);
                                $capacity = (int)($task->remaining_count ?? $task->remaining_slots ?? $task->slots_remaining ?? max(0, (int)($task->total_count ?? 0) - (int)($task->completed_count ?? 0) - (int)($task->pending_count ?? 0)));
                            }
                            $duration = (int)($task->estimated_minutes ?? $task->duration_minutes ?? ($type === 'custom_task' ? 7 : ($type === 'seo' ? 2 : 3)));
                            $trust = max(70, min(99, 92 + (($idx % 4) * 2)));
                            $desc = mb_substr(strip_tags((string)($task->description ?? '')), 0, 150);
                            $titleText = (string)($task->title ?? 'تسک درآمدزا');
                        ?>
                            <article class="tm-task <?= $idx === 0 ? 'selected' : '' ?>"
                                     data-task-card
                                     data-task-id="<?= e((string)($task->id ?? 0)) ?>"
                                     data-title="<?= e($titleText) ?>"
                                     data-description="<?= e($desc) ?>"
                                     data-task-type="<?= e($type) ?>"
                                     data-type-label="<?= e($tm['label']) ?>"
                                     data-type-class="<?= e($tm['class']) ?>"
                                     data-platform-label="<?= e($pm['label']) ?>"
                                     data-platform-key="<?= e($pm['key']) ?>"
                                     data-platform-symbol="<?= e($pm['symbol']) ?>"
                                     data-reward="<?= e(number_format($reward)) ?> تومان"
                                     data-start-mode="<?= e($action['mode']) ?>"
                                     data-start-url="<?= e($action['start_url']) ?>"
                                     data-direct-url="<?= e($action['direct_url']) ?>"
                                     data-execute-template="<?= e($action['execute_template']) ?>"
                                     data-risk-label="<?= e($risk['label']) ?>"
                                     data-flow-title="<?= e($flow['flow_title']) ?>"
                                     data-flow-badge="<?= e($flow['badge']) ?>"
                                     data-flow-badge-class="<?= e($flow['badge_class']) ?>"
                                     data-flow-note="<?= e($flow['note']) ?>"
                                     data-start-label="<?= e($flow['cta']) ?>"
                                     data-step-1="<?= e($flow['steps'][0]) ?>"
                                     data-step-2="<?= e($flow['steps'][1]) ?>"
                                     data-step-3="<?= e($flow['steps'][2]) ?>"
                                     data-step-4="<?= e($flow['steps'][3]) ?>">
                                <div class="tm-task-top">
                                    <div class="tm-brand-logo <?= e($pm['key']) ?>"><svg><use href="#<?= e($pm['symbol']) ?>"/></svg></div>
                                    <div>
                                        <span class="tm-task-type <?= e($tm['class']) ?>"><?= e($tm['label']) ?></span>
                                        <h3><?= e($titleText) ?></h3>
                                    </div>
                                    <div class="tm-card-actions">
                                        <button type="button" class="tm-ico tm-star" data-action="toggle-favorite" title="افزودن به علاقه‌مندی"><svg class="tm-svg"><use href="#tm-i-star"/></svg></button>
                                        <button type="button" class="tm-ico tm-hide" data-action="hide-task" title="عدم نمایش برای من"><svg class="tm-svg"><use href="#tm-i-eye-off"/></svg></button>
                                    </div>
                                </div>
                                <p class="tm-desc"><?= e($desc) ?></p>
                                <div class="tm-meta">
                                    <div class="tm-meta-box"><span>زمان</span><strong><?= e((string)$duration) ?> دقیقه</strong></div>
                                    <div class="tm-meta-box"><span>ظرفیت</span><strong class="earn-num"><?= number_format($capacity) ?></strong></div>
                                    <div class="tm-meta-box"><span>ریسک</span><strong><?= e($risk['label']) ?></strong><div class="tm-risk"><div class="tm-risk-fill <?= e($risk['class']) ?>"></div></div></div>
                                </div>
                                <div class="tm-badges">
                                    <span class="tm-badge tm-badge-green">اعتماد <?= e((string)$trust) ?>٪</span>
                                    <span class="tm-badge <?= e($risk['badge']) ?>">ریسک <?= e($risk['label']) ?></span>
                                    <span class="tm-badge tm-badge-blue"><?= e($pm['label']) ?></span>
                                    <span class="tm-badge <?= e($flow['badge_class']) ?>"><?= e($flow['badge']) ?></span>
                                </div>
                                <div class="tm-task-foot">
                                    <div class="tm-reward earn-num"><?= number_format($reward) ?> <small>تومان</small></div>
                                    <div class="tm-task-ctas"><button type="button" class="tm-mini" data-action="show-details">جزئیات</button><button type="button" class="tm-mini primary" data-action="start-task"><?= e($flow['cta']) ?></button></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <aside class="tm-detail-panel" id="taskDetailPanel">
                    <div class="tm-detail-head"><span class="tm-badge tm-badge-green">Active</span><strong>جزئیات تسک منتخب</strong></div>
                    <div class="tm-detail-body">
                        <div class="tm-detail-preview"><div class="tm-brand-logo instagram" id="detailLogo"><svg><use href="#tm-logo-instagram"/></svg></div></div>
                        <h2 id="detailTitle">یک تسک را انتخاب کنید</h2>
                        <p id="detailDesc">برای مشاهده جزئیات، روی کارت تسک یا دکمه جزئیات کلیک کنید.</p>
                        <div class="tm-badges"><span class="tm-badge tm-badge-green" id="detailType">نوع تسک</span><span class="tm-badge tm-badge-gold" id="detailReward">پاداش</span><span class="tm-badge tm-badge-blue" id="detailPlatform">پلتفرم</span></div>
                        <div class="tm-steps">
                            <div class="tm-step"><div>۱</div><p id="detailStep1">دستورالعمل تسک را کامل مطالعه کنید.</p></div>
                            <div class="tm-step"><div>۲</div><p id="detailStep2">روی شروع بزنید و عملیات را در مسیر تعیین‌شده انجام دهید.</p></div>
                            <div class="tm-step"><div>۳</div><p id="detailStep3">مدرک یا رفتار موردنیاز را ثبت کنید.</p></div>
                            <div class="tm-step"><div>۴</div><p id="detailStep4">ارسال کنید و منتظر تأیید بمانید.</p></div>
                        </div>
                        <div class="tm-warning" id="detailFlowNote"><strong>نکته ضدتقلب:</strong> رفتار غیرطبیعی، حذف تعامل قبل از تأیید یا مدرک تکراری باعث رد شدن اجرا می‌شود.</div>
                        <div class="tm-detail-actions"><button type="button" class="earn-btn earn-btn-secondary" id="detailFavorite"><svg class="tm-svg"><use href="#tm-i-star"/></svg> فاوریت</button><button type="button" class="earn-btn earn-btn-primary" id="detailStart" data-action="detail-start-task"><svg class="tm-svg"><use href="#tm-i-rocket"/></svg> شروع</button></div>
                    </div>
                </aside>
            </section>

            <?php if ($totalPages > 1): ?>
                <div class="earn-actions" style="justify-content:center;">
                    <?php for ($i=1; $i <= $totalPages; $i++): $qParams = $_GET; $qParams['page']=$i; ?>
                        <a class="earn-btn <?= $i === $currentPage ? 'earn-btn-primary' : 'earn-btn-secondary' ?>" href="?<?= http_build_query($qParams) ?>"><?= e((string)$i) ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usertasksfeed.js') . '"></script>';
$content = ob_get_clean();
include view_path('layouts.user');
?>
