<?php
$title = $title ?? 'جزئیات اختلاف تسک سفارشی';
$hideSidebar = true;
$dispute = $dispute ?? null;
$messages = $messages ?? [];
$status = (string)($dispute->status ?? 'open');
$closed = in_array($status, ['resolved_for_executor','resolved_for_advertiser','resolved_admin','closed'], true);
$statusLabels = [
    'open' => 'باز',
    'open_peer' => 'باز',
    'under_review' => 'در حال بررسی',
    'escalated' => 'ارجاع به ادمین',
    'resolved_for_executor' => 'حل به نفع مجری',
    'resolved_for_advertiser' => 'حل به نفع تبلیغ‌دهنده',
    'resolved_admin' => 'حل‌شده',
    'closed' => 'بسته',
];
ob_start();
?>

<div class="earn-wrap task-market-wrap">
    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><i class="material-icons">gavel</i></div>
            <div>
                <div class="earn-hero__eyebrow">Custom Task Dispute</div>
                <h1 class="earn-hero__title">پرونده اختلاف #<?= e((string)$dispute->id) ?></h1>
                <p class="earn-hero__sub">گفت‌وگوی دوطرفه و پیگیری وضعیت اختلاف برای تسک «<?= e($dispute->task_title ?? '—') ?>».</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/custom-tasks/disputes-list') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به اختلاف‌ها</a>
            <span class="tm-badge <?= $closed ? 'tm-badge-green' : 'tm-badge-gold' ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
        </div>
    </section>

    <div class="earn-hub-layout">
        <?php $activeSpoke = 'custom'; include view_path('user.tasks._earn-nav'); ?>
        <main class="earn-hub-main">
            <section class="earn-stats">
                <div class="earn-stat earn-stat--gold"><div class="earn-stat__icon"><i class="material-icons">payments</i></div><div><span class="earn-stat__lbl">پاداش</span><span class="earn-stat__val earn-num"><?= number_format((float)($dispute->reward_amount ?? 0)) ?></span><span class="earn-stat__unit"><?= e($dispute->reward_currency ?? 'irt') ?></span></div></div>
                <div class="earn-stat earn-stat--green"><div class="earn-stat__icon"><i class="material-icons">assignment</i></div><div><span class="earn-stat__lbl">وضعیت ارسال</span><span class="earn-stat__val"><?= e($dispute->submission_status ?? '—') ?></span><span class="earn-stat__unit">submission #<?= e((string)($dispute->submission_id ?? '')) ?></span></div></div>
                <div class="earn-stat earn-stat--blue"><div class="earn-stat__icon"><i class="material-icons">person</i></div><div><span class="earn-stat__lbl">مجری</span><span class="earn-stat__val"><?= e($dispute->worker_name ?? '—') ?></span><span class="earn-stat__unit">طرف اجرا</span></div></div>
                <div class="earn-stat earn-stat--red"><div class="earn-stat__icon"><i class="material-icons">campaign</i></div><div><span class="earn-stat__lbl">تبلیغ‌دهنده</span><span class="earn-stat__val"><?= e($dispute->advertiser_name ?? '—') ?></span><span class="earn-stat__unit">طرف سفارش</span></div></div>
            </section>

            <div class="tm-board" style="grid-template-columns:minmax(0,1fr) 360px;">
                <section class="earn-section">
                    <div class="earn-section__header"><div class="earn-section__title"><i class="material-icons">forum</i> گفت‌وگوی پرونده</div></div>
                    <div class="earn-section__body">
                        <?php if (empty($messages)): ?>
                            <div class="earn-empty"><i class="material-icons">chat_bubble_outline</i><h3>هنوز پیامی ثبت نشده</h3><p>اولین پیام هنگام ثبت اختلاف ایجاد می‌شود.</p></div>
                        <?php else: ?>
                            <div class="tm-steps" style="gap:10px;">
                                <?php foreach ($messages as $msg): $isMe = (int)$msg->user_id === (int)user_id(); ?>
                                    <div class="earn-alert <?= $isMe ? 'earn-alert-info' : 'earn-alert-warning' ?>" style="margin-bottom:0;">
                                        <i class="material-icons"><?= $isMe ? 'person' : 'record_voice_over' ?></i>
                                        <div style="width:100%;">
                                            <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;"><strong><?= e($isMe ? 'شما' : ($msg->sender_name ?? 'طرف مقابل')) ?></strong><small><?= e(substr((string)$msg->created_at, 0, 16)) ?></small></div>
                                            <div><?= nl2br(e($msg->message)) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$closed): ?>
                            <form method="POST" action="<?= url('/custom-tasks/disputes/' . (int)$dispute->id . '/reply') ?>" style="margin-top:16px;">
                                <?= csrf_field() ?>
                                <div class="earn-form-group" style="margin-bottom:12px;"><label>پاسخ شما</label><textarea name="message" class="earn-textarea" rows="4" placeholder="پیام خود را برای طرف مقابل یا داور بنویسید..."></textarea></div>
                                <button type="submit" class="earn-btn earn-btn-primary"><i class="material-icons">send</i> ارسال پیام</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <aside class="tm-detail-panel" style="position:static;">
                    <div class="tm-detail-head"><span class="tm-badge tm-badge-blue">Case</span><strong>جزئیات اختلاف</strong></div>
                    <div class="tm-detail-body">
                        <h2><?= e($dispute->task_title ?? '—') ?></h2>
                        <p><?= e($dispute->task_description ?? '') ?></p>
                        <div class="tm-warning"><strong>دلیل اختلاف:</strong><br><?= nl2br(e($dispute->reason ?? '')) ?></div>
                        <?php if (!empty($dispute->rejection_reason)): ?><div class="earn-alert earn-alert-danger" style="margin-top:12px;"><i class="material-icons">error</i><div><strong>دلیل رد:</strong><br><?= nl2br(e($dispute->rejection_reason)) ?></div></div><?php endif; ?>
                        <div class="tm-badges"><span class="tm-badge tm-badge-gold">وضعیت: <?= e($statusLabels[$status] ?? $status) ?></span><span class="tm-badge tm-badge-blue">Ref #<?= e((string)$dispute->ref_id) ?></span></div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
$content = ob_get_clean();
include view_path('layouts.user');
?>
