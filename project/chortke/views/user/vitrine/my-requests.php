<?php
$title = 'درخواست‌های من — ویترین';
ob_start();
$statusColors = [
  'pending'   => 'warning',
  'accepted'  => 'success',
  'rejected'  => 'danger',
  'cancelled' => 'secondary',
];
$statusLabels = [
  'pending'   => 'در انتظار',
  'accepted'  => 'پذیرفته شد',
  'rejected'  => 'رد شد',
  'cancelled' => 'لغو شد',
];
?>

<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary align-middle">forward_to_inbox</span>
      درخواست‌های من — ویترین
    </h4>
    <p class="text-muted mb-0 text-12">درخواست‌های خرید با قیمت پیشنهادی که ثبت کرده‌اید</p>
  </div>
  <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons icon-sm">storefront</span> بازار ویترین
  </a>
</div>

<?php if (empty($requests)): ?>
<div class="text-center py-5 mt-4">
  <span class="material-icons text-muted icon-xl">forward_to_inbox</span>
  <p class="text-muted mt-2">هنوز درخواستی ثبت نکرده‌اید.</p>
  <a href="<?= url('/vitrine') ?>" class="btn btn-primary btn-sm">مشاهده آگهی‌های فروش</a>
</div>
<?php else: ?>

<div class="row g-3 mt-2">
  <?php foreach ($requests as $r): ?>
  <?php
    $status    = $r->status ?? 'pending';
    $sc        = $statusColors[$status] ?? 'secondary';
    $sl        = $statusLabels[$status] ?? $status;
    $offer     = $r->offer_price ?? null;
  ?>
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="badge bg-primary bg-opacity-10 text-primary"><?= e($r->platform ?? $r->category ?? '—') ?></span>
          <span class="badge bg-<?= $sc ?>"><?= e($sl) ?></span>
        </div>
        <h6 class="fw-bold mb-1">
          <a href="<?= url('/vitrine/' . (int)$r->listing_id) ?>" class="text-decoration-none text-dark">
            <?= e(mb_substr($r->listing_title ?? ('آگهی #' . $r->listing_id), 0, 60)) ?>
          </a>
        </h6>

        <?php if (!empty($r->message)): ?>
        <div class="small text-muted mb-2"><?= e(mb_substr($r->message, 0, 120)) ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center">
          <?php if ($offer !== null): ?>
            <span class="fw-bold text-success"><?= number_format((float)$offer, 2) ?> USDT</span>
          <?php else: ?>
            <span class="small text-muted">قیمت پیشنهادی ندارد</span>
          <?php endif; ?>
          <span class="small text-muted"><?= e(substr($r->created_at ?? '', 0, 10)) ?></span>
        </div>

        <?php if (!empty($r->responded_at)): ?>
        <div class="small text-muted mt-2">
          <span class="material-icons icon-xs">schedule</span>
          پاسخ: <?= e(substr($r->responded_at, 0, 16)) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
$styles = '';
include view_path('layouts.user');
?>
