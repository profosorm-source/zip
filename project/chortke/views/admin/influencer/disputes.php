<?php
$title = 'اختلاف‌های اینفلوئنسر';
$activeInfluencerAdmin = 'disputes';
$disputes = $disputes ?? [];
$filters = $filters ?? [];
$total = (int)($total ?? count($disputes));
$h = static fn($v): string => e((string)($v ?? ''));
$int = static fn($v): string => function_exists('fa_number') ? fa_number((int)$v) : number_format((int)$v);
$statusMeta = static fn($s): array => match((string)$s) {
  'open','open_peer' => ['گفت‌وگوی طرفین','warn','forum'],
  'escalated' => ['ارجاع به مدیر','danger','gavel'],
  'resolved_admin','resolved_peer','closed' => ['حل‌شده','ok','check_circle'],
  default => [$s ?: 'نامشخص','muted','help'],
};
ob_start();
?>
<div id="influencerRoot" class="ai-page" data-profiles-approve="<?= e(url('/admin/influencer/profiles/approve')) ?>" data-verifications-base="<?= e(url('/admin/influencer/verifications/')) ?>">
  <section class="ai-hero"><div><div class="ai-kicker"><span class="material-icons">gavel</span> مدیریت اینفلوئنسر</div><h1>اختلاف‌های اینفلوئنسر</h1><p>داوری سفارش‌ها از مسیر escrow انجام می‌شود؛ رأی مدیر مستقیماً روی امانت مالی اثر می‌گذارد.</p></div><a class="ai-btn" href="<?= e(url('/admin/influencer/orders')) ?>">سفارش‌ها</a></section>
  <?php include view_path('admin.influencer._admin-nav'); ?>
  <section class="ai-card ai-filter"><form method="GET" action="<?= e(url('/admin/influencer/disputes')) ?>"><input type="text" name="search" value="<?= $h($filters['search'] ?? '') ?>" placeholder="جستجو در طرفین..."><select name="status"><option value="">همه وضعیت‌ها</option><option value="open_peer" <?= ($filters['status'] ?? '')==='open_peer'?'selected':'' ?>>گفت‌وگوی طرفین</option><option value="escalated" <?= ($filters['status'] ?? '')==='escalated'?'selected':'' ?>>ارجاع به مدیر</option><option value="resolved_admin" <?= ($filters['status'] ?? '')==='resolved_admin'?'selected':'' ?>>حل‌شده</option></select><button class="ai-btn primary" type="submit">فیلتر</button><a class="ai-btn" href="<?= e(url('/admin/influencer/disputes')) ?>">پاک‌سازی</a></form></section>
  <section class="ai-card"><div class="ai-card-head"><div><h2><span class="material-icons">balance</span> پرونده‌ها</h2><p><?= $int($total) ?> رکورد</p></div></div><?php if(empty($disputes)): ?><div class="ai-empty">اختلافی یافت نشد.</div><?php else: ?><div class="ai-table-wrap"><table class="ai-table"><thead><tr><th>#</th><th>سفارش</th><th>شاکی</th><th>طرف مقابل</th><th>دلیل</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($disputes as $d): $m=$statusMeta((string)$d->status); ?><tr><td>#<?= $int($d->id) ?></td><td>#<?= $int($d->ref_id ?? 0) ?></td><td><?= $h($d->customer_name ?? '—') ?></td><td><?= $h($d->other_party_name ?? '—') ?></td><td><?= $h(mb_substr((string)($d->reason ?? ''),0,70)) ?></td><td><span class="ai-badge <?= e($m[1]) ?>"><span class="material-icons"><?= e($m[2]) ?></span><?= e($m[0]) ?></span></td><td><a class="ai-btn primary" href="<?= e(url('/admin/influencer/disputes/' . (int)$d->id)) ?>">داوری</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininfluencer.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/influencer.js') . '"></script>';
include view_path('layouts.admin');
?>
