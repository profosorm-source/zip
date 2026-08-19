<?php
$title = 'سفارش‌های اینفلوئنسر';
$activeInfluencerAdmin = 'orders';
$orders = $orders ?? [];
$filters = $filters ?? [];
$search = $search ?? '';
$statusLabels = $statusLabels ?? [];
$statusClasses = $statusClasses ?? [];
$stats = $stats ?? (object)[];
$total = (int)($total ?? 0);
$page = (int)($page ?? 1);
$pages = (int)($pages ?? 1);
$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v): string => number_format((float)($v ?? 0));
$int = static fn($v): string => function_exists('fa_number') ? fa_number((int)$v) : number_format((int)$v);
$statusMeta = static fn($s): array => match((string)$s) {
  'pending_acceptance','pending','paid' => ['در انتظار پاسخ','warn','hourglass_top'],
  'accepted' => ['پذیرفته شده','info','task_alt'],
  'awaiting_buyer_check','proof_submitted' => ['در انتظار بررسی','warn','fact_check'],
  'completed' => ['تکمیل‌شده','ok','paid'],
  'refunded','rejected_by_influencer' => ['بازگشت/رد','danger','keyboard_return'],
  'dispute','peer_resolution','escalated_to_admin' => ['اختلاف','danger','gavel'],
  default => [$s ?: 'نامشخص','muted','help'],
};
ob_start();
?>
<div id="influencerRoot" class="ai-page" data-profiles-approve="<?= e(url('/admin/influencer/profiles/approve')) ?>" data-verifications-base="<?= e(url('/admin/influencer/verifications/')) ?>">
  <section class="ai-hero"><div><div class="ai-kicker"><span class="material-icons">auto_awesome</span> مدیریت اینفلوئنسر</div><h1>سفارش‌های اینفلوئنسر</h1><p>چرخه سفارش‌ها، وضعیت escrow، تکمیل و اختلاف‌های اینفلوئنسر را اینجا پیگیری کنید.</p></div><a class="ai-btn primary" href="<?= e(url('/admin/influencer/profiles')) ?>"><span class="material-icons">groups</span> پروفایل‌ها</a></section>
  <?php include view_path('admin.influencer._admin-nav'); ?>
  <section class="ai-stats">
    <article class="ai-stat"><span class="material-icons">receipt_long</span><div><small>کل سفارش‌ها</small><strong><?= $int($stats->total_orders ?? $total) ?></strong></div></article>
    <article class="ai-stat"><span class="material-icons">pending_actions</span><div><small>فعال</small><strong><?= $int($stats->active_orders ?? 0) ?></strong></div></article>
    <article class="ai-stat"><span class="material-icons">paid</span><div><small>تکمیل‌شده</small><strong><?= $int($stats->completed_orders ?? 0) ?></strong></div></article>
    <article class="ai-stat"><span class="material-icons">account_balance</span><div><small>درآمد کل</small><strong><?= $money($stats->total_revenue ?? 0) ?></strong></div></article>
  </section>
  <section class="ai-card ai-filter"><form method="GET" action="<?= e(url('/admin/influencer/orders')) ?>"><input type="text" name="search" value="<?= $h($search) ?>" placeholder="جستجو بر اساس کاربر یا اینفلوئنسر..."><select name="status"><option value="">همه وضعیت‌ها</option><?php foreach($statusLabels as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select><select name="order_type"><option value="">همه نوع‌ها</option><option value="story" <?= ($filters['order_type'] ?? '')==='story'?'selected':'' ?>>استوری</option><option value="post" <?= ($filters['order_type'] ?? '')==='post'?'selected':'' ?>>پست</option></select><button class="ai-btn primary" type="submit"><span class="material-icons">filter_alt</span> فیلتر</button><a class="ai-btn" href="<?= e(url('/admin/influencer/orders')) ?>">پاک‌سازی</a></form></section>
  <section class="ai-card"><div class="ai-card-head"><div><h2><span class="material-icons">list_alt</span> فهرست سفارش‌ها</h2><p><?= $int($total) ?> رکورد</p></div></div><?php if(empty($orders)): ?><div class="ai-empty">سفارشی یافت نشد.</div><?php else: ?><div class="ai-table-wrap"><table class="ai-table"><thead><tr><th>#</th><th>تبلیغ‌دهنده</th><th>اینفلوئنسر</th><th>نوع</th><th>مبلغ</th><th>سهم اینفلوئنسر</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody><?php foreach($orders as $o): $m=$statusMeta((string)$o->status); ?><tr><td>#<?= $int($o->id) ?></td><td><div class="ai-title"><strong><?= $h($o->customer_name ?? '—') ?></strong><small><?= $h($o->customer_email ?? '') ?></small></div></td><td><strong>@<?= $h($o->influencer_username ?? '—') ?></strong></td><td><?= ($o->order_type ?? '')==='story'?'استوری':'پست' ?></td><td><?= $money($o->price ?? 0) ?></td><td><?= $money($o->influencer_earning ?? 0) ?></td><td><span class="ai-badge <?= e($m[1]) ?>"><span class="material-icons"><?= e($m[2]) ?></span><?= e($m[0]) ?></span></td><td><?= $h(substr((string)($o->created_at ?? ''),0,16)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininfluencer.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/influencer.js') . '"></script>';
include view_path('layouts.admin');
?>
