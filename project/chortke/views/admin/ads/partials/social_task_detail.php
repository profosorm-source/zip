<!-- Partial: Social Task Detail -->
<div class="card mb-3">
    <div class="card-header"><strong>جزئیات تسک شبکه اجتماعی</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">پلتفرم</div>
                <div class="fw-bold"><?= e($ad->platform ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع اکشن</div>
                <div class="fw-bold"><?= e($ad->action_type ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">لینک/مقصد</div>
                <div class="fw-bold">
                    <?= e($ad->target_url ?? '—') ?>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">قیمت هر اکشن</div>
                <div class="fw-bold"><?= number_format($ad->price_per_action ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تعداد کلیک/اکشن</div>
                <div class="fw-bold"><?= fa_number($ad->clicks_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نمایش (Impressions)</div>
                <div class="fw-bold"><?= fa_number($ad->impressions ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">CTR</div>
                <div class="fw-bold"><?= number_format($ad->ctr ?? 0, 2) ?>%</div>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= url('/admin/social-tasks/' . ($ad->id ?? 0)) ?>" class="btn btn-sm btn-primary">
                <i class="material-icons">group</i> مشاهده Executions
            </a>
        </div>
    </div>
</div>