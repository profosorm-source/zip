<?php
$title = 'داوری اختلاف اینفلوئنسر';
$activeInfluencerAdmin = 'disputes';
$messages = $messages ?? [];
$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v): string => number_format((float)($v ?? 0));
$status = (string)($dispute->status ?? '');
$isResolved = in_array($status, ['resolved_peer','resolved_admin','closed'], true);
$statusLabel = ['open_peer'=>'گفت‌وگوی طرفین','open'=>'باز','escalated'=>'ارجاع به مدیر','resolved_admin'=>'رأی مدیر صادر شد','resolved_peer'=>'حل دوستانه','closed'=>'بسته'][$status] ?? $status;
ob_start();
?>
<div id="influencerRoot" class="ai-page" data-dispute-id="<?= (int)($dispute->id ?? 0) ?>" data-resolve-url="<?= e(url('/admin/influencer/disputes/' . (int)($dispute->id ?? 0) . '/resolve')) ?>">
  <section class="ai-hero"><div><div class="ai-kicker"><span class="material-icons">balance</span> داوری اینفلوئنسر</div><h1>پرونده اختلاف #<?= (int)($dispute->id ?? 0) ?></h1><p>رأی این صفحه مستقیماً از مسیر escrow سفارش اجرا می‌شود؛ قبل از صدور رأی مدارک را بررسی کنید.</p></div><a class="ai-btn" href="<?= e(url('/admin/influencer/disputes')) ?>"><span class="material-icons">arrow_back</span> بازگشت</a></section>
  <?php include view_path('admin.influencer._admin-nav'); ?>
  <section class="ai-detail-grid">
    <aside class="ai-card"><div class="ai-card-head"><h2><span class="material-icons">info</span> اطلاعات پرونده</h2></div><div class="ai-info"><div><small>وضعیت</small><span class="ai-badge <?= $isResolved?'ok':($status==='escalated'?'danger':'warn') ?>"><?= $h($statusLabel) ?></span></div><div><small>سفارش</small><strong>#<?= (int)($order->id ?? $dispute->ref_id ?? 0) ?></strong></div><div><small>تبلیغ‌دهنده</small><strong><?= $h($dispute->customer_name ?? '—') ?></strong></div><div><small>اینفلوئنسر</small><strong><?= $h($dispute->other_party_name ?? '—') ?></strong></div><div><small>نوع</small><strong><?= (($order->order_type ?? '')==='story')?'استوری':'پست' ?></strong></div><div><small>مبلغ سفارش</small><strong><?= $money($order->price ?? 0) ?></strong></div><div><small>سهم اینفلوئنسر</small><strong><?= $money($order->influencer_earning ?? 0) ?></strong></div></div><?php if(!empty($order->proof_link)): ?><div class="ai-info"><a class="ai-btn primary" target="_blank" href="<?= $h($order->proof_link) ?>"><span class="material-icons">open_in_new</span> مشاهده مدرک</a></div><?php endif; ?></aside>
    <main class="ai-card"><div class="ai-card-head"><div><h2><span class="material-icons">forum</span> گفت‌وگوی طرفین</h2><p><?= $h($dispute->reason ?? '') ?></p></div></div><div class="ai-messages" id="msgBox"><?php if(empty($messages)): ?><div class="ai-empty">پیامی ثبت نشده است.</div><?php else: ?><?php foreach($messages as $m): ?><div class="ai-message"><div class="ai-message-head"><span class="ai-badge <?= ($m->role ?? '')==='customer'?'info':(($m->role ?? '')==='influencer'?'warn':'muted') ?>"><?= $h(($m->role ?? '')==='customer'?'تبلیغ‌دهنده':(($m->role ?? '')==='influencer'?'اینفلوئنسر':'مدیر')) ?></span><strong><?= $h($m->sender_name ?? '—') ?></strong><small><?= $h(substr((string)($m->created_at ?? ''),0,16)) ?></small></div><div><?= nl2br($h($m->message ?? '')) ?></div><?php if(!empty($m->attachment)): ?><a class="ai-btn" target="_blank" href="<?= $h($m->attachment) ?>">پیوست</a><?php endif; ?></div><?php endforeach; ?><?php endif; ?></div>
      <?php if(!$isResolved): ?><div class="ai-verdict"><h2><span class="material-icons">gavel</span> صدور رأی مالی</h2><div class="ai-form"><div class="ai-radio-grid"><label class="ai-radio"><input type="radio" name="verdict" value="favor_influencer">به نفع اینفلوئنسر<br><small>آزادسازی امانت و پرداخت سهم</small></label><label class="ai-radio"><input type="radio" name="verdict" value="favor_customer">به نفع تبلیغ‌دهنده<br><small>بازگشت کامل مبلغ سفارش</small></label><label class="ai-radio"><input type="radio" name="verdict" value="partial">تسویه جزئی<br><small>بخشی بازگشت، بخشی پرداخت</small></label></div><div id="partialGroup" style="display:none"><label>درصد بازگشت به تبلیغ‌دهنده</label><input type="number" id="refundPercent" min="0" max="100" value="50"></div><label>توضیح رأی</label><textarea id="verdictNote" rows="4" placeholder="دلیل رأی را دقیق و قابل پیگیری بنویسید..."></textarea><button type="button" class="ai-btn danger" onclick="submitVerdict()"><span class="material-icons">gavel</span> صدور رأی نهایی</button></div></div><?php else: ?><div class="ai-verdict"><span class="ai-badge ok">این پرونده تعیین تکلیف شده است.</span></div><?php endif; ?></main>
  </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininfluencer.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/influencer.js') . '"></script>';
include view_path('layouts.admin');
?>
