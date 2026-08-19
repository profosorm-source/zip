<?php
$title = $title ?? 'ارسال مدرک تسک سفارشی';
$hideSidebar = true;
$submission = $submission ?? null;
$submissionId = (int)($submission->id ?? 0);
$status = (string)($submission->status ?? 'in_progress');
$reward = (float)($submission->reward_amount ?? $submission->price_per_task ?? 0);
$idempotencyKey = 'CUSTOM_' . $submissionId . '_' . bin2hex(random_bytes(12));
$proofType = strtolower((string)($submission->proof_type ?? 'text'));
$proofType = match ($proofType) { 'link' => 'url', 'image' => 'screenshot', default => $proofType };
if (!in_array($proofType, ['text','code','url','screenshot','file','video'], true)) $proofType = 'text';
$proofLabels = [
    'text' => 'متن مدرک',
    'code' => 'کد/شناسه مدرک',
    'url' => 'لینک مدرک',
    'screenshot' => 'اسکرین‌شات',
    'file' => 'فایل مدرک',
    'video' => 'لینک ویدیو',
];
$proofHelp = [
    'text' => 'برای این تسک، توضیح متنی دقیق حداقل ۱۰ کاراکتر الزامی است.',
    'code' => 'برای این تسک، کد پیگیری، نام کاربری، ایمیل ثبت‌نام یا شناسه لازم است.',
    'url' => 'برای این تسک، یک لینک معتبر و قابل بررسی لازم است.',
    'screenshot' => 'برای این تسک، تصویر JPG/PNG/WEBP الزامی است.',
    'file' => 'برای این تسک، فایل مدرک خصوصی الزامی است؛ تصویر یا PDF تا ۵MB.',
    'video' => 'برای این تسک، فعلاً لینک ویدیو الزامی است؛ آپلود ویدیو در این فاز فعال نیست.',
];
$proofDescription = trim((string)($submission->proof_description ?? ''));
ob_start();
?>

<div id="customTaskProofRoot"
     class="earn-wrap task-market-wrap"
     data-submit-url="<?= e(url('/custom-tasks/submissions/' . $submissionId . '/submit-proof-action')) ?>"
     data-return-url="<?= e(url('/custom-tasks/my-submissions')) ?>"
     data-proof-type="<?= e($proofType) ?>"
     data-csrf="<?= e(csrf_token()) ?>">
    <section class="earn-hero task-market-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon"><i class="material-icons">task_alt</i></div>
            <div>
                <div class="earn-hero__eyebrow">Custom Task Proof</div>
                <h1 class="earn-hero__title"><?= e($submission->task_title ?? 'ارسال مدرک انجام') ?></h1>
                <p class="earn-hero__sub">CustomTask بر اساس قرارداد مدرک کار می‌کند. برای این اجرا نوع مدرک الزامی: <strong><?= e($proofLabels[$proofType] ?? $proofType) ?></strong></p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/tasks?type=custom_task') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازار تسک‌ها</a>
            <a href="<?= url('/custom-tasks/my-submissions') ?>" class="earn-btn earn-btn-ghost">اجراهای من</a>
        </div>
    </section>

    <div class="earn-hub-layout">
        <?php $activeSpoke = 'custom'; include view_path('user.tasks._earn-nav'); ?>
        <main class="earn-hub-main">
            <section class="earn-stats">
                <div class="earn-stat earn-stat--gold"><div class="earn-stat__icon"><i class="material-icons">payments</i></div><div><span class="earn-stat__lbl">پاداش</span><span class="earn-stat__val earn-num"><?= number_format($reward) ?></span><span class="earn-stat__unit"><?= e($submission->reward_currency ?? $submission->currency ?? 'irt') ?></span></div></div>
                <div class="earn-stat earn-stat--green"><div class="earn-stat__icon"><i class="material-icons">hourglass_empty</i></div><div><span class="earn-stat__lbl">وضعیت اجرا</span><span class="earn-stat__val"><?= e($status) ?></span><span class="earn-stat__unit">#<?= e((string)$submissionId) ?></span></div></div>
                <div class="earn-stat earn-stat--blue"><div class="earn-stat__icon"><i class="material-icons">event</i></div><div><span class="earn-stat__lbl">مهلت ارسال</span><span class="earn-stat__val"><?= !empty($submission->deadline_at) ? e(substr((string)$submission->deadline_at, 0, 16)) : 'نامشخص' ?></span><span class="earn-stat__unit">زمان باقی‌مانده</span></div></div>
                <div class="earn-stat earn-stat--red"><div class="earn-stat__icon"><i class="material-icons">verified_user</i></div><div><span class="earn-stat__lbl">نوع مدرک</span><span class="earn-stat__val"><?= e($proofLabels[$proofType] ?? $proofType) ?></span><span class="earn-stat__unit">schema-based</span></div></div>
            </section>

            <div class="tm-board" style="grid-template-columns:minmax(0,1fr) 380px;">
                <section class="earn-section">
                    <div class="earn-section__header"><div class="earn-section__title"><i class="material-icons">description</i> توضیح تسک و مدرک قابل قبول</div></div>
                    <div class="earn-section__body">
                        <p style="color:var(--earn-text-dim);line-height:2;margin-top:0;"><?= nl2br(e($submission->task_description ?? 'توضیحی ثبت نشده است.')) ?></p>
                        <div class="earn-alert earn-alert-info"><i class="material-icons">assignment_turned_in</i><div><strong>دستورالعمل مدرک:</strong><br><?= nl2br(e($proofDescription !== '' ? $proofDescription : ($proofHelp[$proofType] ?? 'مدرک انجام تسک را ارسال کنید.'))) ?></div></div>
                        <div class="tm-steps">
                            <div class="tm-step"><div>۱</div><p>تسک را دقیقاً طبق توضیحات کارفرما انجام دهید.</p></div>
                            <div class="tm-step"><div>۲</div><p>فقط نوع مدرک تعیین‌شده را ارسال کنید؛ مدرک اشتباه قابل رد شدن است.</p></div>
                            <div class="tm-step"><div>۳</div><p>مدرک تکراری مثل کد/لینک/فایل مشابه برای همان تسک رد می‌شود.</p></div>
                            <div class="tm-step"><div>۴</div><p>بعد از ارسال، وضعیت به «ارسال شده» تغییر می‌کند و منتظر بررسی کارفرما می‌ماند.</p></div>
                        </div>
                    </div>
                </section>

                <aside class="tm-detail-panel" style="position:static;">
                    <div class="tm-detail-head"><span class="tm-badge tm-badge-green">Proof</span><strong><?= e($proofLabels[$proofType] ?? 'ثبت مدرک') ?></strong></div>
                    <div class="tm-detail-body">
                        <?php if ($status !== 'in_progress'): ?>
                            <div class="earn-alert earn-alert-warning"><i class="material-icons">info</i><div>این اجرا در وضعیت «<?= e($status) ?>» است و ارسال مدرک جدید از این فرم فعال نیست.</div></div>
                        <?php else: ?>
                            <form id="customTaskProofForm" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" name="task_execution_id" value="<?= e((string)$submissionId) ?>">
                                <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">

                                <?php if ($proofType === 'text'): ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>متن مدرک *</label><textarea name="proof_text" class="earn-textarea" rows="6" placeholder="توضیح دقیق انجام تسک..."></textarea><small class="earn-form-text">حداقل ۱۰ کاراکتر.</small></div>
                                <?php elseif ($proofType === 'code'): ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>کد/شناسه مدرک *</label><input type="text" name="proof_code" class="earn-input" placeholder="کد پیگیری، نام کاربری، ایمیل ثبت‌نام..."></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>توضیح اختیاری</label><textarea name="proof_text" class="earn-textarea" rows="3" placeholder="اگر لازم است توضیح تکمیلی بنویسید."></textarea></div>
                                <?php elseif ($proofType === 'url'): ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>لینک مدرک *</label><input type="url" name="proof_url" class="earn-input" placeholder="https://..." dir="ltr"></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>توضیح اختیاری</label><textarea name="proof_text" class="earn-textarea" rows="3" placeholder="توضیح تکمیلی..."></textarea></div>
                                <?php elseif ($proofType === 'video'): ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>لینک ویدیو یا فایل ویدیو *</label><input type="url" name="proof_url" class="earn-input" placeholder="https://..." dir="ltr"><small class="earn-form-text">اگر لینک ندارید، فایل ویدیو را پایین آپلود کنید.</small></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>فایل ویدیو اختیاری</label><input type="file" name="proof_file" class="earn-input" accept=".mp4,.webm,.mov,video/mp4,video/webm,video/quicktime"><small class="earn-form-text">mp4, webm, mov تا ۳۰MB.</small></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>توضیح اختیاری</label><textarea name="proof_text" class="earn-textarea" rows="3" placeholder="توضیح تکمیلی..."></textarea></div>
                                <?php elseif ($proofType === 'screenshot'): ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>فایل اسکرین‌شات *</label><input type="file" name="proof_file" class="earn-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small class="earn-form-text">JPG, PNG, WEBP تا ۵MB.</small></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>توضیح اختیاری</label><textarea name="proof_text" class="earn-textarea" rows="3" placeholder="توضیح تکمیلی..."></textarea></div>
                                <?php else: ?>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>فایل مدرک *</label><input type="file" name="proof_file" class="earn-input" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"><small class="earn-form-text">تصویر یا PDF تا ۵MB.</small></div>
                                    <div class="earn-form-group" style="margin-bottom:12px;"><label>توضیح اختیاری</label><textarea name="proof_text" class="earn-textarea" rows="3" placeholder="توضیح تکمیلی..."></textarea></div>
                                <?php endif; ?>

                                <div class="earn-actions"><button type="submit" class="earn-btn earn-btn-primary" id="customProofSubmitBtn"><i class="material-icons">send</i> ارسال مدرک برای بررسی</button></div>
                            </form>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userearn.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usercustomtaskproof.js') . '"></script>';
$content = ob_get_clean();
include view_path('layouts.user');
?>
