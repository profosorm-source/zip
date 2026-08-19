<?php
$title = 'ایجاد نقش جدید';

$session = \Core\Session::getInstance();
$old = $session->getFlash('old') ?? [];
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">add_circle</i>
                ایجاد نقش جدید
            </h4>
            <p class="text-muted mb-0">تعریف نقش و تخصیص دسترسی‌ها</p>
        </div>
        <a href="<?= url('/admin/roles') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>
</div>

<!-- پیام خطا -->
<?php if ($flash = $session->getFlash('error')): ?>
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    <i class="material-icons">error</i>
    <?= e($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form action="<?= url('/admin/roles/store') ?>" method="POST">
    <?= csrf_field() ?>
    
    <!-- اطلاعات پایه -->
    <div class="card mt-3">
        <div class="card-header">
            <h6 class="card-title mb-0">
                <i class="material-icons text-primary">info</i>
                اطلاعات نقش
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">نام نقش <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" 
                           value="<?= e($old['name'] ?? '') ?>" 
                           placeholder="مثال: مدیر مالی" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">شناسه (slug) <span class="text-danger">*</span></label>
                    <input type="text" name="slug" class="form-control" dir="ltr"
                           value="<?= e($old['slug'] ?? '') ?>" 
                           placeholder="مثال: finance_manager" required
                           pattern="[a-zA-Z0-9_-]+"
                           class="aggr-cleaned">
                    <small class="text-muted">فقط حروف انگلیسی، اعداد، خط تیره و زیرخط</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">توضیحات</label>
                    <input type="text" name="description" class="form-control" 
                           value="<?= e($old['description'] ?? '') ?>" 
                           placeholder="توضیح مختصر درباره این نقش">
                </div>
            </div>
        </div>
    </div>
    
    <!-- دسترسی‌ها -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">
                <i class="material-icons text-primary">vpn_key</i>
                دسترسی‌ها
            </h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-success" id="btn-select-all">
                    انتخاب همه
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-deselect-all">
                    حذف همه
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($groupedPermissions as $group => $perms): ?>
            <div class="permission-group mb-4">
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" class="form-check-input me-2 group-toggle" 
                           data-group="<?= e($group) ?>" id="group-<?= e($group) ?>">
                    <label class="form-check-label fw-bold" for="group-<?= e($group) ?>">
                        <?= e($groupLabels[$group] ?? $group) ?>
                    </label>
                </div>
                <div class="row pe-4">
                    <?php foreach ($perms as $perm): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                        <div class="form-check">
                            <input type="checkbox" name="permissions[]" 
                                   value="<?= e($perm->id) ?>" 
                                   class="form-check-input perm-checkbox perm-group-<?= e($group) ?>"
                                   id="perm-<?= e($perm->id) ?>">
                            <label class="form-check-label" for="perm-<?= e($perm->id) ?>" class="aggr-cleaned">
                                <?= e($perm->name) ?>
                                <?php if ($perm->description): ?>
                                <i class="material-icons text-muted" title="<?= e($perm->description) ?>">help_outline</i>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($group !== \array_key_last($groupedPermissions)): ?>
            <hr >
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- دکمه‌ها -->
    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <a href="<?= url('/admin/roles') ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="material-icons">save</i>
            ذخیره نقش
        </button>
    </div>
</form>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
