<?php
$title = 'ایجاد سطح جدید';

$session = \Core\Session::getInstance();
$old = $session->getFlash('old') ?? [];
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">add_circle</i>
                ایجاد سطح جدید
            </h4>
        </div>
        <a href="<?= url('/admin/levels') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<?php if ($flash = $session->getFlash('error')): ?>
<div class="alert alert-danger mt-3">
    <i class="material-icons">error</i>
    <?= e($flash) ?>
</div>
<?php endif; ?>

<form action="<?= url('/admin/levels/create') ?>" method="POST">
    <?= csrf_field() ?>

    <!-- اطلاعات پایه -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">اطلاعات پایه</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">نام سطح <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($old['name'] ?? '') ?>" placeholder="مثال: نقره" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">شناسه (slug) <span class="text-danger">*</span></label>
                    <input type="text" name="slug" class="form-control" dir="ltr" value="<?= e($old['slug'] ?? '') ?>" placeholder="مثال: silver" required>
                    <small class="text-muted">فقط حروف انگلیسی کوچک، عدد، خط تیره و زیرخط</small>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">آیکون Material</label>
                    <input type="text" name="icon" class="form-control" dir="ltr" value="<?= e($old['icon'] ?? 'workspace_premium') ?>" placeholder="workspace_premium">
                    <small class="text-muted"><a href="https://fonts.google.com/icons" target="_blank">لیست آیکون‌ها</a></small>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">رنگ</label>
                    <input type="color" name="color" class="form-control form-control-color" value="<?= e($old['color'] ?? '#c0c0c0') ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">ترتیب نمایش</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= e($old['sort_order'] ?? '') ?>" placeholder="خودکار">
                    <small class="text-muted">خالی = انتهای لیست</small>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                            <?= (isset($old['is_active']) ? $old['is_active'] : true) ? 'checked' : '' ?>>
                        <label class="form-check-label">فعال</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- پیش‌نمایش آیکون -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">پیش‌نمایش سطح</h6></div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <i class="material-icons" id="iconPreview">workspace_premium</i>
                <span id="namePreview">نام سطح</span>
            </div>
        </div>
    </div>

    <!-- شرایط ارتقا با فعالیت -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">شرایط ارتقا با فعالیت</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل روز فعالیت</label>
                    <input type="number" name="min_active_days" class="form-control" min="0" value="<?= e($old['min_active_days'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل تسک تکمیل‌شده</label>
                    <input type="number" name="min_completed_tasks" class="form-control" min="0" value="<?= e($old['min_completed_tasks'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل درآمد (تومان)</label>
                    <input type="number" name="min_total_earning" class="form-control" min="0" value="<?= e($old['min_total_earning'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">حداقل درآمد (USDT)</label>
                    <input type="number" name="min_total_earning_usdt" class="form-control" min="0" step="0.01" value="<?= e($old['min_total_earning_usdt'] ?? 0) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- قیمت خرید -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">قیمت خرید</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">قیمت (تومان)</label>
                    <input type="number" name="purchase_price_irt" class="form-control" min="0" value="<?= e($old['purchase_price_irt'] ?? 0) ?>">
                    <small class="text-muted">0 = غیرقابل خرید</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">قیمت (USDT)</label>
                    <input type="number" name="purchase_price_usdt" class="form-control" min="0" step="0.01" value="<?= e($old['purchase_price_usdt'] ?? 0) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">مدت اعتبار (روز)</label>
                    <input type="number" name="purchase_duration_days" class="form-control" min="1" value="<?= e($old['purchase_duration_days'] ?? 30) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- پاداش‌ها -->
    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0">پاداش‌ها و مزایا</h6></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش درآمد تسک (%)</label>
                    <input type="number" name="earning_bonus_percent" class="form-control" min="0" step="0.5" value="<?= e($old['earning_bonus_percent'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش کمیسیون معرفی (%)</label>
                    <input type="number" name="referral_bonus_percent" class="form-control" min="0" step="0.5" value="<?= e($old['referral_bonus_percent'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش سقف تسک روزانه</label>
                    <input type="number" name="daily_task_limit_bonus" class="form-control" min="0" value="<?= e($old['daily_task_limit_bonus'] ?? 0) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">افزایش سقف برداشت</label>
                    <input type="number" name="withdrawal_limit_bonus" class="form-control" min="0" value="<?= e($old['withdrawal_limit_bonus'] ?? 0) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="priority_support" value="0">
                        <input type="checkbox" name="priority_support" value="1" class="form-check-input"
                            <?= ($old['priority_support'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label">پشتیبانی اولویت‌دار</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="special_badge" value="0">
                        <input type="checkbox" name="special_badge" value="1" class="form-check-input"
                            <?= ($old['special_badge'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label">نشان ویژه</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <a href="<?= url('/admin/levels') ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="material-icons">add_circle</i> ایجاد سطح
        </button>
    </div>
</form>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>

