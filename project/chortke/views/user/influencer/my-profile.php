<?php
$title = 'اینفلوئنسر';
$bodyClass = trim((string)($bodyClass ?? '') . ' influencer-hub-page');
$profile = $profile ?? null;
$orders = $orders ?? [];
$placedOrders = $placedOrders ?? [];
$marketProfiles = $marketProfiles ?? [];
$marketStatsMap = $marketStatsMap ?? [];
$disputes = $disputes ?? [];
$statusLabels = $statusLabels ?? [];
$statusClasses = $statusClasses ?? [];
$incomingNeedsAction = (int)($incomingNeedsAction ?? 0);
$placedNeedsCheck = (int)($placedNeedsCheck ?? 0);
$verificationState = $verificationStatus['status'] ?? null;
$profileStatus = (($profile->status ?? '') === 'verified' || $verificationState === 'approved') ? 'verified' : ($verificationState ?: ($profile->status ?? 'not_registered'));
$displayCode = $verificationCode ?? ($profile->verification_code ?? '—');
$initialSection = in_array(($_GET['section'] ?? ''), ['profile','incoming','market','placed','disputes'], true) ? $_GET['section'] : 'overview';
$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v): string => number_format((float)($v ?? 0));
$int = static fn($v): string => function_exists('fa_number') ? fa_number((int)$v) : number_format((int)$v);
$platformLabel = static fn($p): string => match((string)$p) { 'instagram'=>'اینستاگرام', 'telegram'=>'تلگرام', default => (string)$p };
$statusMeta = static function(string $status): array {
    return match($status) {
        'pending', 'pending_acceptance', 'paid' => ['در انتظار پاسخ', 'inf-badge-warn', 'hourglass_top'],
        'accepted' => ['پذیرفته شده', 'inf-badge-info', 'task_alt'],
        'awaiting_buyer_check', 'proof_submitted' => ['در انتظار بررسی تبلیغ‌دهنده', 'inf-badge-warn', 'fact_check'],
        'completed' => ['تکمیل‌شده', 'inf-badge-ok', 'paid'],
        'refunded', 'rejected_by_influencer' => ['بازگشت وجه/رد', 'inf-badge-danger', 'keyboard_return'],
        'dispute', 'peer_resolution', 'escalated_to_admin' => ['اختلاف', 'inf-badge-danger', 'gavel'],
        default => [$status ?: 'نامشخص', 'inf-badge-muted', 'help'],
    };
};
ob_start();
?>
<div class="inf-wrap" id="influencerHub"
     data-store-url="<?= e(url('/influencer/ads/store')) ?>"
     data-base="<?= e(url('/influencer')) ?>"
     data-initial-section="<?= e($initialSection) ?>">

  <section class="inf-hero">
    <div>
      <div class="inf-kicker"><span class="material-icons">auto_awesome</span> بازار اختصاصی اینفلوئنسر</div>
      <h1>مرکز اینفلوئنسر</h1>
      <p>پیج خود را ثبت کنید، سفارش بگیرید، تبلیغات اینفلوئنسری سفارش دهید و همه چیز را از همین صفحه مدیریت کنید.</p>
    </div>
    <div class="inf-hero-side">
      <button type="button" class="inf-btn inf-btn-primary" data-inf-tab="market"><span class="material-icons">campaign</span> سفارش تبلیغ</button>
      <button type="button" class="inf-btn inf-btn-ghost" data-inf-tab="incoming"><span class="material-icons">inbox</span> سفارش‌های دریافتی</button>
    </div>
  </section>

  <nav class="inf-tabs" aria-label="بخش‌های اینفلوئنسر">
    <button type="button" class="inf-tab" data-inf-tab="overview"><span class="material-icons">dashboard</span><strong>داشبورد</strong></button>
    <button type="button" class="inf-tab" data-inf-tab="profile"><span class="material-icons">verified_user</span><strong>پیج من</strong></button>
    <button type="button" class="inf-tab" data-inf-tab="incoming"><span class="material-icons">move_to_inbox</span><strong>سفارش‌های دریافتی</strong><?php if($incomingNeedsAction>0): ?><em><?= $int($incomingNeedsAction) ?></em><?php endif; ?></button>
    <button type="button" class="inf-tab" data-inf-tab="market"><span class="material-icons">travel_explore</span><strong>سفارش تبلیغ</strong></button>
    <button type="button" class="inf-tab" data-inf-tab="placed"><span class="material-icons">receipt_long</span><strong>سفارش‌های من</strong><?php if($placedNeedsCheck>0): ?><em><?= $int($placedNeedsCheck) ?></em><?php endif; ?></button>
    <button type="button" class="inf-tab" data-inf-tab="disputes"><span class="material-icons">gavel</span><strong>اختلاف‌ها</strong></button>
  </nav>

  <section class="inf-panel" data-inf-panel="overview">
    <div class="inf-stats">
      <article><span class="material-icons">assignment</span><small>سفارش‌های دریافتی</small><strong><?= $int(count($orders)) ?></strong></article>
      <article><span class="material-icons">notifications_active</span><small>نیازمند پاسخ</small><strong><?= $int($incomingNeedsAction) ?></strong></article>
      <article><span class="material-icons">shopping_bag</span><small>سفارش‌های ثبت‌شده من</small><strong><?= $int(count($placedOrders)) ?></strong></article>
      <article><span class="material-icons">verified</span><small>وضعیت پیج</small><strong><?= $profile ? $h($profileStatus === 'verified' ? 'تأیید شده' : 'در حال بررسی') : 'ثبت نشده' ?></strong></article>
    </div>
    <div class="inf-grid-2 mt-3">
      <div class="inf-card">
        <div class="inf-card-head"><h2><span class="material-icons">route</span> مسیر امن سفارش</h2></div>
        <ol class="inf-steps">
          <li>مبلغ سفارش در صندوق امانی نگه داشته می‌شود.</li>
          <li>اینفلوئنسر سفارش را قبول و مدرک انتشار را ثبت می‌کند.</li>
          <li>تبلیغ‌دهنده تأیید می‌کند یا اختلاف ثبت می‌شود.</li>
          <li>پس از تأیید، سهم اینفلوئنسر پرداخت و سهم سایت جدا می‌شود.</li>
        </ol>
      </div>
      <div class="inf-card">
        <div class="inf-card-head"><h2><span class="material-icons">photo_camera</span> تأیید پیج با اسکرین‌شات</h2></div>
        <p class="inf-muted">برای تأیید مالکیت پیج، کد اختصاصی را در پست/استوری قرار دهید و اسکرین‌شات واضح بارگذاری کنید. اگر سیستم با اطمینان تشخیص ندهد، درخواست به مدیر ارجاع می‌شود.</p>
      </div>
    </div>
  </section>

  <section class="inf-panel" data-inf-panel="profile">
    <?php if (!$profile): ?>
      <div class="inf-card">
        <div class="inf-card-head"><h2><span class="material-icons">add_circle</span> ثبت پیج اینفلوئنسر</h2><p>پیج خود را ثبت کنید تا بعد از تأیید بتوانید سفارش بگیرید.</p></div>
        <form action="<?= e(url('/influencer/register')) ?>" method="POST" enctype="multipart/form-data" class="inf-form">
          <?= csrf_field() ?>
          <div class="inf-form-grid">
            <label><span>پلتفرم</span><select name="platform"><option value="instagram">اینستاگرام</option><option value="telegram">تلگرام</option></select></label>
            <label><span>نام کاربری</span><input type="text" name="username" required placeholder="مثلاً chortke_media"></label>
            <label><span>تعداد فالوور/عضو</span><input type="number" name="follower_count" min="0" value="0"></label>
            <label><span>لینک صفحه</span><input type="url" name="page_url" placeholder="https://..."></label>
            <label><span>دسته‌بندی</span><select name="category"><option value="">انتخاب کنید</option><?php foreach(($categories ?? []) as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?></select></label>
            <label><span>تصویر پروفایل</span><input type="file" name="profile_image" accept="image/*"></label>
          </div>
          <div class="inf-form-grid prices">
            <label><span>استوری ۲۴ ساعته</span><input type="number" name="story_price_24h" min="0" step="1000"></label>
            <label><span>پست ۲۴ ساعته</span><input type="number" name="post_price_24h" min="0" step="1000"></label>
            <label><span>پست ۴۸ ساعته</span><input type="number" name="post_price_48h" min="0" step="1000"></label>
            <label><span>پست ۷۲ ساعته</span><input type="number" name="post_price_72h" min="0" step="1000"></label>
          </div>
          <label class="wide"><span>توضیحات کوتاه</span><textarea name="bio" rows="3" placeholder="موضوع پیج، مخاطب‌ها و شرایط تبلیغ را بنویسید..."></textarea></label>
          <button type="submit" class="inf-btn inf-btn-primary"><span class="material-icons">save</span> ثبت پیج</button>
        </form>
      </div>
    <?php else: ?>
      <?php $pStatus = $profileStatus; ?>
      <div class="inf-card">
        <div class="inf-profile-head">
          <div class="inf-avatar"><?= e(mb_strtoupper(mb_substr($profile->username ?? 'I', 0, 1))) ?></div>
          <div><h2>@<?= $h($profile->username) ?></h2><p><?= $h($platformLabel($profile->platform)) ?> · <?= $int($profile->follower_count ?? $profile->followers_count ?? 0) ?> دنبال‌کننده</p></div>
          <span class="inf-badge <?= $pStatus === 'verified' ? 'inf-badge-ok' : 'inf-badge-warn' ?>"><span class="material-icons"><?= $pStatus === 'verified' ? 'verified' : 'hourglass_top' ?></span><?= $pStatus === 'verified' ? 'تأیید شده' : 'نیازمند تأیید' ?></span>
        </div>
        <?php if ($pStatus === 'pending' || $pStatus === 'expired'): ?>
          <div class="inf-verify-box">
            <h3><span class="material-icons">photo_camera</span> تأیید مالکیت با اسکرین‌شات</h3>
            <p>کد زیر را در پست/استوری قرار دهید، از آن اسکرین‌شات بگیرید و همین‌جا ارسال کنید.</p>
            <div class="inf-code"><strong><?= $h($displayCode) ?></strong><button type="button" data-action="copy-code" data-code="<?= $h($displayCode) ?>"><span class="material-icons">content_copy</span></button></div>
            <form action="<?= e(url('/influencer/verify')) ?>" method="POST" enctype="multipart/form-data" class="inf-form compact">
              <?= csrf_field() ?>
              <label><span>لینک پست/استوری</span><input type="url" name="post_url" required placeholder="https://www.instagram.com/p/..."></label>
              <label><span>کد قابل مشاهده در تصویر</span><input type="text" name="visible_code" maxlength="20" placeholder="<?= $h($displayCode) ?>"></label>
              <label><span>اسکرین‌شات</span><input type="file" name="verification_screenshot" accept="image/jpeg,image/png,image/webp"></label>
              <button type="submit" class="inf-btn inf-btn-primary">ارسال برای تأیید</button>
            </form>
          </div>
        <?php elseif (in_array($pStatus, ['submitted','pending_admin_review'], true)): ?>
          <div class="inf-note warn"><span class="material-icons">manage_search</span><div><strong>در انتظار بررسی مدیر</strong><p>سیستم نتوانسته تأیید خودکار قطعی انجام دهد یا مدرک برای بررسی انسانی ارسال شده است.</p></div></div>
        <?php elseif ($pStatus === 'rejected'): ?>
          <div class="inf-note danger"><span class="material-icons">cancel</span><div><strong>پیج رد شده است</strong><p><?= $h($profile->rejection_reason ?? 'مطابق شرایط نیست') ?></p><a href="<?= e(url('/influencer/register')) ?>" class="inf-btn inf-btn-ghost">ویرایش پروفایل</a></div></div>
        <?php else: ?>
          <div class="inf-rate-grid">
            <div><small>استوری ۲۴ ساعته</small><strong><?= $money($profile->story_price_24h ?? 0) ?></strong></div>
            <div><small>پست ۲۴ ساعته</small><strong><?= $money($profile->post_price_24h ?? 0) ?></strong></div>
            <div><small>پست ۴۸ ساعته</small><strong><?= $money($profile->post_price_48h ?? 0) ?></strong></div>
            <div><small>پست ۷۲ ساعته</small><strong><?= $money($profile->post_price_72h ?? 0) ?></strong></div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="inf-panel" data-inf-panel="incoming">
    <div class="inf-card"><div class="inf-card-head"><h2><span class="material-icons">move_to_inbox</span> سفارش‌های دریافتی</h2><p>سفارش‌های جدید را قبول یا رد کنید؛ بعد از انجام، مدرک انتشار را ثبت کنید.</p></div>
      <?php if (empty($orders)): ?><div class="inf-empty"><span class="material-icons">inbox</span><p>فعلاً سفارشی برای شما ثبت نشده است.</p></div><?php else: ?>
      <div class="inf-list">
        <?php foreach($orders as $o): $m=$statusMeta((string)$o->status); ?>
          <article class="inf-row"><div><h3>#<?= $int($o->id) ?> · <?= $h($o->customer_name ?? 'تبلیغ‌دهنده') ?></h3><p><?= $h($o->caption ?? '') ?></p></div><span class="inf-badge <?= e($m[1]) ?>"><span class="material-icons"><?= e($m[2]) ?></span><?= e($m[0]) ?></span><strong><?= $money($o->influencer_earning ?? 0) ?></strong><div class="inf-actions"><?php if(in_array((string)$o->status,['pending','paid','pending_acceptance'],true)): ?><button class="inf-mini ok" data-action="respond-order" data-order-id="<?= (int)$o->id ?>" data-action-type="accept">قبول</button><button class="inf-mini danger" data-action="prompt-reject" data-order-id="<?= (int)$o->id ?>">رد</button><?php elseif((string)$o->status==='accepted'): ?><button class="inf-mini" data-action="open-proof-modal" data-order-id="<?= (int)$o->id ?>">ثبت مدرک</button><?php endif; ?></div></article>
        <?php endforeach; ?>
      </div><?php endif; ?></div>
  </section>

  <section class="inf-panel" data-inf-panel="market">
    <div class="inf-market-layout">
      <div class="inf-card"><div class="inf-card-head"><h2><span class="material-icons">travel_explore</span> انتخاب اینفلوئنسر</h2><p>از بین پیج‌های تأییدشده انتخاب کنید و سفارش را در صندوق امانی ثبت کنید.</p></div>
        <div class="inf-market-grid">
          <?php foreach($marketProfiles as $mp): $ms=$marketStatsMap[(int)$mp->id] ?? null; ?>
            <article class="inf-profile-card"><div class="inf-avatar small"><?= e(mb_strtoupper(mb_substr($mp->username ?? 'I',0,1))) ?></div><h3>@<?= $h($mp->username) ?></h3><p><?= $h($platformLabel($mp->platform)) ?> · <?= $int($mp->follower_count ?? 0) ?> دنبال‌کننده</p><div class="inf-rate-grid mini"><div><small>استوری</small><strong><?= $money($mp->story_price_24h ?? 0) ?></strong></div><div><small>رتبه</small><strong><?= $h($ms->grade ?? '—') ?></strong></div></div><button type="button" class="inf-btn inf-btn-primary w-100" data-select-influencer data-id="<?= (int)$mp->id ?>" data-username="<?= $h($mp->username) ?>" data-story="<?= (float)($mp->story_price_24h ?? 0) ?>" data-post24="<?= (float)($mp->post_price_24h ?? 0) ?>" data-post48="<?= (float)($mp->post_price_48h ?? 0) ?>" data-post72="<?= (float)($mp->post_price_72h ?? 0) ?>">ثبت سفارش</button></article>
          <?php endforeach; ?>
        </div>
      </div>
      <aside class="inf-card inf-order-box"><div class="inf-card-head"><h2><span class="material-icons">edit_note</span> فرم سفارش</h2><p id="selectedInfluencerText">یک اینفلوئنسر انتخاب کنید.</p></div>
        <form id="hubOrderForm" class="inf-form" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="influencer_id" id="hubInfluencerId">
          <label><span>نوع تبلیغ</span><select name="order_type" id="hubOrderType"><option value="story">استوری</option><option value="post">پست</option></select></label>
          <label><span>مدت</span><select name="duration_hours" id="hubDuration"><option value="24">۲۴ ساعت</option><option value="48">۴۸ ساعت</option><option value="72">۷۲ ساعت</option></select></label>
          <label><span>بریف تبلیغ</span><textarea name="caption" rows="4" required placeholder="متن، لینک، هشتگ و توضیحات لازم..."></textarea></label>
          <label><span>لینک</span><input type="url" name="link" placeholder="https://..."></label>
          <div class="inf-price-preview">مبلغ سفارش: <strong id="hubPricePreview">—</strong></div>
          <button type="submit" class="inf-btn inf-btn-primary w-100"><span class="material-icons">lock</span> ثبت و نگهداری امن مبلغ</button>
        </form>
      </aside>
    </div>
  </section>

  <section class="inf-panel" data-inf-panel="placed">
    <div class="inf-card"><div class="inf-card-head"><h2><span class="material-icons">receipt_long</span> سفارش‌های من</h2><p>سفارش‌هایی که به اینفلوئنسرها داده‌اید.</p></div>
      <?php if(empty($placedOrders)): ?><div class="inf-empty"><span class="material-icons">shopping_bag</span><p>هنوز سفارشی ثبت نکرده‌اید.</p></div><?php else: ?><div class="inf-list"><?php foreach($placedOrders as $o): $m=$statusMeta((string)$o->status); ?><article class="inf-row"><div><h3>#<?= $int($o->id) ?> · @<?= $h($o->influencer_username ?? '') ?></h3><p><?= $h($o->caption ?? '') ?></p></div><span class="inf-badge <?= e($m[1]) ?>"><span class="material-icons"><?= e($m[2]) ?></span><?= e($m[0]) ?></span><strong><?= $money($o->price ?? 0) ?></strong><div class="inf-actions"><?php if(in_array((string)$o->status,['awaiting_buyer_check','proof_submitted'],true)): ?><button class="inf-mini ok" data-action="confirm-order" data-order-id="<?= (int)$o->id ?>">تأیید</button><button class="inf-mini danger" data-action="open-dispute-modal" data-order-id="<?= (int)$o->id ?>">اعتراض</button><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></div>
  </section>

  <section class="inf-panel" data-inf-panel="disputes">
    <div class="inf-card"><div class="inf-card-head"><h2><span class="material-icons">gavel</span> اختلاف‌ها</h2><p>گفت‌وگوها و پرونده‌های اختلاف اینفلوئنسر.</p></div>
      <?php if(empty($disputes)): ?><div class="inf-empty"><span class="material-icons">verified</span><p>پرونده اختلاف فعالی ندارید.</p></div><?php else: ?><div class="inf-list"><?php foreach($disputes as $d): ?><article class="inf-row"><div><h3>پرونده #<?= $int($d->id) ?></h3><p><?= $h($d->reason ?? '') ?></p></div><span class="inf-badge inf-badge-danger"><span class="material-icons">gavel</span><?= $h($d->status ?? '') ?></span><a class="inf-mini" href="<?= e(url('/influencer/orders/' . (int)$d->ref_id . '/dispute')) ?>">مشاهده</a></article><?php endforeach; ?></div><?php endif; ?></div>
  </section>

  <div class="modal fade" id="proofModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="proofForm" enctype="multipart/form-data"><div class="modal-header"><h5 class="modal-title">ثبت مدرک انتشار</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?= csrf_field() ?><input type="hidden" id="proofOrderId"><label class="form-label">لینک پست/استوری</label><input type="url" name="proof_link" class="form-control" required><label class="form-label mt-2">اسکرین‌شات</label><input type="file" name="proof_screenshot" class="form-control" accept="image/*"><label class="form-label mt-2">توضیحات</label><textarea name="proof_notes" class="form-control" rows="2"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" id="proofSubmitBtn" class="btn btn-primary">ثبت مدرک</button></div></form></div></div></div>
  <div class="modal fade" id="rejectModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">رد سفارش</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><textarea id="rejectReason" class="form-control" rows="3" placeholder="دلیل رد سفارش..."></textarea></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-danger" id="rejectConfirmBtn">رد سفارش</button></div></div></div></div>
  <div class="modal fade" id="disputeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">ثبت اعتراض</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><textarea id="disputeReason" class="form-control" rows="3" placeholder="دلیل اعتراض..."></textarea></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-danger" id="disputeSubmitBtn">ثبت اعتراض</button></div></div></div></div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userinfluencerhub.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userinfluencerhub.js') . '"></script>';
include view_path('layouts.user');
?>
