<?php
$title = 'گزارش مشکل #' . ($report->id ?? '');
$hideSidebar = true;
$statusLabels = ['open'=>['باز','sup-badge--info'],'in_progress'=>['در حال بررسی','sup-badge--warning'],'resolved'=>['حل شده','sup-badge--success'],'closed'=>['بسته شده','sup-badge--muted'],'duplicate'=>['تکراری','sup-badge--warning'],'wont_fix'=>['رد شده','sup-badge--danger']];
$priorityLabels = ['low'=>['کم','sup-badge--muted'],'normal'=>['متوسط','sup-badge--info'],'high'=>['بالا','sup-badge--warning'],'critical'=>['بحرانی','sup-badge--danger']];
$categoryLabels = ['ui_issue'=>'ظاهری','ui'=>'ظاهری','functional'=>'عملکردی','payment'=>'پرداخت','security'=>'امنیتی','performance'=>'سرعت','content'=>'محتوا','other'=>'سایر'];
$st = $statusLabels[$report->status ?? 'open'] ?? ['؟','sup-badge--muted'];
$pri = $priorityLabels[$report->priority ?? 'normal'] ?? ['؟','sup-badge--muted'];
ob_start();
?>

<div id="supportBugShowRoot" class="sup-wrap" data-comment-url="<?= e(url('/bug-reports/' . (int)$report->id . '/comment')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">bug_report</i></div>
            <div><div class="sup-hero__eyebrow">Bug Report #<?= e((string)$report->id) ?></div><h1 class="sup-hero__title">جزئیات گزارش مشکل</h1><p class="sup-hero__sub">وضعیت گزارش، پاسخ‌ها و اطلاعات فنی ارسال‌شده را پیگیری کنید.</p></div>
        </div>
        <div class="sup-hero__side"><a href="<?= url('/bug-reports') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به گزارش‌ها</a><a href="<?= url('/tickets/create') ?>" class="sup-btn sup-btn-primary"><i class="material-icons">add_comment</i> ثبت تیکت</a></div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'bug'; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <section class="sup-stats">
                <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">tag</i></div><div><span class="sup-stat__lbl">شناسه گزارش</span><span class="sup-stat__val sup-num">#<?= e((string)$report->id) ?></span><span class="sup-stat__unit">پیگیری فنی</span></div></div>
                <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">category</i></div><div><span class="sup-stat__lbl">دسته</span><span class="sup-stat__val"><?= e($categoryLabels[$report->category ?? 'other'] ?? ($report->category ?? 'other')) ?></span><span class="sup-stat__unit">نوع خطا</span></div></div>
                <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">flag</i></div><div><span class="sup-stat__lbl">وضعیت</span><span class="sup-stat__val"><?= e($st[0]) ?></span><span class="sup-stat__unit">آخرین وضعیت</span></div></div>
                <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">priority_high</i></div><div><span class="sup-stat__lbl">اولویت</span><span class="sup-stat__val"><?= e($pri[0]) ?></span><span class="sup-stat__unit">شدت مشکل</span></div></div>
            </section>

            <section class="sup-section">
                <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">description</i> شرح گزارش</div><div><span class="sup-badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span> <span class="sup-badge <?= e($pri[1]) ?>"><?= e($pri[0]) ?></span></div></div>
                <div class="sup-section__body">
                    <div class="sup-form-row">
                        <div class="sup-mini-row"><span>تاریخ</span><strong><?= to_jalali($report->created_at ?? '') ?></strong></div>
                        <div class="sup-mini-row"><span>صفحه</span><strong dir="ltr"><?= e(mb_strimwidth((string)($report->page_url ?? ''), 0, 70, '...')) ?></strong></div>
                    </div>
                    <div class="sup-alert sup-alert-info"><i class="material-icons">notes</i><div><?= nl2br(e($report->description ?? '')) ?></div></div>
                    <?php if (!empty($report->screenshot_path)): ?><div style="margin-top:14px;"><strong>اسکرین‌شات:</strong><br><img src="<?= asset($report->screenshot_path) ?>" alt="screenshot" style="max-width:100%;border-radius:16px;border:1px solid var(--sup-border-soft);margin-top:8px;"></div><?php endif; ?>
                    <?php if (!empty($report->admin_note)): ?><div class="sup-alert sup-alert-warning" style="margin-top:14px;"><i class="material-icons">admin_panel_settings</i><div><strong>یادداشت مدیریت:</strong><br><?= e($report->admin_note) ?></div></div><?php endif; ?>
                </div>
            </section>

            <section class="sup-section">
                <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">forum</i> پیام‌ها</div><span class="sup-badge sup-badge--info"><?= number_format(count($comments ?? [])) ?> پیام</span></div>
                <div class="sup-section__body">
                    <?php if (empty($comments)): ?>
                        <div class="sup-empty"><i class="material-icons">forum</i><h3>هنوز پیامی ثبت نشده</h3><p>در صورت نیاز پیام تکمیلی ارسال کنید.</p></div>
                    <?php else: ?>
                        <div style="display:grid;gap:12px;">
                            <?php foreach ($comments as $c): $isAdmin = ($c->user_type ?? '') === 'admin'; ?>
                                <div class="sup-notif-item" style="grid-template-columns:44px minmax(0,1fr);<?= $isAdmin ? 'border-color:rgba(240,185,11,.24);' : '' ?>"><div class="sup-notif-icon"><i class="material-icons"><?= $isAdmin ? 'support_agent' : 'person' ?></i></div><div><div class="sup-notif-title"><?= e($c->user_full_name ?? 'کاربر') ?> <span class="sup-badge <?= $isAdmin ? 'sup-badge--warning' : 'sup-badge--info' ?>"><?= to_jalali($c->created_at ?? '') ?></span></div><p class="sup-notif-msg"><?= e($c->comment ?? '') ?></p><?php if (!empty($c->attachment_path)): ?><img src="<?= asset($c->attachment_path) ?>" alt="پیوست" style="max-width:220px;border-radius:12px;border:1px solid var(--sup-border-soft);"><?php endif; ?></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($report->status ?? '', ['open','in_progress'], true)): ?>
                        <div class="sup-actions" style="margin-top:16px;"><input type="text" id="userComment" class="sup-input" placeholder="پیام خود را بنویسید..." style="flex:3;"><button class="sup-btn sup-btn-primary" id="sendComment"><i class="material-icons">send</i> ارسال</button></div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userbugreportsshow.js') . '"></script>';
include view_path('layouts.user');
?>
