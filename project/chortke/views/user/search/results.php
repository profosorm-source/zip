<?php
$title = 'جستجو';
$hideSidebar = true;
$query = $query ?? '';
$results = $results ?? [];
$total = 0;
foreach ($results as $k => $v) { if (is_array($v)) $total += count($v); }
ob_start();
?>

<div class="sup-wrap">
    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">search</i></div>
            <div><div class="sup-hero__eyebrow">Global Search</div><h1 class="sup-hero__title">جستجو</h1><p class="sup-hero__sub">در تراکنش‌ها، تیکت‌ها، کمپین‌ها و فعالیت‌های حساب خود جستجو کنید.</p></div>
        </div>
        <div class="sup-hero__side"><a href="<?= url('/tickets') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز پشتیبانی</a></div>
    </section>

    <div class="sup-hub-layout">
        <?php $activeSpoke = 'search'; include view_path('user.support._support-nav'); ?>
        <main class="sup-hub-main">
            <section class="sup-form-card" style="margin-bottom:16px;">
                <div class="sup-form-card__head"><div class="sup-form-card__title"><i class="material-icons">search</i> جستجوی سریع</div></div>
                <div class="sup-form-card__body">
                    <form method="GET" action="<?= url('/search') ?>" class="sup-filter" style="margin-bottom:0;">
                        <input type="text" name="q" class="sup-input" value="<?= e($query) ?>" placeholder="جستجو در تراکنش‌ها، تیکت‌ها، کمپین‌ها..." autofocus style="flex:1;min-width:280px;">
                        <button class="sup-btn sup-btn-primary"><i class="material-icons">search</i> جستجو</button>
                    </form>
                </div>
            </section>

            <section class="sup-stats">
                <div class="sup-stat sup-stat--gold"><div class="sup-stat__icon"><i class="material-icons">search</i></div><div><span class="sup-stat__lbl">عبارت جستجو</span><span class="sup-stat__val"><?= $query !== '' ? e($query) : '—' ?></span><span class="sup-stat__unit">حداقل ۲ کاراکتر</span></div></div>
                <div class="sup-stat sup-stat--green"><div class="sup-stat__icon"><i class="material-icons">dataset</i></div><div><span class="sup-stat__lbl">کل نتایج</span><span class="sup-stat__val sup-num"><?= number_format($total) ?></span><span class="sup-stat__unit">در همه دسته‌ها</span></div></div>
                <div class="sup-stat sup-stat--blue"><div class="sup-stat__icon"><i class="material-icons">confirmation_number</i></div><div><span class="sup-stat__lbl">تیکت‌ها</span><span class="sup-stat__val sup-num"><?= number_format(count($results['tickets'] ?? [])) ?></span><span class="sup-stat__unit">نتیجه</span></div></div>
                <div class="sup-stat sup-stat--red"><div class="sup-stat__icon"><i class="material-icons">receipt_long</i></div><div><span class="sup-stat__lbl">تراکنش‌ها</span><span class="sup-stat__val sup-num"><?= number_format(count($results['transactions'] ?? [])) ?></span><span class="sup-stat__unit">نتیجه</span></div></div>
            </section>

            <?php if (empty($query)): ?>
                <div class="sup-empty sup-section"><i class="material-icons">search</i><h3>عبارت جستجو را وارد کنید</h3><p>برای شروع، حداقل دو کاراکتر تایپ کنید.</p></div>
            <?php elseif ($total === 0): ?>
                <div class="sup-empty sup-section"><i class="material-icons">search_off</i><h3>نتیجه‌ای یافت نشد</h3><p>برای «<?= e($query) ?>» چیزی پیدا نکردیم.</p></div>
            <?php else: ?>
                <?php if (!empty($results['transactions'])): ?>
                    <section class="sup-section"><div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">receipt_long</i> تراکنش‌ها</div><span class="sup-badge sup-badge--info"><?= count($results['transactions']) ?></span></div><div class="sup-table-wrap"><table class="sup-table"><thead><tr><th>#</th><th>نوع</th><th>مبلغ</th><th>ارز</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody><?php foreach ($results['transactions'] as $t): ?><tr><td><?= (int)$t->id ?></td><td><?= e($t->type ?? '') ?></td><td class="sup-num"><?= number_format((float)($t->amount ?? 0)) ?></td><td><?= e($t->currency ?? '') ?></td><td><span class="sup-badge sup-badge--muted"><?= e($t->status ?? '') ?></span></td><td><?= to_jalali($t->created_at ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
                <?php endif; ?>

                <?php if (!empty($results['tickets'])): ?>
                    <section class="sup-section"><div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">confirmation_number</i> تیکت‌ها</div><span class="sup-badge sup-badge--info"><?= count($results['tickets']) ?></span></div><div class="sup-section__body"><div class="sup-ticket-list"><?php foreach ($results['tickets'] as $t): ?><a href="<?= url('/tickets/show/' . (int)$t->id) ?>" class="sup-ticket-card"><div class="sup-ticket-head"><div class="sup-ticket-cat"><i class="material-icons">confirmation_number</i>#<?= (int)$t->id ?></div><span class="sup-badge <?= ($t->status ?? '') === 'open' ? 'sup-badge--success' : 'sup-badge--muted' ?>"><?= e($t->status ?? '') ?></span></div><div class="sup-ticket-subject"><?= e(mb_substr($t->subject ?? '', 0, 80)) ?></div><div class="sup-ticket-foot"><span class="sup-ticket-date"><?= to_jalali($t->created_at ?? '') ?></span></div></a><?php endforeach; ?></div></div></section>
                <?php endif; ?>

                <?php foreach (['ads'=>'کمپین‌های تبلیغاتی','tasks'=>'تسک‌ها'] as $key => $label): ?>
                    <?php if (!empty($results[$key])): ?>
                        <section class="sup-section"><div class="sup-section__header"><div class="sup-section__title"><i class="material-icons">view_list</i> <?= e($label) ?></div><span class="sup-badge sup-badge--info"><?= count($results[$key]) ?></span></div><div class="sup-table-wrap"><table class="sup-table"><tbody><?php foreach ($results[$key] as $item): ?><tr><td><?= e($item->title ?? $item->ad_title ?? ('#'.($item->id ?? ''))) ?></td><td><?= e($item->status ?? $item->platform ?? '') ?></td><td><?= to_jalali($item->created_at ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
include view_path('layouts.user');
?>
