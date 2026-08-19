<?php
$title = 'اختلاف‌های تسک سفارشی من';
$items = $items ?? $disputes ?? [];
$statusLabels = [
  'open' => 'باز',
  'open_peer' => 'باز',
  'under_review' => 'در حال بررسی',
  'escalated' => 'ارجاع به ادمین',
  'resolved_for_executor' => 'حل به نفع مجری',
  'resolved_for_advertiser' => 'حل به نفع تبلیغ‌دهنده',
  'resolved_admin' => 'حل‌شده',
  'closed' => 'بسته',
];
ob_start();
?>
<div class="earn-wrap task-market-wrap">
  <section class="earn-hero task-market-hero">
    <div class="earn-hero__main"><div class="earn-hero__icon"><i class="material-icons">gavel</i></div><div><div class="earn-hero__eyebrow">Custom Task Disputes</div><h1 class="earn-hero__title">اختلاف‌های تسک سفارشی من</h1><p class="earn-hero__sub">پرونده‌های اعتراض به رد شدن مدرک یا اختلاف با تبلیغ‌دهنده را اینجا پیگیری کنید.</p></div></div>
    <div class="earn-hero__side"><a href="<?= url('/custom-tasks/my-submissions') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> اجراهای من</a></div>
  </section>

  <?php if (empty($items)): ?>
    <div class="earn-empty"><i class="material-icons">verified_user</i><h3>اختلاف فعالی ندارید</h3><p>اگر مدرک شما رد شود، از صفحه اجراهای من می‌توانید اختلاف ثبت کنید.</p></div>
  <?php else: ?>
    <section class="earn-section">
      <div class="earn-section__header"><div class="earn-section__title"><i class="material-icons">list_alt</i> پرونده‌ها</div></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>#</th><th>تسک</th><th>طرف مقابل</th><th>وضعیت</th><th>دلیل</th><th>تاریخ</th><th>عملیات</th></tr></thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= (int)$item->id ?></td>
              <td><?= e($item->task_title ?? '-') ?></td>
              <td><?= e($item->other_party_name ?? '-') ?></td>
              <td><span class="tm-badge <?= str_starts_with((string)$item->status, 'resolved') ? 'tm-badge-green' : 'tm-badge-gold' ?>"><?= e($statusLabels[$item->status] ?? $item->status) ?></span></td>
              <td><?= e(mb_substr((string)$item->reason, 0, 70)) ?></td>
              <td class="text-muted small"><?= e(substr((string)$item->created_at, 0, 16)) ?></td>
              <td><a class="earn-btn earn-btn-secondary" style="min-height:34px;padding:7px 11px" href="<?= url('/custom-tasks/disputes/' . (int)$item->id) ?>">مشاهده</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
include view_path('layouts.user');
?>
