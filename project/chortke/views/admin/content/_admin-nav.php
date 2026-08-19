<?php
$activeAdminContent = $activeAdminContent ?? 'submissions';
$tabs = [
    'submissions' => ['href' => url('/admin/content'), 'icon' => 'video_library', 'label' => 'بررسی محتواها', 'hint' => 'تأیید، رد، انتشار و تعلیق'],
    'revenues' => ['href' => url('/admin/content/revenues'), 'icon' => 'payments', 'label' => 'درآمدها', 'hint' => 'تأیید و پرداخت دوره‌ای'],
];
?>
<nav class="ac-nav" aria-label="ناوبری مدیریت محتوا">
    <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= e($tab['href']) ?>" class="ac-nav-item <?= $activeAdminContent === $key ? 'active' : '' ?>">
            <span class="material-icons"><?= e($tab['icon']) ?></span>
            <span>
                <strong><?= e($tab['label']) ?></strong>
                <small><?= e($tab['hint']) ?></small>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
