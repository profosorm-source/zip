<?php
$title = 'تأیید رمز عبور — اتصال حساب';
ob_start();
?>
<div class="container py-5" style="max-width:480px;margin:0 auto">
    <div class="card">
        <div class="card-header text-center">
            <span class="material-icons" style="font-size:48px;color:var(--primary)">lock</span>
            <h4 class="mt-2">تأیید رمز عبور</h4>
            <p class="text-muted small mb-0">برای اتصال حساب <?= e($provider ?? '') ?>، لطفاً رمز عبور خود را وارد کنید</p>
        </div>
        <div class="card-body">
            <?php if ($error = flash('error')): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="<?= url('auth/oauth-confirm') ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">رمز عبور</label>
                    <input type="password" name="password" class="form-control" required autofocus 
                           placeholder="رمز عبور خود را وارد کنید" minlength="6">
                </div>
                <button type="submit" class="btn btn-primary w-100">تأیید و اتصال حساب</button>
            </form>
            
            <div class="text-center mt-3">
                <a href="<?= url('login') ?>" class="text-muted small">انصراف و بازگشت به ورود</a>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
