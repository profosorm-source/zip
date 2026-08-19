<?php
$title = 'افزودن کاربر جدید';

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">افزودن کاربر جدید</h5>
    </div>
    <div class="card-body">
        <form id="createUserForm" data-action-url="<?= url('/admin/users/store') ?>">
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">نام کامل <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">ایمیل <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">رمز عبور <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">نقش <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" required>
                        <option value="user">کاربر</option>
                        <option value="support">پشتیبان</option>
                        <option value="admin">مدیر</option>
                    </select>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">وضعیت <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active">فعال</option>
                        <option value="inactive">غیرفعال</option>
                        <option value="suspended">تعلیق</option>
                        <option value="banned">مسدود</option>
                    </select>
                    <div class="invalid-feedback"></div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i>
                    ذخیره
                </button>
                <a href="<?= url('/admin/users') ?>" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
