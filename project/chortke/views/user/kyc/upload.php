<?php
$title = 'آپلود مدارک احراز هویت';
$hideSidebar = true;
$errors = $errors ?? [];
$canSubmit = $canSubmit ?? true;
$appName = $appName ?? setting('site_name', 'چرتکه');
$todayJalali = $todayJalali ?? to_jalali(date('Y-m-d'));

ob_start();
?>

<div class="acc-wrap">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">upload_file</i></div>
            <div>
                <div class="acc-hero__eyebrow">KYC Upload</div>
                <h1 class="acc-hero__title">آپلود مدارک احراز هویت</h1>
                <p class="acc-hero__sub">اطلاعات هویتی و تصویر سلفی مطابق قوانین را برای بررسی ارسال کنید.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/kyc') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به KYC</a>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'kyc'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <?php if (!$canSubmit): ?>
                <div class="acc-empty acc-section">
                    <i class="material-icons">lock</i>
                    <h3>امکان ثبت درخواست نیست</h3>
                    <p><?= e($error ?? 'شما در حال حاضر مجاز به ثبت درخواست احراز هویت نیستید.') ?></p>
                    <a href="<?= url('/kyc') ?>" class="acc-btn acc-btn-primary">بازگشت به صفحه اصلی</a>
                </div>
            <?php else: ?>
                <div class="acc-grid">
                    <section class="acc-form-card">
                        <div class="acc-form-card__head"><div class="acc-form-card__title"><i class="material-icons">assignment_ind</i> اطلاعات هویتی</div></div>
                        <div class="acc-form-card__body">
                            <div class="acc-alert acc-alert-warning"><i class="material-icons">warning_amber</i><div><strong>توجه:</strong> اطلاعات باید دقیقاً مطابق مدارک هویتی شما باشد. ارسال مدارک جعلی باعث مسدود شدن حساب می‌شود.</div></div>
                            <form method="POST" action="<?= url('/kyc/submit') ?>" enctype="multipart/form-data" id="kycForm">
                                <?= csrf_field() ?>
                                <div class="acc-form-row">
                                    <div class="acc-form-group">
                                        <label for="national_code">کد ملی</label>
                                        <input type="text" name="national_code" id="national_code" dir="ltr" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="۱۰ رقم کد ملی" required>
                                        <?php if (!empty($errors['national_code'])): ?><small class="acc-form-text acc-badge acc-badge--danger"><?= e($errors['national_code'][0]) ?></small><?php endif; ?>
                                    </div>
                                    <div class="acc-form-group">
                                        <label for="birth_date">تاریخ تولد</label>
                                        <input type="date" name="birth_date" id="birth_date" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" min="<?= date('Y-m-d', strtotime('-100 years')) ?>" required>
                                        <?php if (!empty($errors['birth_date'])): ?><small class="acc-form-text acc-badge acc-badge--danger"><?= e($errors['birth_date'][0]) ?></small><?php endif; ?>
                                    </div>
                                </div>

                                <div class="acc-form-row one">
                                    <div class="acc-form-group">
                                        <label for="verificationImage">تصویر احراز هویت</label>
                                        <input type="file" id="verificationImage" name="verification_image" accept="image/jpeg,image/jpg,image/png" required>
                                        <small class="acc-form-text">JPG یا PNG — حداکثر ۵ مگابایت</small>
                                        <?php if (!empty($errors['verification_image'])): ?><small class="acc-form-text acc-badge acc-badge--danger"><?= e($errors['verification_image'][0]) ?></small><?php endif; ?>
                                    </div>
                                </div>

                                <div class="acc-alert acc-alert-info" id="kycPreviewBox" style="display:none;"><i class="material-icons">image</i><div><strong>پیش‌نمایش آماده است</strong><br><img id="previewImg" src="" alt="preview" style="max-width:220px;border-radius:14px;margin-top:10px;border:1px solid var(--acc-border-soft);"></div></div>

                                <label class="acc-alert acc-alert-warning" style="cursor:pointer;">
                                    <input type="checkbox" id="confirmCheck" required style="margin-top:6px;">
                                    <span>تأیید می‌کنم اطلاعات واردشده صحیح و تصویر ارسالی بدون ویرایش و متعلق به خودم است.</span>
                                </label>

                                <div class="acc-actions"><button type="submit" id="submitBtn" class="acc-btn acc-btn-primary" disabled><i class="material-icons">send</i> ارسال درخواست احراز هویت</button></div>
                            </form>
                        </div>
                    </section>

                    <aside class="acc-card">
                        <div class="acc-card__head"><div class="acc-card__title"><i class="material-icons">info_outline</i> راهنمای تصویری و نمونه دست‌نوشته</div></div>
                        <div class="acc-card__body">
                            <div class="acc-alert acc-alert-info" style="background:rgba(59,130,246,0.12); border:1px solid rgba(59,130,246,0.3); border-radius:14px; padding:16px; margin-bottom:18px;">
                                <i class="material-icons" style="color:#3b82f6;">assignment</i>
                                <div><strong>متن نمونه دست‌نوشته (روی کاغذ سفید با خودکار):</strong></div>
                            </div>

                            <!-- باکس متن دست‌نوشته با قابلیت کپی -->
                            <div style="background:var(--acc-surface-2, #161C27); border:1px dashed var(--acc-border-soft, #2B313A); border-radius:16px; padding:18px; margin-bottom:22px; position:relative; font-size:0.95rem; line-height:1.9; color:inherit; text-align:right;">
                                <p style="margin:0; font-family:'Vazirmatn',Tahoma,sans-serif;">
                                    «اینجانب <strong style="color:#10b981;">[نام و نام خانوادگی]</strong>، جهت احراز هویت در صرافی ارز دیجیتال <strong>چرتکه</strong> و با اطلاع کامل از قوانین ضدپولشویی، این تصویر را ارائه می‌نمایم. تعهد می‌گردد حساب کاربری و کارت‌های بانکی خود را در اختیار اشخاص دیگر قرار ندهم.<br>
                                    <strong style="color:#F0B90B;">تاریخ امروز: <?= e($todayJalali) ?> - امضا و اثر انگشت</strong>»
                                </p>
                            </div>

                            <!-- راهنمای تصویری نحوه در دست گرفتن مدارک -->
                            <div style="background:var(--acc-surface-2, #161C27); border:1px solid var(--acc-border-soft, #2B313A); border-radius:16px; padding:18px; margin-bottom:20px; text-align:center;">
                                <div style="width:70px; height:70px; background:rgba(16,185,129,0.15); border:2px solid #10b981; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#10b981; margin:0 auto 12px;">
                                    <i class="material-icons" style="font-size:36px;">face_retouching_natural</i>
                                </div>
                                <h4 style="font-size:1rem; font-weight:800; margin-bottom:10px;">نحوه در دست گرفتن مدارک:</h4>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:0.85rem; text-align:right; margin-top:12px;">
                                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.08);">
                                        <strong style="color:#10b981; display:block; margin-bottom:4px;">🤳 دست راست:</strong>
                                        کارت ملی یا شناسنامه جدید (خوانا و بدون رفلکس نور فلش)
                                    </div>
                                    <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.08);">
                                        <strong style="color:#F0B90B; display:block; margin-bottom:4px;">📝 دست چپ:</strong>
                                        برگه دست‌نوشته تعهدنامه (کاملاً خوانا و با امضا و تاریخ روز)
                                    </div>
                                </div>
                            </div>

                            <div class="acc-info-grid" style="grid-template-columns:1fr;">
                                <div class="acc-info-row">فرمت مجاز فایل<strong>JPG / PNG</strong></div>
                                <div class="acc-info-row">حداکثر حجم فایل<strong>۵ مگابایت</strong></div>
                            </div>
                            <div class="acc-alert acc-alert-danger" style="margin-top:16px;"><i class="material-icons">cancel</i><div>تصاویر تار، فیلترشده، ویرایش‌شده با فتوشاپ، عینک دودی، ماسک یا مدارک متعلق به دیگران پذیرفته نمی‌شود.</div></div>
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userkycupload.js') . '"></script>';
include view_path('layouts.user');
?>
