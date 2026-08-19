<?php
$title = 'ویرایش کوپن';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">ویرایش کوپن: <?= e(e($coupon->code)) ?></h5>
                </div>
                <div class="card-body">
                    <form id="editCouponForm" data-action-url="<?= url('admin/coupons/update') ?>
  <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">">
                        <input type="hidden" name="id" value="<?= e($coupon->id) ?>">

                        <div class="row">
                            <!-- کد کوپن (غیرقابل تغییر) -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">کد کوپن</label>
                                <input type="text" 
                                       class="form-control" 
                                       value="<?= e(e($coupon->code)) ?>"
                                       disabled>
                            </div>

                            <!-- نوع تخفیف -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">نوع تخفیف *</label>
                                <select name="type" class="form-select" id="discountType" required>
                                    <option value="percent" <?= $coupon->type === 'percent' ? 'selected' : '' ?>>درصدی</option>
                                    <option value="fixed" <?= $coupon->type === 'fixed' ? 'selected' : '' ?>>مبلغ ثابت</option>
                                </select>
                            </div>

                            <!-- مقدار تخفیف -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">مقدار تخفیف *</label>
                                <input type="number" 
                                       name="value" 
                                       class="form-control" 
                                       step="0.01"
                                       min="0"
                                       value="<?= e($coupon->value) ?>"
                                       required>
                            </div>

                            <!-- کاربرد کوپن -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">کاربرد کوپن *</label>
                                <select name="applicable_to" class="form-select" required>
                                    <option value="all" <?= $coupon->applicable_to === 'all' ? 'selected' : '' ?>>همه موارد</option>
                                    <option value="task" <?= $coupon->applicable_to === 'task' ? 'selected' : '' ?>>سفارش تسک</option>
                                    <option value="investment" <?= $coupon->applicable_to === 'investment' ? 'selected' : '' ?>>سرمایه‌گذاری</option>
                                    <option value="vip" <?= $coupon->applicable_to === 'vip' ? 'selected' : '' ?>>خرید VIP</option>
                                    <option value="story_order" <?= $coupon->applicable_to === 'story_order' ? 'selected' : '' ?>>سفارش استوری</option>
                                </select>
                            </div>

                            <!-- حداقل خرید -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">حداقل مبلغ خرید (تومان)</label>
                                <input type="number" 
                                       name="min_purchase" 
                                       class="form-control" 
                                       value="<?= e($coupon->min_purchase) ?>">
                            </div>

                            <!-- حداکثر تخفیف -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">حداکثر تخفیف (تومان)</label>
                                <input type="number" 
                                       name="max_discount" 
                                       class="form-control" 
                                       value="<?= e($coupon->max_discount) ?>">
                            </div>

                            <!-- محدودیت استفاده -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">محدودیت تعداد استفاده</label>
                                <input type="number" 
                                       name="usage_limit" 
                                       class="form-control" 
                                       value="<?= e($coupon->usage_limit) ?>">
                                <small class="text-muted">استفاده شده: <?= e($coupon->usage_count) ?></small>
                            </div>

                            <!-- تاریخ شروع -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ شروع</label>
                                <input type="datetime-local" 
                                       name="start_date" 
                                       class="form-control"
                                       value="<?= $coupon->start_date ? date('Y-m-d\TH:i', strtotime($coupon->start_date)) : '' ?>">
                            </div>

                            <!-- تاریخ پایان -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ پایان</label>
                                <input type="datetime-local" 
                                       name="end_date" 
                                       class="form-control"
                                       value="<?= $coupon->end_date ? date('Y-m-d\TH:i', strtotime($coupon->end_date)) : '' ?>">
                            </div>

                            <!-- وضعیت فعال -->
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="active" 
                                           id="activeCheck"
                                           <?= $coupon->active ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="activeCheck">
                                        فعال
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?= url('admin/coupons') ?>" class="btn btn-secondary">
                                <span class="material-icons" aria-hidden="true">arrow_forward</span> بازگشت
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <span class="material-icons" aria-hidden="true">save</span> بروزرسانی
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
