<?php
$title = 'گزارش‌های مشکل';
$hideSidebar = true;
$reports = $reports ?? [];
$page = (int)($page ?? 1);
$statusLabels = ['open'=>['باز','sup-badge--info'],'in_progress'=>['در حال بررسی','sup-badge--warning'],'resolved'=>['حل شده','sup-badge--success'],'closed'=>['بسته شده','sup-badge--muted'],'duplicate'=>['تکراری','sup-badge--warning'],'wont_fix'=>['رد شده','sup-badge--danger']];
$priorityLabels = ['low'=>['کم','sup-badge--muted'],'normal'=>['متوسط','sup-badge--info'],'high'=>['بالا','sup-badge--warning'],'critical'=>['بحرانی','sup-badge--danger']];
$categoryLabels = ['ui_issue'=>'ظاهری','ui'=>'ظاهری','functional'=>'عملکردی','payment'=>'پرداخت','security'=>'امنیتی','performance'=>'سرعت','content'=>'محتوا','other'=>'سایر'];
$openCount = 0; $resolvedCount = 0; $commentCount = 0;
foreach ($reports as $r) { if (in_array($r->status ?? '', ['resolved','closed'], true)) $resolvedCount++; else $openCount++; $commentCount += (int)($r->comment_count ?? 0); }
ob_start();
?>

<div class="sup-wrap">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">bug_report</i></div>
            <div><div class="sup-hero__eyebrow">Bug Reports</div><h1 class="sup-hero__title">گزارش‌های مشکل</h1><p class="sup-hero__sub">خطاها و مشکلات گزارش‌شده را پیگیری کنید؛ برای پشتیبانی رسمی از تیکت استفاده کنید.</p></div>
        </div>
        <div class="sup-hero__side"><a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز پشتیبانی</a><a href="<?= url('/tickets/create') ?>" class="sup-btn sup-btn-primary"><i class="material-icons">add_comment</i> تیکت پشتیبانی</a></div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'bug'; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <section class="sup-stats">
                <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">bug_report</i></div><div><span class="sup-stat__lbl">کل گزارش‌ها</span><span class="sup-stat__val sup-num"><?= number_format(count($reports)) ?></span><span class="sup-stat__unit">این صفحه</span></div></div>
                <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">manage_search</i></div><div><span class="sup-stat__lbl">در حال بررسی</span><span class="sup-stat__val sup-num"><?= number_format($openCount) ?></span><span class="sup-stat__unit">باز یا پیگیری‌دار</span></div></div>
                <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">task_alt</i></div><div><span class="sup-stat__lbl">حل‌شده</span><span class="sup-stat__val sup-num"><?= number_format($resolvedCount) ?></span><span class="sup-stat__unit">بسته یا رفع‌شده</span></div></div>
                <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">forum</i></div><div><span class="sup-stat__lbl">پاسخ‌ها</span><span class="sup-stat__val sup-num"><?= number_format($commentCount) ?></span><span class="sup-stat__unit">کامنت ثبت‌شده</span></div></div>
            </section>

            <section class="sup-section">
                <div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">bug_report</i> لیست گزارش‌های مشکل</div><span class="sup-badge sup-badge--info">صفحه <?= number_format($page) ?></span></div>
                <?php if (empty($reports)): ?>
                    <div class="sup-empty"><i class="material-icons">check_circle_outline</i><h3>هنوز گزارشی ثبت نکرده‌اید</h3><p>اگر مشکل مهمی دارید، بهتر است تیکت پشتیبانی ثبت کنید.</p><a href="<?= url('/tickets/create') ?>" class="sup-btn sup-btn-primary">ثبت تیکت</a></div>
                <?php else: ?>
                    <div class="sup-table-wrap">
                        <table class="sup-table">
                            <thead><tr><th>#</th><th>دسته</th><th>توضیحات</th><th>اولویت</th><th>وضعیت</th><th>تاریخ</th><th>پاسخ</th></tr></thead>
                            <tbody>
                                <?php foreach ($reports as $r): ?>
                                    <?php $pri=$priorityLabels[$r->priority ?? 'normal'] ?? ['؟','sup-badge--muted']; $st=$statusLabels[$r->status ?? 'open'] ?? ['؟','sup-badge--muted']; ?>
                                    <tr data-action="navigate" data-href="<?= url('/bug-reports/' . (int)$r->id) ?>" style="cursor:pointer;">
                                        <td class="sup-num"><?= e((string)$r->id) ?></td>
                                        <td><span class="sup-badge sup-badge--info"><?= e($categoryLabels[$r->category ?? 'other'] ?? ($r->category ?? 'other')) ?></span></td>
                                        <td><?= e(mb_strimwidth((string)($r->description ?? ''), 0, 70, '...')) ?></td>
                                        <td><span class="sup-badge <?= e($pri[1]) ?>"><?= e($pri[0]) ?></span></td>
                                        <td><span class="sup-badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span></td>
                                        <td><?= to_jalali($r->created_at ?? '') ?></td>
                                        <td><?= (int)($r->comment_count ?? 0) > 0 ? '<span class="sup-badge sup-badge--success">' . e((string)$r->comment_count) . '</span>' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>
<script nonce="<?= e($cspNonce ?? '') ?>">document.addEventListener('click',e=>{const r=e.target.closest('[data-action="navigate"]');if(r&&r.dataset.href) location.href=r.dataset.href;});</script>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
include view_path('layouts.user');
?>
