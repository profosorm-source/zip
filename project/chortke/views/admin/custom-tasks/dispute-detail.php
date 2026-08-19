<?php
$title = 'جزئیات اختلاف تسک سفارشی';
$statusLabels = [
    'open' => 'باز', 'open_peer' => 'باز', 'under_review' => 'در حال بررسی', 'escalated' => 'ارجاع به ادمین',
    'resolved_for_executor' => 'حل به نفع مجری', 'resolved_for_advertiser' => 'حل به نفع تبلیغ‌دهنده', 'resolved_admin' => 'حل‌شده', 'closed' => 'بسته'
];
$closed = in_array((string)$dispute->status, ['resolved_for_executor','resolved_for_advertiser','resolved_admin','closed'], true);
ob_start();
?>
<div id="customTasksRoot" data-resolve-url="<?= url('/admin/custom-tasks/disputes/resolve') ?>"></div>

<div class="content-header d-flex justify-content-between align-items-center">
  <div><h4 class="page-title mb-1"><i class="material-icons text-danger">gavel</i> اختلاف #<?= (int)$dispute->id ?></h4><p class="text-muted mb-0">پرونده تسک: <?= e($dispute->task_title ?? '—') ?></p></div>
  <a href="<?= url('/admin/custom-tasks/disputes') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="row mt-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <h5 class="fw-bold mb-3">اطلاعات پرونده</h5>
      <div class="d-flex justify-content-between border-bottom py-2"><span>وضعیت</span><span class="badge bg-<?= $closed ? 'success' : 'warning' ?>"><?= e($statusLabels[$dispute->status] ?? $dispute->status) ?></span></div>
      <div class="d-flex justify-content-between border-bottom py-2"><span>مجری</span><strong><?= e($dispute->worker_name ?? '—') ?></strong></div>
      <div class="d-flex justify-content-between border-bottom py-2"><span>تبلیغ‌دهنده</span><strong><?= e($dispute->advertiser_name ?? '—') ?></strong></div>
      <div class="d-flex justify-content-between border-bottom py-2"><span>پاداش</span><strong><?= number_format((float)($dispute->reward_amount ?? 0)) ?> <?= e($dispute->reward_currency ?? 'irt') ?></strong></div>
      <div class="mt-3"><small class="text-muted">دلیل اختلاف</small><div class="alert alert-warning mt-1 mb-0"><?= nl2br(e($dispute->reason ?? '')) ?></div></div>
      <?php if (!empty($dispute->rejection_reason)): ?><div class="mt-3"><small class="text-muted">دلیل رد اولیه</small><div class="alert alert-danger mt-1 mb-0"><?= nl2br(e($dispute->rejection_reason)) ?></div></div><?php endif; ?>
    </div></div>

    <?php if (!$closed): ?>
    <div class="card"><div class="card-body">
      <h5 class="fw-bold mb-3">داوری سریع</h5>
      <button class="btn btn-success w-100 mb-2 btn-resolve" data-id="<?= (int)$dispute->id ?>" data-reason="<?= e($dispute->reason ?? '') ?>">حل به نفع مجری و پرداخت</button>
      <button class="btn btn-warning w-100 mb-2 btn-resolve" data-id="<?= (int)$dispute->id ?>" data-reason="<?= e($dispute->reason ?? '') ?>">حل میانه / پرداخت درصدی</button>
      <button class="btn btn-outline-danger w-100 btn-resolve-advertiser" data-id="<?= (int)$dispute->id ?>">حل به نفع تبلیغ‌دهنده</button>
      <small class="text-muted d-block mt-2">برای حل به نفع تبلیغ‌دهنده از لیست یا فرم JSON نیز پشتیبانی می‌شود.</small>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="card"><div class="card-body">
      <h5 class="fw-bold mb-3"><i class="material-icons align-middle">forum</i> گفت‌وگوی پرونده</h5>
      <?php if (empty($messages)): ?>
        <div class="text-center text-muted py-4">پیامی ثبت نشده است.</div>
      <?php else: ?>
        <?php foreach ($messages as $msg): ?>
          <div class="p-3 mb-2 rounded border <?= ($msg->role ?? '') === 'admin' ? 'bg-light' : '' ?>">
            <div class="d-flex justify-content-between"><strong><?= e(($msg->role ?? '') === 'admin' ? 'ادمین' : ($msg->sender_name ?? 'کاربر')) ?></strong><small class="text-muted"><?= e(substr((string)$msg->created_at, 0, 16)) ?></small></div>
            <div class="mt-2"><?= nl2br(e($msg->message)) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!$closed): ?>
      <form method="POST" action="<?= url('/admin/custom-tasks/disputes/' . (int)$dispute->id . '/reply') ?>" class="mt-3">
        <?= csrf_field() ?>
        <label class="form-label">پیام ادمین</label>
        <textarea name="message" class="form-control" rows="3" placeholder="پیام یا توضیح تکمیلی برای طرفین..."></textarea>
        <button class="btn btn-primary mt-2" type="submit">ارسال پیام</button>
      </form>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<script nonce="<?= e($cspNonce ?? '') ?>">
document.addEventListener('click', function(e){
  var btn = e.target.closest('.btn-resolve-advertiser');
  if(!btn) return;
  e.preventDefault();
  fetch('<?= url('/admin/custom-tasks/disputes/resolve') ?>', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':window.csrfToken||''}, body:JSON.stringify({dispute_id:btn.dataset.id,decision:'advertiser',admin_note:'رأی به نفع تبلیغ‌دهنده ثبت شد.'})})
    .then(r=>r.json()).then(()=>location.reload());
});
</script>
<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/customtasks.js') . '"></script>';
include view_path('layouts.admin');
?>
