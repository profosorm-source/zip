<?php
// Legacy compatibility view. Direct rating is currently disabled for social task executions.
$title = 'امتیازدهی تسک اجتماعی';
$hideSidebar = true;
ob_start();
?>
<div class="earn-wrap task-market-wrap">
  <section class="earn-hero task-market-hero">
    <div class="earn-hero__main">
      <div class="earn-hero__icon"><i class="material-icons">star</i></div>
      <div>
        <div class="earn-hero__eyebrow">Social Rating</div>
        <h1 class="earn-hero__title">امتیازدهی مستقیم فعال نیست</h1>
        <p class="earn-hero__sub">امتیازدهی این مسیر در ریفکت جدید غیرفعال است. برای مشاهده تسک‌ها به بازار تسک‌های اجتماعی برگردید.</p>
      </div>
    </div>
    <div class="earn-hero__side"><a href="<?= url('/tasks?type=social') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار تسک‌ها</a></div>
  </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
include view_path('layouts.user');
?>
