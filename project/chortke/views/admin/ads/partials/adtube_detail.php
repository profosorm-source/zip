<!-- Partial: AdTube Detail -->
<div class="card mb-3">
    <div class="card-header"><strong>جزئیات AdTube (ویدیو)</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">URL ویدیو</div>
                <div class="fw-bold">
                    <?= e($ad->video_url ?? ($ad->target_url ?? '—')) ?>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">مدت زمان مورد نیاز (ثانیه)</div>
                <div class="fw-bold"><?= fa_number($ad->required_watch_time ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">قیمت هر مشاهده</div>
                <div class="fw-bold"><?= number_format($ad->price_per_view ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تعداد مشاهده</div>
                <div class="fw-bold"><?= fa_number($ad->views_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">تعداد کلیک</div>
                <div class="fw-bold"><?= fa_number($ad->clicks_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">CTR</div>
                <div class="fw-bold"><?= number_format($ad->ctr ?? 0, 2) ?>%</div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">مهلت</div>
                <div class="fw-bold"><?= to_jalali($ad->deadline ?? '') ?></div>
            </div>
        </div>
        <div class="mt-3 alert alert-warning">
            <i class="material-icons">videocam</i>
            اجراهای AdTube از طریق جدول <code>adtube_views</code> مدیریت می‌شوند. فعلاً پنل تخصصی جداگانه برای AdTube وجود ندارد و از همین داشبورد یکپارچه قابل مدیریت است.
        </div>
    </div>
</div>