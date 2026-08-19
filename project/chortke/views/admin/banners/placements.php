<?php
$title = 'مدیریت جایگاه‌ها';
ob_start();
?>

<div class="admin-content">
    <div class="page-header">
        <h1>📍 جایگاه‌های تبلیغاتی</h1>
        <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="placements-grid">
        <?php foreach ($placements as $p): ?>
            <div class="placement-card <?= $p->is_active ? 'active' : 'inactive' ?>">
                <div class="placement-header">
                    <h3><?= e($p->title) ?></h3>
                    <div class="badge <?= $p->is_active ? 'badge-success' : 'badge-secondary' ?>">
                        <?= $p->is_active ? '✅ فعال' : '❌ غیرفعال' ?>
                    </div>
                </div>

                <div class="placement-info">
                    <div class="info-row">
                        <span class="label">کد:</span>
                        <code><?= e($p->slug) ?></code>
                    </div>
                    <div class="info-row">
                        <span class="label">صفحه:</span>
                        <span><?= e($p->page) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">ابعاد:</span>
                        <span><?= e($p->dimensions ?? '-') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">حداکثر:</span>
                        <span><?= $p->max_banners ?> بنر</span>
                    </div>
                    <div class="info-row">
                        <span class="label">فعال:</span>
                        <span class="badge badge-info"><?= $p->active_banners ?? 0 ?></span>
                    </div>
                </div>

                <div class="placement-actions">
                    <form method="POST" action="<?= url('/admin/banners/placements/toggle') ?>">
            <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $p->id ?>">
                        <button type="submit" class="btn btn-sm <?= $p->is_active ? 'btn-warning' : 'btn-success' ?>">
                            <?= $p->is_active ? '⏸️ غیرفعال' : '▶️ فعال' ?>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-primary" data-click="editPlacement" data-args="<?= $p->id ?>">⚙️ تنظیمات</button>
                </div>

                <?php if ($p->description): ?>
                    <div class="placement-desc">
                        <small><?= e($p->description) ?></small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>





<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbannersplacements.css') . '">';
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/bannersplacements.js') . '"></script>';
include view_path('layouts.admin');
?>

