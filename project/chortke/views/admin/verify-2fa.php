<?php
$title = 'تأیید هویت دو مرحله‌ای — ادمین';
ob_start();
?>
<div class="container py-5" style="max-width:420px;margin:0 auto">
    <div class="card"><div class="card-header text-center">
        <span class="material-icons" style="font-size:48px;color:var(--primary)">security</span>
        <h4 class="mt-2">تأیید هویت دو مرحله‌ای</h4>
        <p class="text-muted small mb-0">کد ۶ رقمی از برنامه Authenticator خود را وارد کنید</p>
    </div>
    <div class="card-body">
        <?php if ($error = flash('error')): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="<?= url('/admin/verify-2fa') ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <input type="text" name="code" class="form-control text-center" maxlength="6" required autofocus 
                       placeholder="کد ۶ رقمی" inputmode="numeric" pattern="[0-9]{6}">
            </div>
            <button type="submit" class="btn btn-primary w-100">تأیید</button>
        </form>
    </div></div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
