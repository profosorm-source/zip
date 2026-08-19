<!-- Partial: Banner Detail -->
<div class="card mb-3">
    <div class="card-header"><strong>جزئیات بنر</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">جایگاه (Placement)</div>
                <div class="fw-bold"><?= e($ad->placement ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع بنر</div>
                <div class="fw-bold"><?= e($ad->banner_type ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">لینک</div>
                <div class="fw-bold">
                    <?= e($ad->link ?? '—') ?>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">Target</div>
                <div class="fw-bold"><?= e($ad->target ?? '_blank') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">کلیک‌ها</div>
                <div class="fw-bold"><?= fa_number($ad->clicks ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نمایش‌ها</div>
                <div class="fw-bold"><?= fa_number($ad->impressions ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">CTR</div>
                <div class="fw-bold"><?= number_format($ad->ctr ?? 0, 2) ?>%</div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تاریخ شروع</div>
                <div class="fw-bold"><?= to_jalali($ad->start_date ?? '') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تاریخ پایان</div>
                <div class="fw-bold"><?= to_jalali($ad->end_date ?? '') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">فعال</div>
                <div class="fw-bold"><?= ($ad->is_active ?? 0) ? 'بله' : 'خیر' ?></div>
            </div>
        </div>
        <?php if (!empty($ad->image_path)): ?>
        <div class="mt-3">
            <div class="text-muted">تصویر بنر</div>
            <img src="<?= e($ad->image_path) ?>" alt="Banner" class="inline-style-replaced">
        </div>
        <?php endif; ?>
        <div class="mt-3">
            <a href="<?= url('/admin/banners/' . ($ad->id ?? 0) . '/edit') ?>" class="btn btn-sm btn-primary">
                <i class="material-icons">edit</i> ویرایش در پنل بنر
            </a>
        </div>
    </div>
</div>