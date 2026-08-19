<?php ob_start(); ?>

<div class="row mb-4">
    <div class="col-12">
        <h3>
            <i class="material-icons-outlined">lock</i>
            تغییر رمز عبور
        </h3>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                
                <form method="POST" action="<?= url('profile/change-password') ?>" id="password-form">
                    <?= csrf_field() ?>
                    
                    <!-- Current Password -->
                    <div class="mb-3">
                        <label class="form-label">رمز عبور فعلی</label>
                        <input type="password" 
                               name="current_password" 
                               class="form-control" 
                               placeholder="رمز عبور فعلی خود را وارد کنید"
                               required>
                    </div>
                    
                    <!-- New Password -->
                    <div class="mb-3">
                        <label class="form-label">رمز عبور جدید</label>
                        <input type="password" 
                               name="new_password" 
                               class="form-control" 
                               placeholder="حداقل 8 کاراکتر"
                               required>
                        <small class="text-muted">حداقل 8 کاراکتر شامل حروف بزرگ، کوچک و عدد</small>
                    </div>
                    
                    <!-- Confirm New Password -->
                    <div class="mb-3">
                        <label class="form-label">تکرار رمز عبور جدید</label>
                        <input type="password" 
                               name="new_password_confirmation" 
                               class="form-control" 
                               placeholder="رمز عبور جدید را دوباره وارد کنید"
                               required>
                    </div>
                    
                    <!-- Submit -->
                    <button type="submit" class="btn btn-primary">
                        <i class="material-icons-outlined">check</i>
                        تغییر رمز عبور
                    </button>
                    
                    <a href="<?= url('profile') ?>" class="btn btn-outline-secondary">
                        <i class="material-icons-outlined">arrow_back</i>
                        بازگشت
                    </a>
                    
                </form>
                
            </div>
        </div>
    </div>
</div>



<?php
$content = ob_get_clean();
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userchangepassword.js') . '"></script>';
include view_path('layouts.user');
?>