<?php
$activeInfluencerAdmin = $activeInfluencerAdmin ?? 'orders';
$items = [
  'orders' => [url('/admin/influencer/orders'), 'receipt_long', 'سفارش‌ها', 'چرخه سفارش و escrow'],
  'profiles' => [url('/admin/influencer/profiles'), 'groups', 'پروفایل‌ها', 'تأیید، رد و تعلیق پیج‌ها'],
  'verifications' => [url('/admin/influencer/verifications'), 'fact_check', 'درخواست‌های تأیید', 'بررسی مدارک مالکیت'],
  'disputes' => [url('/admin/influencer/disputes'), 'gavel', 'اختلاف‌ها', 'داوری و تسویه امن'],
];
?>
<nav class="ai-nav" aria-label="ناوبری مدیریت اینفلوئنسر">
  <?php foreach ($items as $key => $item): ?>
    <a href="<?= e($item[0]) ?>" class="ai-nav-item <?= $activeInfluencerAdmin === $key ? 'active' : '' ?>">
      <span class="material-icons"><?= e($item[1]) ?></span>
      <span><strong><?= e($item[2]) ?></strong><small><?= e($item[3]) ?></small></span>
    </a>
  <?php endforeach; ?>
</nav>
