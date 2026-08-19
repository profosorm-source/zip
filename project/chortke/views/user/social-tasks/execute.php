<?php
$title = 'انجام تسک اجتماعی';
$hideSidebar = true;
$execution = $execution ?? null;
$task = $task ?? $execution;
$executionId = (int)($execution->id ?? 0);
$adId = (int)($execution->ad_id ?? 0);
$taskTitle = $execution->ad_title ?? $task->title ?? 'تسک اجتماعی';
$taskType = $execution->task_type ?? $task->task_type ?? 'social';
$platform = strtolower((string)($execution->platform ?? $task->platform ?? 'social'));
$reward = (float)($execution->price_per_task ?? $task->price_per_task ?? $task->reward_amount ?? 0);
$status = (string)($execution->status ?? 'pending');
$expectedTime = (int)($execution->expected_time ?? 60);

ob_start();
?>

<div id="socialTaskExecuteRoot"
     class="earn-wrap task-market-wrap"
     data-submit-url="<?= e(url('/social-tasks/' . $executionId . '/submit')) ?>"
     data-behavior-url="<?= e(url('/api/social-tasks/behavior')) ?>"
     data-camera-url="<?= e(url('/api/social-tasks/camera-verify')) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-execution-id="<?= e((string)$executionId) ?>"
     data-task-type="<?= e((string)$taskType) ?>"
     data-client-mode="web"
     data-expected-time="<?= e((string)$expectedTime) ?>">

    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><i class="material-icons">groups</i></div>
            <div>
                <div class="earn-hero__eyebrow">Social Task Execution</div>
                <h1 class="earn-hero__title"><?= e($taskTitle) ?></h1>
                <p class="earn-hero__sub">در SocialTask مدرک متنی/لینک/اسکرین‌شات از کاربر گرفته نمی‌شود؛ سیستم با الگوی رفتاری، زمان فعال، تعامل‌ها و در موبایل در صورت مشکوک بودن با تأیید دوربین، امتیاز نهایی را محاسبه می‌کند.</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/tasks?type=social') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار تسک‌ها</a>
            <span class="tm-badge tm-badge-green"><?= e($status) ?></span>
        </div>
    </section>

    <div class="earn-hub-layout">
        <?php $activeSpoke = 'social'; include view_path('user.tasks._earn-nav'); ?>
        <main class="earn-hub-main">
            <section class="earn-stats">
                <div class="earn-stat earn-stat--gold"><div class="earn-stat__icon"><i class="material-icons">payments</i></div><div><span class="earn-stat__lbl">پاداش</span><span class="earn-stat__val earn-num"><?= number_format($reward) ?></span><span class="earn-stat__unit">تومان</span></div></div>
                <div class="earn-stat earn-stat--green"><div class="earn-stat__icon"><i class="material-icons">timer</i></div><div><span class="earn-stat__lbl">زمان پیشنهادی</span><span class="earn-stat__val earn-num" id="timerValue"><?= number_format($expectedTime) ?></span><span class="earn-stat__unit">ثانیه</span></div></div>
                <div class="earn-stat earn-stat--blue"><div class="earn-stat__icon"><i class="material-icons">category</i></div><div><span class="earn-stat__lbl">نوع تسک</span><span class="earn-stat__val"><?= e($taskType) ?></span><span class="earn-stat__unit"><?= e($platform) ?></span></div></div>
                <div class="earn-stat earn-stat--red"><div class="earn-stat__icon"><i class="material-icons">tag</i></div><div><span class="earn-stat__lbl">شناسه اجرا</span><span class="earn-stat__val earn-num">#<?= e((string)$executionId) ?></span><span class="earn-stat__unit">Ad #<?= e((string)$adId) ?></span></div></div>
            </section>

            <div class="tm-board" style="grid-template-columns:minmax(0,1fr) 330px;">
                <section class="earn-section">
                    <div class="earn-section__header"><div class="earn-section__title"><i class="material-icons">checklist</i> مراحل انجام تسک</div></div>
                    <div class="earn-section__body">
                        <div class="tm-steps">
                            <div class="tm-step"><div>۱</div><p>تسک را طبق دستورالعمل انجام دهید؛ سیستم زمان و تعامل‌های انسانی را ثبت می‌کند.</p></div>
                            <div class="tm-step"><div>۲</div><p>تعامل باید طبیعی باشد؛ اجرای خیلی سریع، الگوی یکنواخت یا قطع تعامل امتیاز را کم می‌کند.</p></div>
                            <div class="tm-step"><div>۳</div><p>اگر اجرای موبایل مشکوک باشد، برنامه موبایل با اجازه کاربر چند فریم دوربین را فقط محلی تحلیل می‌کند.</p></div>
                            <div class="tm-step"><div>۴</div><p>پس از پایان، سیستم بدون دریافت مدرک دستی، امتیاز نهایی را محاسبه و تصمیم می‌گیرد.</p></div>
                        </div>
                        <div class="tm-warning"><strong>ضدتقلب امتیازی:</strong> هیچ عکس خام یا proof دستی برای SocialTask لازم نیست. دوربین موبایل فقط در وضعیت مشکوک و فقط برای تولید signal امتیازی استفاده می‌شود.</div>
                    </div>
                </section>

                <aside class="tm-detail-panel" style="position:static;">
                    <div class="tm-detail-head"><span class="tm-badge tm-badge-green">Scoring</span><strong>محاسبه نتیجه</strong></div>
                    <div class="tm-detail-body">
                        <form id="socialTaskSubmitForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="idempotency_key" id="idempotencyKey" value="">
                            <input type="hidden" name="active_time" id="activeTime" value="0">
                            <input type="hidden" name="expected_time" value="<?= e((string)$expectedTime) ?>">
                            <div class="earn-alert earn-alert-info"><i class="material-icons">info</i><div>وقتی کار را تمام کردید، دکمه پایان را بزنید. سیستم رفتار ثبت‌شده را امتیازدهی می‌کند؛ نیازی به نوشتن توضیح یا ارسال اسکرین‌شات نیست.</div></div>
                            <div class="earn-actions"><button type="submit" class="earn-btn earn-btn-primary" id="submitBtn"><i class="material-icons">task_alt</i> پایان و محاسبه امتیاز</button></div>
                        </form>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersocialtasksexecute.js') . '"></script>';
include view_path('layouts.user');
?>
