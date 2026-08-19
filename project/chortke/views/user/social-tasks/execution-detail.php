<?php
// Legacy compatibility view. Execution details are now rendered by /social-tasks/{id}/execute.
$title = 'جزئیات اجرای تسک اجتماعی';
$hideSidebar = true;
$exec = $exec ?? null;
ob_start();
?>
<div class="earn-wrap task-market-wrap">
  <section class="earn-hero task-market-hero">
    <div class="earn-hero__main">
      <div class="earn-hero__icon"><i class="material-icons">groups</i></div>
      <div>
        <div class="earn-hero__eyebrow">Social Task</div>
        <h1 class="earn-hero__title">جزئیات اجرای تسک اجتماعی</h1>
        <p class="earn-hero__sub">این مسیر قدیمی است؛ برای ادامه از صفحه اجرای اصلی استفاده کنید.</p>
      </div>
    </div>
    <div class="earn-hero__side">
      <a href="<?= url('/tasks?type=social') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار تسک‌ها</a>
      <?php if (!empty($exec->id)): ?><a href="<?= url('/social-tasks/' . (int)$exec->id . '/execute') ?>" class="earn-btn earn-btn-primary">ادامه اجرا</a><?php endif; ?>
    </div>
  </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
include view_path('layouts.user');
?>
