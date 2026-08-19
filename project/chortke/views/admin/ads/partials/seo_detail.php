<!-- Partial: SEO Detail -->
<div class="card mb-3">
    <div class="card-header"><strong>جزئیات سئو و کلیک</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">کلیدواژه</div>
                <div class="fw-bold"><?= e($ad->keyword ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">URL هدف</div>
                <div class="fw-bold">
                    <?= e($ad->target_url ?? '—') ?>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">قیمت هر کلیک</div>
                <div class="fw-bold"><?= number_format($ad->price_per_click ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تعداد کلیک</div>
                <div class="fw-bold"><?= fa_number($ad->clicks_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">مهلت</div>
                <div class="fw-bold"><?= to_jalali($ad->deadline ?? '') ?></div>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= url('/admin/seo-ad') ?>" class="btn btn-sm btn-primary">
                <i class="material-icons">search</i> مشاهده لیست SEO
            </a>
        </div>
    </div>
</div>