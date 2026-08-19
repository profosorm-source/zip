<!-- Partial: Custom Task Detail -->
<div class="card mb-3">
    <div class="card-header"><strong>جزئیات تسک سفارشی</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع تسک</div>
                <div class="fw-bold"><?= e($ad->task_type ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">نوع اثبات</div>
                <div class="fw-bold"><?= e($ad->proof_type ?? '—') ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">قیمت هر تسک</div>
                <div class="fw-bold"><?= number_format($ad->price_per_task ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">کارمزد</div>
                <div class="fw-bold"><?= number_format($ad->site_fee_amount ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">در حال انجام</div>
                <div class="fw-bold"><?= fa_number($ad->pending_count ?? 0) ?></div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="text-muted">مهلت</div>
                <div class="fw-bold"><?= to_jalali($ad->deadline ?? '') ?></div>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= url('/admin/custom-tasks/' . ($ad->id ?? 0)) ?>" class="btn btn-sm btn-primary">
                <i class="material-icons">assignment</i> مشاهده Submissions
            </a>
        </div>
    </div>
</div>