<?php
$title = 'پنل اختلاف — سفارش #' . e($order->id ?? '');
ob_start();
$disputeStatus = $dispute->status ?? null;
$statusLabels  = ['open_peer'=>'گفت‌وگوی طرفین','resolved_peer'=>'حل دوستانه','escalated'=>'ارجاع به مدیر','resolved_admin'=>'رأی مدیر صادر شد','closed'=>'بسته شده'];
$isClosed = in_array($disputeStatus, ['resolved_peer','resolved_admin','closed']);
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <span class="material-icons text-warning">gavel</span>
    پنل اختلاف — سفارش #<?= e($order->id ?? '') ?>
  </h4>
  <a href="<?= url($role==='influencer' ? '/influencer/orders' : '/influencer/ads/my-orders') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a>
</div>

<!-- خلاصه سفارش -->
<div class="card mt-3">
  <div class="card-body">
    <div class="row g-2 small">
      <div class="col-md-3">
        <span class="text-muted">نوع سفارش:</span>
        <strong><?= $order->order_type === 'story' ? 'استوری' : 'پست' ?></strong>
      </div>
      <div class="col-md-3">
        <span class="text-muted">مبلغ:</span>
        <strong class="text-success"><?= number_format($order->price ?? 0) ?></strong>
      </div>
      <div class="col-md-3">
        <span class="text-muted">وضعیت اختلاف:</span>
        <span class="badge bg-warning text-dark"><?= $statusLabels[$disputeStatus] ?? '—' ?></span>
      </div>
      <div class="col-md-3">
        <?php if (!empty($order->proof_link)): ?>
          <a href="<?= e($order->proof_link) ?>" target="_blank" class="btn btn-outline-info btn-sm">
            <span class="material-icons icon-xs">open_in_new</span>
            مشاهده مدرک
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- پیام‌ها -->
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">گفت‌وگوی طرفین</h6>
  </div>
  <div class="card-body p-3 messages-box" id="messagesBox">
    <?php if (empty($messages)): ?>
      <p class="text-muted text-center small">هنوز پیامی ارسال نشده است.</p>
    <?php else: ?>
      <?php foreach ($messages as $m):
        $isMine = (int)$m->user_id === (int)$userId;
        $roleLabel = $m->role === 'admin' ? 'مدیر' : ($m->role === 'customer' ? 'تبلیغ‌دهنده' : 'اینفلوئنسر');
      ?>
      <div class="d-flex <?= $isMine ? 'justify-content-end' : 'justify-content-start' ?> mb-2">
        <div class="rounded p-2 message-bubble <?= $isMine ? 'bg-primary text-white' : 'bg-light' ?>">
          <div class="small fw-bold mb-1 <?= $isMine ? 'text-white-50' : 'text-muted' ?>">
            <?= e($m->sender_name ?? $roleLabel) ?>
          </div>
          <div class="message-text"><?= e($m->message) ?></div>
          <?php if (!empty($m->attachment)): ?>
            <div class="mt-1">
              <a href="<?= e($m->attachment) ?>" target="_blank" class="small <?= $isMine ? 'text-white' : 'text-primary' ?>">
                <span class="material-icons icon-xs">attach_file</span>
                فایل پیوست
              </a>
            </div>
          <?php endif; ?>
          <div class="small mt-1 <?= $isMine ? 'text-white-50' : 'text-muted' ?> message-time">
            <?= e(substr($m->created_at ?? '', 0, 16)) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ارسال پیام -->
  <?php if (!$isClosed && $dispute): ?>
  <div class="card-footer">
    <div class="d-flex gap-2 align-items-end">
      <textarea id="msgText" class="form-control form-control-sm" rows="2" placeholder="پیام خود را بنویسید..."></textarea>
      <div class="d-flex flex-column gap-1">
        <label class="btn btn-outline-secondary btn-sm mb-0" title="پیوست فایل">
          <span class="material-icons icon-sm">attach_file</span>
          <input type="file" id="msgAttachment" class="d-none" accept="image/*,.pdf">
        </label>
        <button class="btn btn-primary btn-sm" data-action="send-dispute-msg">
          <span class="material-icons icon-sm">send</span>
        </button>
      </div>
    </div>
    <div id="attachName" class="small text-muted mt-1"></div>
  </div>
  <?php endif; ?>
</div>

<!-- دکمه‌های عملیات -->
<?php if (!$isClosed && $dispute && $disputeStatus === 'open_peer'): ?>
<div class="card mt-3">
  <div class="card-body">
    <div class="row g-2">
      <!-- توافق دوطرفه -->
      <?php if ($role === 'influencer'): ?>
      <div class="col-md-6">
        <div class="card border-success h-100">
          <div class="card-body text-center py-3">
            <span class="material-icons text-success icon-xl">handshake</span>
            <h6 class="mt-1">پیشنهاز توافق</h6>
            <p class="small text-muted mb-2">اگر با تبلیغ‌دهنده به توافق رسیدید اینجا ثبت کنید.</p>
            <button class="btn btn-success btn-sm" data-action="open-agreement-modal">ثبت توافق</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <!-- ارجاع به مدیر -->
      <div class="col-md-<?= $role === 'influencer' ? '6' : '12' ?>">
        <div class="card border-danger h-100">
          <div class="card-body text-center py-3">
            <span class="material-icons text-danger icon-xl">escalator_warning</span>
            <h6 class="mt-1">ارجاع به مدیر</h6>
            <p class="small text-muted mb-2">اگر نتوانستید توافق کنید پرونده را به مدیر بسپارید.</p>
            <button class="btn btn-outline-danger btn-sm" data-action="escalate-dispute">ارجاع به مدیر</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($disputeStatus === 'escalated'): ?>
<div class="alert alert-info mt-3">
  <span class="material-icons icon-sm">hourglass_top</span>
  پرونده به مدیر ارجاع داده شده. منتظر رأی مدیر باشید.
</div>
<?php elseif (in_array($disputeStatus, ['resolved_peer','resolved_admin'])): ?>
<div class="alert alert-success mt-3">
  <span class="material-icons icon-sm">check_circle</span>
  اختلاف حل شد.
  <?php if (!empty($dispute->resolution_note)): ?>
    <div class="mt-1 small"><?= e($dispute->resolution_note) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Modal توافق -->
<div class="modal fade" id="agreementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ثبت توافق</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">نتیجه توافق</label>
          <select id="verdictSelect" class="form-select">
            <option value="favor_influencer">سفارش انجام شده — پرداخت به اینفلوئنسر</option>
            <option value="favor_customer">سفارش انجام نشده — بازگشت وجه</option>
            <option value="partial">تسویه جزئی (۵۰٪)</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">توضیح توافق</label>
          <textarea id="agreementNote" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-success" data-action="submit-agreement">ثبت توافق</button>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userinfluencerdisputepanel.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userinfluencerdisputepanel.js') . '"></script>';
include view_path('layouts.user');
?>
