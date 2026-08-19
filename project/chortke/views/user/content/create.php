<?php
$title = 'ارسال محتوای جدید';
$hideSidebar = true;
$bodyClass = trim((string)($bodyClass ?? '') . ' content-hub-page');
$activeSpoke = 'submit';
ob_start();
?>
<div class="cnt-wrap">
  <section class="cnt-hero">
    <div>
      <div class="cnt-kicker"><span class="material-icons">upload_file</span> ارسال محتوا</div>
      <h1>ثبت ویدیوی جدید</h1>
      <p>لینک ویدیو را وارد کنید. بعد از بررسی مدیریت، در صورت تأیید و انتشار وارد چرخه درآمد می‌شود.</p>
    </div>
    <div class="cnt-actions"><a href="<?= e(url('/content')) ?>" class="cnt-btn cnt-btn-ghost"><span class="material-icons">arrow_forward</span> بازگشت</a></div>
  </section>
  <div class="cnt-hub-layout">
    <?php include view_path('user.content._content-nav'); ?>
    <main class="cnt-hub-main">
      <section class="cnt-form-card">
        <div class="cnt-form-card__head"><h2><span class="material-icons">edit_note</span> اطلاعات محتوا</h2></div>
        <div class="cnt-form-card__body">
          <div class="alert alert-info" role="alert">
            <i class="material-icons" aria-hidden="true">info</i>
            <div>ویدیو می‌تواند در آپارات، یوتیوب یا یک آپلودسنتر معتبر باشد. درآمد از ماه سوم پس از تأیید محاسبه می‌شود.</div>
          </div>
          <form id="contentForm" method="POST" novalidate data-store-url="<?= e(url('/content/store')) ?>" data-index-url="<?= e(url('/content')) ?>" data-csrf="<?= e(csrf_token()) ?>">
            <input type="hidden" name="csrf_token" id="csrf_token" value="<?= csrf_token() ?>">
            <div class="cnt-form-row">
              <div class="form-group">
                <label for="platform">پلتفرم <span class="text-danger">*</span></label>
                <select name="platform" id="platform" class="form-control" required>
                  <option value="">انتخاب کنید...</option>
                  <option value="aparat">آپارات</option>
                  <option value="youtube">یوتیوب</option>
                  <option value="upload_center">آپلود سنتر</option>
                </select>
                <small id="platform-help" class="form-text text-muted">محل قرار گرفتن یا دانلود ویدیو را انتخاب کنید.</small>
                <div class="invalid-feedback" id="platform-error"></div>
              </div>
              <div class="form-group">
                <label for="category">دسته‌بندی</label>
                <select name="category" id="category" class="form-control">
                  <option value="">انتخاب کنید...</option>
                  <option value="comedy">طنز و کمدی</option><option value="education">آموزشی</option><option value="tech">تکنولوژی</option><option value="cooking">آشپزی</option><option value="music">موسیقی</option><option value="vlog">ولاگ</option><option value="gaming">بازی</option><option value="art">هنر و خلاقیت</option><option value="sport">ورزشی</option><option value="other">سایر</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label for="video_url">لینک ویدیو <span class="text-danger">*</span></label>
              <input type="url" name="video_url" id="video_url" class="form-control" dir="ltr" placeholder="https://www.youtube.com/watch?v=..." required maxlength="500">
              <small class="form-text text-muted" id="url-hint">لینک کامل ویدیو را از مرورگر کپی کنید.</small>
              <div class="invalid-feedback" id="video_url-error"></div>
            </div>
            <div class="form-group">
              <label for="title">عنوان ویدیو <span class="text-danger">*</span></label>
              <input type="text" name="title" id="title" class="form-control" maxlength="255" minlength="5" placeholder="عنوان ویدیوی خود را وارد کنید" required>
              <small class="form-text text-muted"><span id="title-count">0</span>/255 کاراکتر</small>
              <div class="invalid-feedback" id="title-error"></div>
            </div>
            <div class="form-group">
              <label for="description">توضیحات</label>
              <textarea name="description" id="description" class="form-control" rows="4" maxlength="2000" placeholder="توضیح کوتاهی درباره ویدیو بنویسید..." ></textarea>
              <small class="form-text text-muted"><span id="descCount">0</span>/2000 کاراکتر</small>
            </div>
            <div class="agreement-box">
              <h6><i class="material-icons">gavel</i> تعهدنامه همکاری محتوایی</h6>
              <div class="agreement-text" tabindex="0" role="region" aria-label="متن تعهدنامه"><?= nl2br(e($agreementText ?? '')) ?></div>
              <div class="form-check mt-3">
                <input type="checkbox" name="agreement_accepted" id="agreement_accepted" value="1" class="form-check-input" required>
                <label for="agreement_accepted" class="form-check-label"><strong>تمامی شرایط را مطالعه کردم و می‌پذیرم.</strong></label>
                <div class="invalid-feedback" id="agreement-error">پذیرش تعهدنامه الزامی است.</div>
              </div>
            </div>
            <div class="cnt-actions mt-4">
              <button type="submit" id="submitBtn" class="cnt-btn cnt-btn-primary" disabled aria-busy="false"><i class="material-icons">send</i> ارسال محتوا</button>
              <button type="reset" class="cnt-btn cnt-btn-ghost" id="resetBtn"><i class="material-icons">refresh</i> پاک کردن فرم</button>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercontenthub.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/usercontentcreate.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usercontentcreate.js') . '"></script>';
include view_path('layouts.user');
?>
