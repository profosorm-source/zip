<?php
$title = 'مدیریت کوپن‌های تخفیف';
ob_start();
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">لیست کوپن‌های تخفیف</h5>
                    <a href="<?= url('admin/coupons/create') ?>" class="btn btn-primary btn-sm">
                        <span class="material-icons" aria-hidden="true">add</span> افزودن کوپن جدید
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-items-center mb-0" id="couponsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>کد</th>
                                    <th>نوع</th>
                                    <th>مقدار</th>
                                    <th>کاربرد</th>
                                    <th>محدودیت استفاده</th>
                                    <th>تعداد استفاده</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ انقضا</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($coupons)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">کوپنی یافت نشد</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($coupons as $coupon): ?>
                                        <tr>
                                            <td><?= e($coupon->id) ?></td>
                                            <td>
                                                <span class="badge bg-gradient-info"><?= e(e($coupon->code)) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($coupon->type === 'percent'): ?>
                                                    <span class="badge bg-gradient-success">درصدی</span>
                                                <?php else: ?>
                                                    <span class="badge bg-gradient-warning">مبلغ ثابت</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($coupon->type === 'percent'): ?>
                                                    <?= e($coupon->value) ?>%
                                                <?php else: ?>
                                                    <?= number_format($coupon->value) ?> تومان
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $labels = [
                                                    'all' => 'همه',
                                                    'task' => 'سفارش تسک',
                                                    'investment' => 'سرمایه‌گذاری',
                                                    'vip' => 'VIP',
                                                    'story_order' => 'سفارش استوری'
                                                ];
                                                ?>
                                                <span class="badge bg-gradient-primary"><?= e($labels[$coupon->applicable_to] ?? $coupon->applicable_to) ?></span>
                                            </td>
                                            <td><?= $coupon->usage_limit > 0 ? $coupon->usage_limit : 'نامحدود' ?></td>
                                            <td>
                                                <span class="badge bg-gradient-secondary"><?= e($coupon->usage_count) ?></span>
                                            </td>
                                            <td>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input toggle-active" 
                                                           type="checkbox" 
                                                           data-id="<?= e($coupon->id) ?>"
                                                           <?= $coupon->active ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($coupon->end_date): ?>
                                                    <?= jdate('Y/m/d H:i', strtotime($coupon->end_date)) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">نامحدود</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?= url('admin/coupons/details?id=' . $coupon->id) ?>" 
                                                   class="btn btn-info btn-sm" 
                                                   title="جزئیات">
                                                    <span class="material-icons" aria-hidden="true">visibility</span>
                                                </a>
                                                <a href="<?= url('admin/coupons/edit?id=' . $coupon->id) ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   title="ویرایش">
                                                    <span class="material-icons" aria-hidden="true">edit</span>
                                                </a>
                                                <button class="btn btn-danger btn-sm delete-coupon" 
                                                        data-id="<?= e($coupon->id) ?>" 
                                                        title="حذف">
                                                    <span class="material-icons" aria-hidden="true">delete</span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>




<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
