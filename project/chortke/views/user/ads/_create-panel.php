<div class="ads-create-layout">
  <aside class="ads-create-tips">
    <h2><span class="material-icons">tips_and_updates</span> راهنمای ساده ثبت تبلیغ</h2>
    <ul>
      <li>بودجه کمپین تا زمان مصرف واقعی یا بازگشت وجه، به‌صورت امن نگهداری می‌شود.</li>
      <li>برای بنر، نوتیفیکیشن و تبلیغ ویدیویی، هزینه بعد از نمایش، کلیک یا مشاهده واقعی کم می‌شود.</li>
      <li>در تبلیغات شبکه‌های اجتماعی، کاربر مدرک دستی نمی‌فرستد؛ سیستم با امتیاز رفتاری نتیجه را بررسی می‌کند.</li>
      <li>در تسک سفارشی، شما نوع مدرک موردنیاز را مشخص می‌کنید و انجام‌دهنده همان را ارسال می‌کند.</li>
    </ul>
  </aside>

  <div class="wizard-container" id="adsWizard"
       data-type-info-url="<?= e(url('/ads/api/type-info')) ?>"
       data-validate-field-url="<?= e(url('/ads/api/validate-field')) ?>"
       data-preview-cost-url="<?= e(url('/ads/api/preview-cost')) ?>"
       data-store-url="<?= e(url('/ads/store')) ?>"
       data-index-url="<?= e(url('/ads')) ?>">

    <div class="wizard-stepper" id="wizardStepper" data-step="<?= (int)($currentStep ?? 1) ?>">
      <div class="step-node <?= ($currentStep ?? 1) >= 1 ? 'active' : '' ?>" id="nodeStep1">
        <div class="step-bubble">۱</div><span class="step-label">انتخاب نوع</span>
      </div>
      <div class="step-node <?= ($currentStep ?? 1) >= 2 ? 'active' : '' ?>" id="nodeStep2">
        <div class="step-bubble">۲</div><span class="step-label">جزئیات تبلیغ</span>
      </div>
      <div class="step-node <?= ($currentStep ?? 1) >= 3 ? 'active' : '' ?>" id="nodeStep3">
        <div class="step-bubble">۳</div><span class="step-label">بررسی هزینه</span>
      </div>
      <div class="step-node <?= ($currentStep ?? 1) >= 4 ? 'active' : '' ?>" id="nodeStep4">
        <div class="step-bubble">۴</div><span class="step-label">ثبت نهایی</span>
      </div>
    </div>

    <div class="wizard-glass">
      <div class="step-loading-overlay" id="stepLoading">
        <div class="text-center">
          <div class="wizard-spinner"></div>
          <p class="text-muted small fw-semibold">در حال بارگذاری...</p>
        </div>
      </div>

      <div id="step1" class="wizard-panel <?= ($currentStep ?? 1) == 1 ? 'active' : '' ?>">
        <div class="wizard-panel-header">
          <span class="material-icons icon-gradient">category</span>
          <span>نوع تبلیغ خود را انتخاب کنید</span>
        </div>
        <div class="wizard-panel-body">
          <div class="type-grid">
            <div class="type-card" data-type="social_task" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">groups</span>
              <h5>تبلیغات شبکه‌های اجتماعی</h5>
              <p>فالو، لایک، کامنت یا عضویت در شبکه‌هایی مثل اینستاگرام و تلگرام.</p>
            </div>
            <div class="type-card" data-type="adtube" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">smart_display</span>
              <h5>تبلیغ ویدیویی AdTube</h5>
              <p>کاربران ویدیوی شما را می‌بینند و بعد از مشاهده معتبر، هزینه مصرف می‌شود.</p>
            </div>
            <div class="type-card" data-type="custom_task" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">assignment_turned_in</span>
              <h5>تسک‌های سفارشی</h5>
              <p>ثبت‌نام، نصب برنامه، ارسال کد، عکس یا هر کاری که خودتان تعریف می‌کنید.</p>
            </div>
            <div class="type-card" data-type="seo" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">travel_explore</span>
              <h5>سئو و کلیک</h5>
              <p>کاربر سایت شما را جستجو و بازدید می‌کند؛ پرداخت بر اساس کیفیت تعامل است.</p>
            </div>
            <div class="type-card" data-type="banner" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">view_carousel</span>
              <h5>بنر تبلیغاتی</h5>
              <p>تصویر بنر شما در جایگاه‌های سایت نمایش داده می‌شود.</p>
            </div>
            <div class="type-card" data-type="notification" role="button" tabindex="0">
              <span class="check-indicator"><span class="material-icons icon-sm">check</span></span>
              <span class="material-icons">notifications_active</span>
              <h5>پیام تبلیغاتی</h5>
              <p>پیام هدفمند در اعلان‌ها یا Push کاربران ارسال می‌شود.</p>
            </div>
          </div>
        </div>
      </div>

      <div id="step2" class="wizard-panel <?= ($currentStep ?? 1) == 2 ? 'active' : '' ?>">
        <div class="wizard-panel-header">
          <span class="material-icons icon-gradient" id="step2Icon">edit</span>
          <span id="step2Title">جزئیات تبلیغ</span>
        </div>
        <div class="wizard-panel-body">
          <form id="dynamicForm" class="wizard-dynamic-form <?= ($currentStep ?? 1) == 2 ? 'active' : '' ?>" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
          </form>
        </div>
      </div>

      <div id="step3" class="wizard-panel">
        <div class="wizard-panel-header">
          <span class="material-icons icon-gradient">account_balance_wallet</span>
          <span>بررسی هزینه و بودجه</span>
        </div>
        <div class="wizard-panel-body">
          <p class="text-muted mb-3">قبل از ثبت، هزینه نهایی و مبلغی که از کیف پول کم می‌شود را اینجا می‌بینید.</p>
          <div id="budgetPreview" class="budget-preview-card"></div>
        </div>
      </div>

      <div id="step4" class="wizard-panel">
        <div class="wizard-panel-header">
          <span class="material-icons icon-gradient">fact_check</span>
          <span>تأیید نهایی</span>
        </div>
        <div class="wizard-panel-body">
          <div class="alert alert-info wizard-info-alert">
            <span class="material-icons icon-sm">info</span>
            با زدن دکمه ثبت، کمپین شما ساخته می‌شود و مبلغ لازم به‌صورت امن از کیف پول رزرو می‌شود.
          </div>
        </div>
      </div>

      <div id="wizardSuccess" class="wizard-success">
        <div class="success-icon"><span class="material-icons icon-xl">check</span></div>
        <h3 class="text-success fw-bold">تبلیغ با موفقیت ثبت شد!</h3>
        <p class="text-muted">در حال انتقال به بخش مدیریت تبلیغات...</p>
      </div>

      <div class="wizard-actions<?= ($currentStep ?? 1) >= 4 ? ' d-none' : '' ?>" id="wizardActions">
        <button type="button" class="wizard-btn wizard-btn-secondary<?= ($currentStep ?? 1) == 1 ? ' vis-hidden' : '' ?>" id="btnPrev">
          <span class="material-icons icon-sm">arrow_forward</span> مرحله قبل
        </button>
        <button type="button" class="wizard-btn wizard-btn-primary" id="btnNext" <?= ($currentStep ?? 1) == 2 ? '' : 'disabled' ?>>
          مرحله بعد <span class="material-icons icon-sm">arrow_back</span>
        </button>
        <button type="button" class="wizard-btn wizard-btn-primary d-none" id="btnSubmit">
          <span class="material-icons icon-sm">check_circle</span> تأیید نهایی و ثبت
        </button>
      </div>
    </div>
  </div>
</div>
