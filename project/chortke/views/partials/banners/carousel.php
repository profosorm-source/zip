<?php if (empty($banners)) return; ?>
<div class="banner-carousel" data-placement="<?= e($placementSlug) ?>" data-rotation="<?= $placement->rotation_speed ?? 5000 ?>">
    <div class="carousel-container">
        <?php foreach ($banners as $index => $banner): ?>
            <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-banner-id="<?= $banner->id ?>">
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

    <?php if (count($banners) > 1): ?>
        <div class="carousel-controls">
            <button class="carousel-prev" data-action="carousel-prev">‹</button>
            <button class="carousel-next" data-action="carousel-next">›</button>
        </div>
        <div class="carousel-indicators">
            <?php foreach ($banners as $index => $banner): ?>
                <span class="indicator <?= $index === 0 ? 'active' : '' ?>" data-action="carousel-goto" data-index="<?= e($index) ?>"></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>