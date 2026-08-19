<?php if (empty($banners)) return; ?>
<div class="banner-static" data-placement="<?= e($placementSlug) ?>">
    <?php foreach ($banners as $banner): ?>
        <div class="banner-item" data-banner-id="<?= $banner->id ?>">
            <?php if ($banner->image_path): ?>
                <a href="<?= e($banner->link ?? '#') ?>" target="<?= e($banner->target ?? '_blank') ?>" data-action="banner-click" data-id="<?= e($banner->id) ?>">
                    <img src="<?= e($banner->image_path) ?>" alt="<?= e($banner->alt_text ?? $banner->title) ?>" loading="lazy">
                </a>
            <?php elseif ($banner->custom_code): ?>
                <div class="banner-custom"><?= $banner->custom_code ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>