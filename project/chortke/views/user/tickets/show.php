<?php
$title = 'تیکت #' . ($ticket->id ?? '');
$hideSidebar = true;
use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
$status = (string)($ticket->status ?? 'open');
$priority = (string)($ticket->priority ?? 'normal');
$statusClass = in_array($status, ['closed','resolved'], true) ? 'sup-badge--muted' : ($status === 'open' ? 'sup-badge--success' : 'sup-badge--info');
$priorityClass = match($priority) { 'urgent','critical' => 'sup-badge--danger', 'high' => 'sup-badge--warning', 'low' => 'sup-badge--muted', default => 'sup-badge--info' };
ob_start();
?>

<div id="supportTicketShowRoot" class="sup-wrap" data-reply-url="<?= e(url('/tickets/reply')) ?>" data-close-url="<?= e(url('/tickets/close')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">forum</i></div>
            <div>
                <div class="sup-hero__eyebrow">Ticket #<?= e((string)$ticket->id) ?></div>
                <h1 class="sup-hero__title"><?= e($ticket->subject) ?></h1>
                <p class="sup-hero__sub">گفتگو و پیگیری رسمی با تیم پشتیبانی.</p>
            </div>
        </div>
        <div class="sup-hero__side">
            <a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز پشتیبانی</a>
            <?php if ($status !== 'closed'): ?>
                <button class="sup-btn sup-btn-danger" data-action="close-ticket" data-ticket-id="<?= e((string)$ticket->id) ?>"><i class="material-icons">lock</i> بستن تیکت</button>
            <?php endif; ?>
        </div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'tickets'; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <section class="sup-stats">
                <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">tag</i></div><div><span class="sup-stat__lbl">شماره تیکت</span><span class="sup-stat__val sup-num">#<?= e((string)$ticket->id) ?></span><span class="sup-stat__unit">شناسه پیگیری</span></div></div>
                <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">flag</i></div><div><span class="sup-stat__lbl">وضعیت</span><span class="sup-stat__val"><?= TicketStatus::label($status) ?></span><span class="sup-stat__unit">آخرین وضعیت</span></div></div>
                <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">priority_high</i></div><div><span class="sup-stat__lbl">اولویت</span><span class="sup-stat__val"><?= TicketPriority::label($priority) ?></span><span class="sup-stat__unit">سطح رسیدگی</span></div></div>
                <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">chat</i></div><div><span class="sup-stat__lbl">پیام‌ها</span><span class="sup-stat__val sup-num"><?= number_format(count($messages ?? [])) ?></span><span class="sup-stat__unit">در گفتگو</span></div></div>
            </section>

            <div class="sup-hub-layout" style="grid-template-columns:300px minmax(0,1fr);">
                <aside class="sup-section">
                    <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">info_outline</i> جزئیات تیکت</div></div>
                    <div class="sup-section__body">
                        <div class="sup-mini-row"><span>وضعیت</span><strong class="sup-badge <?= e($statusClass) ?>"><?= TicketStatus::label($status) ?></strong></div>
                        <div class="sup-mini-row"><span>اولویت</span><strong class="sup-badge <?= e($priorityClass) ?>"><?= TicketPriority::label($priority) ?></strong></div>
                        <div class="sup-mini-row"><span>دسته</span><strong><?= e($ticket->category_name ?? 'عمومی') ?></strong></div>
                        <div class="sup-mini-row"><span>ثبت</span><strong><?= to_jalali($ticket->created_at ?? '') ?></strong></div>
                        <div class="sup-mini-row"><span>آخرین بروزرسانی</span><strong><?= to_jalali($ticket->updated_at ?? '') ?></strong></div>
                    </div>
                </aside>

                <section class="sup-section">
                    <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">chat_bubble_outline</i> گفتگو</div><span class="sup-badge sup-badge--info"><?= count($messages ?? []) ?> پیام</span></div>
                    <div class="sup-section__body">
                        <div id="messagesContainer" style="display:grid;gap:12px;max-height:560px;overflow:auto;padding-left:4px;">
                            <?php foreach ($messages as $message): ?>
                                <?php $isAdmin = (bool)($message->is_admin ?? false); ?>
                                <div class="sup-notif-item" style="grid-template-columns:44px minmax(0,1fr);<?= $isAdmin ? 'border-color:rgba(240,185,11,.24);' : '' ?>">
                                    <div class="sup-notif-icon"><i class="material-icons"><?= $isAdmin ? 'support_agent' : 'person' ?></i></div>
                                    <div>
                                        <div class="sup-notif-title"><?= $isAdmin ? 'تیم پشتیبانی' : e($message->full_name ?? 'کاربر') ?> <span class="sup-badge <?= $isAdmin ? 'sup-badge--warning' : 'sup-badge--info' ?>"><?= to_jalali($message->created_at ?? '') ?></span></div>
                                        <p class="sup-notif-msg"><?= nl2br(e($message->message ?? '')) ?></p>
                                        <?php if (!empty($message->attachments)): ?>
                                            <?php $attachments = json_decode($message->attachments, true) ?: []; ?>
                                            <?php foreach ($attachments as $file): ?><a href="<?= url($file['path']) ?>" target="_blank" class="sup-badge sup-badge--muted"><i class="material-icons" style="font-size:14px;">attach_file</i><?= e($file['name']) ?></a><?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($status !== 'closed'): ?>
                            <form id="replyForm" enctype="multipart/form-data" style="margin-top:16px;">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="ticket_id" value="<?= e((string)$ticket->id) ?>">
                                <div class="sup-form-row one"><div class="sup-form-group"><label>پاسخ شما</label><textarea name="message" class="sup-textarea" rows="4" placeholder="پیام خود را بنویسید..." required></textarea></div></div>
                                <div class="sup-actions"><label class="sup-btn sup-btn-secondary" for="replyFiles"><i class="material-icons">attach_file</i> پیوست<input type="file" id="replyFiles" name="attachments[]" multiple accept="image/*" style="display:none;"></label><span id="fileNames" class="sup-file-preview" style="flex:2;"></span><button type="submit" class="sup-btn sup-btn-primary" id="sendBtn"><i class="material-icons">send</i> ارسال پاسخ</button></div>
                            </form>
                        <?php else: ?>
                            <div class="sup-alert sup-alert-warning" style="margin-top:16px;"><i class="material-icons">lock</i> این تیکت بسته شده است و امکان ارسال پاسخ وجود ندارد.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userticketsshow.js') . '"></script>';
include view_path('layouts.user');
?>
