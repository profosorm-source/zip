<?php
$title = 'سفارش‌های دریافتی';
ob_start();
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <span class="material-icons text-primary">pending_actions</span> سفارش‌های دریافتی
  </h4>
  <a href="<?= url('/influencer') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a>
</div>

<?php if (empty($orders)): ?>
  <div class="card mt-4">
    <div class="card-body text-center py-5">
      <span class="material-icons text-muted icon-lg">inbox</span>
      <h6 class="mt-2 text-muted">سفارشی دریافت نکرده‌اید.</h6>
    </div>
  </div>
<?php else: ?>

<?php foreach ($orders as $o):
  $sl = $statusLabels ?? [];
  $sc = $statusClasses ?? [];
  $badgeMap = ['badge-success'=>'bg-success','badge-primary'=>'bg-primary','badge-warning'=>'bg-warning text-dark','badge-info'=>'bg-info','badge-danger'=>'bg-danger','badge-secondary'=>'bg-secondary','badge-orange'=>'bg-warning'];
  $badgeCls = $badgeMap[$sc[$o->status] ?? 'badge-secondary'] ?? 'bg-secondary';
?>
<div class="card mt-2">
  <div class="card-body">
    <div class="row align-items-center g-2">
      <!-- شناسه و اطلاعات -->
      <div class="col-md-4">
        <div class="d-flex align-items-center gap-2">
          <div class="text-muted small">#<?= e($o->id) ?></div>
          <span class="badge <?= $badgeCls ?>"><?= e($sl[$o->status] ?? $o->status) ?></span>
        </div>
        <div class="small mt-1">
          <strong><?= $o->order_type === 'story' ? 'استوری' : 'پست' ?></strong>
          · <?= $o->duration_hours ?? 24 ?> ساعت
          · <span class="text-success fw-bold"><?= number_format($o->influencer_earning ?? 0) ?></span>
        </div>
        <div class="text-muted text-11"><?= e(substr($o->created_at ?? '', 0, 16)) ?></div>
      </div>

      <!-- اطلاعات سفارش -->
      <div class="col-md-4 small text-muted">
        <?php if (!empty($o->caption)): ?>
          <div class="text-truncate"><?= e($o->caption) ?></div>
        <?php endif; ?>
        <?php if (!empty($o->link)): ?>
          <a href="<?= e($o->link) ?>" target="_blank" class="d-block text-truncate">
            <span class="material-icons icon-xs">link</span>
            <?= e($o->link) ?>
          </a>
        <?php endif; ?>
        <?php if (!empty($o->preferred_publish_time)): ?>
          <div>زمان مطلوب: <?= e($o->preferred_publish_time) ?></div>
        <?php endif; ?>
      </div>

      <!-- دکمه‌های عملیات -->
      <div class="col-md-4 d-flex gap-1 flex-wrap justify-content-md-end">
        <?php if ($o->status === 'paid'): ?>
          <button class="btn btn-success btn-sm" data-action="respond-order" data-order-id="<?= $o->id ?>" data-action-type="accept">قبول</button>
          <button class="btn btn-outline-danger btn-sm" data-action="prompt-reject" data-order-id="<?= $o->id ?>">رد</button>

        <?php elseif ($o->status === 'accepted'): ?>
          <button class="btn btn-primary btn-sm" data-action="open-proof-modal" data-order-id="<?= $o->id ?>">
            <span class="material-icons icon-sm">upload</span>
            ثبت مدرک انتشار
          </button>

        <?php elseif ($o->status === 'awaiting_buyer_check'): ?>
          <span class="text-muted small">
            <span class="material-icons text-warning icon-sm">hourglass_empty</span>
            در انتظار تایید خریدار
          </span>

        <?php elseif (in_array($o->status, ['peer_resolution','escalated_to_admin'])): ?>
          <a href="<?= url('/influencer/orders/' . $o->id . '/dispute') ?>" class="btn btn-warning btn-sm text-white">
            <span class="material-icons icon-sm">gavel</span>
            پنل اختلاف
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- نمایش proof اگر داشت -->
    <?php if (!empty($o->proof_link) || !empty($o->proof_screenshot)): ?>
    <div class="mt-2 pt-2 border-top small">
      <span class="text-muted">مدرک:</span>
      <?php if (!empty($o->proof_link)): ?>
        <a href="<?= e($o->proof_link) ?>" target="_blank" class="ms-1">
          <span class="material-icons icon-xs">link</span>
          مشاهده لینک
        </a>
      <?php endif; ?>
      <?php if (!empty($o->proof_screenshot)): ?>
        <a href="<?= e($o->proof_screenshot) ?>" target="_blank" class="ms-2">
          <span class="material-icons icon-xs">image</span>
          تصویر
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (($page ?? 1) > 1 || count($orders) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if (($page ?? 1) > 1): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page ?? 1)-1 ?>">قبلی</a></li><?php endif; ?>
    <li class="page-item active"><span class="page-link"><?= $page ?? 1 ?></span></li>
    <?php if (count($orders) >= 20): ?><li class="page-item"><a class="page-link" href="?page=<?= ($page ?? 1)+1 ?>">بعدی</a></li><?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal: ثبت مدرک -->
<div class="modal fade" id="proofModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ثبت مدرک انتشار</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="proofForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" id="proofOrderId" name="order_id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">لینک پست/استوری <span class="text-danger">*</span></label>
            <input type="url" name="proof_link" class="form-control"
                   placeholder="https://www.instagram.com/p/..." required>
            <div class="form-text">لینک مستقیم پست یا استوری منتشرشده را وارد کنید.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">تصویر مدرک (اختیاری)</label>
            <input type="file" name="proof_screenshot" class="form-control" accept="image/*">
          </div>
          <div class="mb-3">
            <label class="form-label">توضیحات</label>
            <textarea name="proof_notes" class="form-control" rows="2"
                      placeholder="هر توضیح اضافه‌ای..."></textarea>
          </div>
          <div class="alert alert-info small mb-0">
            <span class="material-icons icon-xs">info</span>
            بعد از ثبت مدرک، به تبلیغ‌دهنده اطلاع داده می‌شود تا پیج شما را چک کنید.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary" id="proofSubmitBtn">ثبت مدرک</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: رد سفارش -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">رد سفارش</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">دلیل رد (اختیاری)</label>
        <textarea id="rejectReason" class="form-control" rows="2"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-danger" id="rejectConfirmBtn">تایید رد</button>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userinfluencermyorders.js') . '"></script>';
include view_path('layouts.user');
?>
