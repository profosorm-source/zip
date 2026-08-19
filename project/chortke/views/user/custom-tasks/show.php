<?php
$title = $title ?? 'جزئیات تسک سفارشی';
$hideSidebar = true;
$task = $task ?? null;
$reward = (float)($task->price_per_task ?? $task->reward_per_user ?? $task->reward_amount ?? 0);
$taskId = (int)($task->id ?? 0);
ob_start();
?>

<div id="customTaskShowRoot"
     class="earn-wrap task-market-wrap"
     data-start-url="<?= e(url('/custom-tasks/' . $taskId . '/start-execution')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">
    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><i class="material-icons">assignment</i></div>
            <div>
                <div class="earn-hero__eyebrow">Custom Task</div>
                <h1 class="earn-hero__title"><?= e($task->title ?? 'تسک سفارشی') ?></h1>
                <p class="earn-hero__sub">جزئیات تسک سفارشی را بررسی کنید، سپس اجرا را شروع کنید و مدرک انجام را در مرحله بعد ارسال کنید.</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/tasks?type=custom_task') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار تسک‌ها</a>
        </div>
    </section>

    <div class="earn-hub-layout">
        <?php $activeSpoke = 'custom'; include view_path('user.tasks._earn-nav'); ?>
        <main class="earn-hub-main">
            <section class="earn-stats">
                <div class="earn-stat earn-stat--gold"><div class="earn-stat__icon"><i class="material-icons">payments</i></div><div><span class="earn-stat__lbl">پاداش</span><span class="earn-stat__val earn-num"><?= number_format($reward) ?></span><span class="earn-stat__unit">تومان</span></div></div>
                <div class="earn-stat earn-stat--green"><div class="earn-stat__icon"><i class="material-icons">groups</i></div><div><span class="earn-stat__lbl">ظرفیت باقی‌مانده</span><span class="earn-stat__val earn-num"><?= number_format((int)($task->remaining_count ?? $task->slots_remaining ?? 0)) ?></span><span class="earn-stat__unit">جای خالی</span></div></div>
                <div class="earn-stat earn-stat--blue"><div class="earn-stat__icon"><i class="material-icons">timer</i></div><div><span class="earn-stat__lbl">مهلت</span><span class="earn-stat__val"><?= !empty($task->deadline) ? e(substr($task->deadline, 0, 10)) : 'بدون محدودیت' ?></span><span class="earn-stat__unit">زمان انجام</span></div></div>
                <div class="earn-stat earn-stat--red"><div class="earn-stat__icon"><i class="material-icons">verified_user</i></div><div><span class="earn-stat__lbl">وضعیت</span><span class="earn-stat__val"><?= e($task->status ?? 'active') ?></span><span class="earn-stat__unit">قابل اجرا</span></div></div>
            </section>

            <section class="earn-section">
                <div class="earn-section__header"><div class="earn-section__title"><i class="material-icons">description</i> توضیحات و مراحل</div></div>
                <div class="earn-section__body">
                    <p style="color:var(--earn-text-dim);line-height:2;margin-top:0;"><?= nl2br(e($task->description ?? 'توضیحی ثبت نشده است.')) ?></p>
                    <?php if (!empty($task->instructions)): ?>
                        <div class="earn-alert earn-alert-info"><i class="material-icons">info</i><div><?= nl2br(e($task->instructions)) ?></div></div>
                    <?php endif; ?>
                    <form id="startCustomTaskForm" method="POST" action="<?= url('/custom-tasks/' . $taskId . '/start-execution') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="task_id" value="<?= e((string)$taskId) ?>">
                        <div class="earn-actions"><button type="submit" class="earn-btn earn-btn-primary" id="customStartBtn"><i class="material-icons">rocket_launch</i> شروع اجرای تسک</button><a href="<?= url('/tasks?type=custom_task') ?>" class="earn-btn earn-btn-secondary">انصراف</a></div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>
<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usercustomtaskshow.js') . '"></script>';
$content = ob_get_clean();
include view_path('layouts.user');
?>
