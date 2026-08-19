<?php
$title = 'پروفایل‌های اینفلوئنسر';
$activeInfluencerAdmin = 'profiles';
$profiles = $profiles ?? [];
$filters = $filters ?? [];
$search = $search ?? '';
$statusLabels = $statusLabels ?? [];
$total = (int)($total ?? 0);
$h = static fn($v): string => e((string)($v ?? ''));
$int = static fn($v): string => function_exists('fa_number') ? fa_number((int)$v) : number_format((int)$v);
$money = static fn($v): string => number_format((float)($v ?? 0));
$profileMeta = static fn($s): array => match((string)$s) {
  'verified' => ['تأیید شده','ok','verified'],
  'pending_admin_review','submitted' => ['در انتظار بررسی','warn','manage_search'],
  'pending' => ['در انتظار مدرک','warn','hourglass_empty'],
  'rejected' => ['رد شده','danger','cancel'],
  'suspended' => ['تعلیق','muted','block'],
  default => [$s ?: 'نامشخص','muted','help'],
};
ob_start();
?>
<div id="influencerRoot" class="ai-page" data-profiles-approve="<?= e(url('/admin/influencer/profiles/approve')) ?>" data-verifications-base="<?= e(url('/admin/influencer/verifications/')) ?>">
  <section class="ai-hero"><div><div class="ai-kicker"><span class="material-icons">groups</span> مدیریت اینفلوئنسر</div><h1>پروفایل‌های اینفلوئنسر</h1><p>پیج‌ها را تأیید، رد یا تعلیق کنید. پروفایل‌های تأییدشده می‌توانند سفارش بگیرند.</p></div><a class="ai-btn primary" href="<?= e(url('/admin/influencer/verifications')) ?>"><span class="material-icons">fact_check</span> درخواست‌های تأیید</a></section>
  <?php include view_path('admin.influencer._admin-nav'); ?>
  <section class="ai-stats"><article class="ai-stat"><span class="material-icons">groups</span><div><small>کل پروفایل‌ها</small><strong><?= $int($total) ?></strong></div></article><article class="ai-stat"><span class="material-icons">verified</span><div><small>تأییدشده</small><strong><?= $int(count(array_filter($profiles, fn($p)=>($p->status??'')==='verified'))) ?></strong></div></article><article class="ai-stat"><span class="material-icons">manage_search</span><div><small>در انتظار</small><strong><?= $int(count(array_filter($profiles, fn($p)=>in_array(($p->status??''),['pending','pending_admin_review'],true)))) ?></strong></div></article><article class="ai-stat"><span class="material-icons">block</span><div><small>رد/تعلیق</small><strong><?= $int(count(array_filter($profiles, fn($p)=>in_array(($p->status??''),['rejected','suspended'],true)))) ?></strong></div></article></section>
  <section class="ai-card ai-filter"><form method="GET" action="<?= e(url('/admin/influencer/profiles')) ?>"><input type="text" name="search" value="<?= $h($search) ?>" placeholder="جستجو نام کاربری یا نام کاربر..."><select name="status"><option value="">همه وضعیت‌ها</option><?php foreach($statusLabels as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select><button class="ai-btn primary" type="submit"><span class="material-icons">filter_alt</span> فیلتر</button><a class="ai-btn" href="<?= e(url('/admin/influencer/profiles')) ?>">پاک‌سازی</a></form></section>
  <section class="ai-card"><div class="ai-card-head"><div><h2><span class="material-icons">badge</span> فهرست پروفایل‌ها</h2><p><?= $int($total) ?> رکورد</p></div></div><?php if(empty($profiles)): ?><div class="ai-empty">پروفایلی یافت نشد.</div><?php else: ?><div class="ai-table-wrap"><table class="ai-table"><thead><tr><th>#</th><th>پیج</th><th>کاربر</th><th>پلتفرم</th><th>فالوور</th><th>تعرفه استوری</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($profiles as $p): $m=$profileMeta((string)$p->status); ?><tr><td>#<?= $int($p->id) ?></td><td><div class="ai-title"><strong>@<?= $h($p->username) ?></strong><small><?= $h($p->category ?? '') ?></small></div></td><td><?= $h($p->full_name ?? $p->email ?? '—') ?></td><td><?= $h($p->platform) ?></td><td><?= $int($p->follower_count ?? $p->followers_count ?? 0) ?></td><td><?= $money($p->story_price_24h ?? $p->price_story ?? 0) ?></td><td><span class="ai-badge <?= e($m[1]) ?>"><span class="material-icons"><?= e($m[2]) ?></span><?= e($m[0]) ?></span></td><td><div class="ai-actions"><?php if(($p->status ?? '') !== 'verified'): ?><button class="ai-btn success" type="button" onclick="doAction(<?= (int)$p->id ?>,'approve')">تأیید</button><?php endif; ?><button class="ai-btn danger" type="button" onclick="doAction(<?= (int)$p->id ?>,'reject')">رد</button><?php if(($p->status ?? '') !== 'suspended'): ?><button class="ai-btn warn" type="button" onclick="doAction(<?= (int)$p->id ?>,'suspend')">تعلیق</button><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininfluencer.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/influencer.js') . '"></script>';
include view_path('layouts.admin');
?>
