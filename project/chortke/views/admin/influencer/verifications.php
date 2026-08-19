<?php
$title = 'درخواست‌های تأیید اینفلوئنسر';
$activeInfluencerAdmin = 'verifications';
$requests = $requests ?? [];
$total = (int)($total ?? 0);
$page = (int)($page ?? 1);
$pages = (int)($pages ?? 1);
$h = static fn($v): string => e((string)($v ?? ''));
$int = static fn($v): string => function_exists('fa_number') ? fa_number((int)$v) : number_format((int)$v);
ob_start();
?>
<div id="influencerRoot" class="ai-page" data-profiles-approve="<?= e(url('/admin/influencer/profiles/approve')) ?>" data-verifications-base="<?= e(url('/admin/influencer/verifications/')) ?>">
  <section class="ai-hero"><div><div class="ai-kicker"><span class="material-icons">fact_check</span> مدیریت اینفلوئنسر</div><h1>درخواست‌های تأیید پیج</h1><p>درخواست‌هایی که سیستم نتوانسته با اطمینان خودکار تأیید کند، اینجا بررسی می‌شوند.</p></div><a class="ai-btn" href="<?= e(url('/admin/influencer/profiles')) ?>">پروفایل‌ها</a></section>
  <?php include view_path('admin.influencer._admin-nav'); ?>
  <section class="ai-stats"><article class="ai-stat"><span class="material-icons">pending_actions</span><div><small>کل درخواست‌ها</small><strong><?= $int($total) ?></strong></div></article><article class="ai-stat"><span class="material-icons">photo_camera</span><div><small>روش فعلی</small><strong>اسکرین‌شات</strong></div></article><article class="ai-stat"><span class="material-icons">verified_user</span><div><small>fallback</small><strong>مدیر</strong></div></article><article class="ai-stat"><span class="material-icons">api</span><div><small>API خارجی</small><strong>غیرفعال</strong></div></article></section>
  <section class="ai-card"><div class="ai-card-head"><div><h2><span class="material-icons">rule</span> فهرست درخواست‌ها</h2><p><?= $int($total) ?> رکورد</p></div></div><?php if(empty($requests)): ?><div class="ai-empty">درخواستی برای بررسی وجود ندارد.</div><?php else: ?><div class="ai-table-wrap"><table class="ai-table"><thead><tr><th>#</th><th>پیج</th><th>کاربر</th><th>لینک/مدرک</th><th>تاریخ ارسال</th><th>عملیات</th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td>#<?= $int($r->id) ?></td><td><div class="ai-title"><strong>@<?= $h($r->username ?? '') ?></strong><small><?= $h($r->platform ?? '') ?></small></div></td><td><?= $h($r->full_name ?? $r->email ?? '—') ?></td><td><div class="ai-actions"><?php if(!empty($r->post_url)): ?><a class="ai-btn" target="_blank" href="<?= $h($r->post_url) ?>"><span class="material-icons">open_in_new</span> لینک</a><?php endif; ?><?php if(!empty($r->proof_url)): ?><a class="ai-btn" target="_blank" href="<?= $h($r->proof_url) ?>"><span class="material-icons">image</span> تصویر</a><?php endif; ?></div></td><td><?= $h(substr((string)($r->submitted_at ?? $r->created_at ?? ''),0,16)) ?></td><td><div class="ai-actions"><button class="ai-btn success" type="button" onclick="handleVerification(<?= (int)$r->id ?>,'approve')">تأیید</button><button class="ai-btn danger" type="button" onclick="handleVerification(<?= (int)$r->id ?>,'reject')">رد</button></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininfluencer.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/influencer.js') . '"></script>';
include view_path('layouts.admin');
?>
