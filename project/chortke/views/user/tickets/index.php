<?php
$title = 'مرکز پشتیبانی';
$hideSidebar = true;
$tickets = $tickets ?? [];
$total = (int)($total ?? count($tickets));
$unreadCount = (int)($unreadCount ?? 0);
$status = $status ?? '';
$page = (int)($page ?? 1);
$totalPages = (int)($totalPages ?? 1);

use App\Enums\TicketStatus;
use App\Enums\TicketPriority;

$openCount = 0; $closedCount = 0; $newReplies = 0;
foreach ($tickets as $ticket) {
    if (in_array($ticket->status ?? '', ['closed','resolved'], true)) $closedCount++; else $openCount++;
    if (($ticket->last_reply_by ?? '') === 'admin') $newReplies++;
}

ob_start();
?>

<div class="sup-wrap">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">confirmation_number</i></div>
            <div>
                <div class="sup-hero__eyebrow">Support Hub</div>
                <h1 class="sup-hero__title">مرکز پشتیبانی</h1>
                <p class="sup-hero__sub">تیکت‌ها، اعلان‌ها، گزارش‌های مشکل و جستجو را از یک مرکز واحد مدیریت کنید.</p>
            </div>
        </div>
        <div class="sup-hero__side">
            <a href="<?= url('/dashboard') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">dashboard</i> بازگشت به پنل کاربری</a>
            <a href="<?= url('/tickets/create') ?>" class="sup-btn sup-btn-primary"><i class="material-icons">add</i> تیکت جدید</a>
        </div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'tickets'; $openTicketCount = $openCount; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <?php if ($unreadCount > 0): ?>
                <div class="sup-alert sup-alert-warning"><i class="material-icons">mark_chat_unread</i><span>شما <strong><?= number_format($unreadCount) ?></strong> پاسخ خوانده‌نشده دارید.</span></div>
            <?php endif; ?>

            <section class="sup-spoke-grid">
                <a href="<?= url('/tickets/create') ?>" class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">add_comment</i></span><span class="sup-spoke-card__body"><strong>تیکت جدید</strong><small>ثبت درخواست پشتیبانی</small></span><i class="material-icons">chevron_left</i></a>
                <a href="<?= url('/bug-reports') ?>" class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">bug_report</i></span><span class="sup-spoke-card__body"><strong>گزارش مشکل</strong><small>پیگیری خطاهای فنی</small></span><i class="material-icons">chevron_left</i></a>
                <a href="<?= url('/search') ?>" class="sup-spoke-card"><span class="sup-spoke-card__icon"><i class="material-icons">search</i></span><span class="sup-spoke-card__body"><strong>جستجو</strong><small>جستجو در تیکت‌ها و تراکنش‌ها</small></span><i class="material-icons">chevron_left</i></a>
            </section>

            <section class="sup-stats">
                <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">confirmation_number</i></div><div><span class="sup-stat__lbl">کل تیکت‌ها</span><span class="sup-stat__val sup-num"><?= number_format($total) ?></span><span class="sup-stat__unit">درخواست ثبت‌شده</span></div></div>
                <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">support_agent</i></div><div><span class="sup-stat__lbl">تیکت‌های باز</span><span class="sup-stat__val sup-num"><?= number_format($openCount) ?></span><span class="sup-stat__unit">نیازمند پیگیری</span></div></div>
                <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">mark_chat_unread</i></div><div><span class="sup-stat__lbl">پاسخ جدید</span><span class="sup-stat__val sup-num"><?= number_format($newReplies) ?></span><span class="sup-stat__unit">از سمت پشتیبانی</span></div></div>
                <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">task_alt</i></div><div><span class="sup-stat__lbl">بسته/حل‌شده</span><span class="sup-stat__val sup-num"><?= number_format($closedCount) ?></span><span class="sup-stat__unit">آرشیو شده</span></div></div>
            </section>

            <form method="GET" action="<?= url('/tickets') ?>" class="sup-filter">
                <select name="status" class="sup-select" style="max-width:260px;">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach (TicketStatus::all() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= TicketStatus::label($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sup-btn sup-btn-primary"><i class="material-icons">filter_list</i> فیلتر</button>
                <?php if (!empty($status)): ?><a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-secondary"><i class="material-icons">close</i> حذف فیلتر</a><?php endif; ?>
            </form>

            <section class="sup-section">
                <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">confirmation_number</i> تیکت‌های پشتیبانی</div><span class="sup-badge sup-badge--info"><?= number_format(count($tickets)) ?> مورد این صفحه</span></div>
                <div class="sup-section__body">
                    <?php if (empty($tickets)): ?>
                        <div class="sup-empty"><i class="material-icons">confirmation_number</i><h3>هنوز تیکتی ثبت نکرده‌اید</h3><p>برای ارتباط با تیم پشتیبانی، اولین تیکت خود را ثبت کنید.</p><a href="<?= url('/tickets/create') ?>" class="sup-btn sup-btn-primary">ایجاد اولین تیکت</a></div>
                    <?php else: ?>
                        <div class="sup-ticket-list">
                            <?php foreach ($tickets as $ticket): ?>
                                <?php
                                $isNew = ($ticket->last_reply_by ?? '') === 'admin';
                                $statusClass = in_array($ticket->status ?? '', ['closed','resolved'], true) ? 'sup-badge--muted' : (($ticket->status ?? '') === 'open' ? 'sup-badge--success' : 'sup-badge--info');
                                $priorityClass = match($ticket->priority ?? 'normal') { 'urgent','critical' => 'sup-badge--danger', 'high' => 'sup-badge--warning', 'low' => 'sup-badge--muted', default => 'sup-badge--info' };
                                ?>
                                <a href="<?= url('/tickets/show/' . (int)$ticket->id) ?>" class="sup-ticket-card <?= $isNew ? 'sup-ticket-card--new' : '' ?>">
                                    <div class="sup-ticket-head">
                                        <div class="sup-ticket-cat"><i class="material-icons"><?= e($ticket->category_icon ?? 'category') ?></i><?= e($ticket->category_name ?? 'عمومی') ?></div>
                                        <div><span class="sup-badge <?= $statusClass ?>"><?= TicketStatus::label($ticket->status) ?></span> <span class="sup-badge <?= $priorityClass ?>"><?= TicketPriority::label($ticket->priority) ?></span> <?php if ($isNew): ?><span class="sup-badge sup-badge--success">پاسخ جدید</span><?php endif; ?></div>
                                    </div>
                                    <div class="sup-ticket-subject"><?= e($ticket->subject) ?></div>
                                    <div class="sup-ticket-foot"><span class="sup-ticket-date"><i class="material-icons">schedule</i><?= to_jalali($ticket->updated_at) ?></span><span class="sup-ticket-id">#<?= e((string)$ticket->id) ?></span></div>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="sup-actions" style="justify-content:center;">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <a class="sup-btn <?= $i === $page ? 'sup-btn-primary' : 'sup-btn-secondary' ?>" href="<?= url('/tickets?status=' . urlencode($status) . '&page=' . $i) ?>"><?= e((string)$i) ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
include view_path('layouts.user');
?>
