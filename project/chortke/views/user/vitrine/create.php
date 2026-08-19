<?php
$title = ($listingType ?? 'sell') === 'buy' ? 'ثبت درخواست خرید در ویترین' : 'ثبت آگهی فروش در ویترین';
ob_start();
$isBuy = ($listingType ?? 'sell') === 'buy';
?>

<section class="fin-hero">
    <div class="fin-hero__main">
      <div class="fin-hero__icon" style="background:rgba(240,185,11,0.15); color:#F0B90B; border:1px solid #F0B90B;">
        <span class="material-icons">storefront</span>
      </div>
      <div>
        <div class="fin-hero__eyebrow" style="color:#F0B90B;">Vitrine Marketplace Hub</div>
        <h1 class="fin-hero__title">ثبت آگهی فروش در ویترین</h1>
        <p class="fin-hero__sub">فروش سریع و امن پیج، کانال، گروه، سرور و ابزارها با تضمین سیستم Escrow چرتکه</p>
      </div>
    </div>
    <div class="fin-hero__side">
      <a href="<?= url('/vitrine') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار ویترین</a>
      <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
    </div>
  </section>

  <div class="fin-hub-layout">
    <?php $activeSpoke = "create"; include view_path("user.vitrine._vitrine-nav"); ?>
    <main class="fin-hub-main">
  <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons icon-sm">arrow_forward</span> بازگشت
  </a>
</div>

<!-- راهنما -->
<div class="alert alert-info mt-3 d-flex gap-2 align-items-start">
  <span class="material-icons icon-lg mt-1">info</span>
  <div class="small">
    <?php if ($isBuy): ?>
      <strong>درخواست خرید:</strong> مشخص کنید دنبال چه چیزی هستید. فروشندگانی که آگهی مناسب دارند با شما تماس می‌گیرند.
    <?php else: ?>
      <strong>آگهی فروش:</strong> توضیحات کاملی از چیزی که می‌فروشید بنویسید.
      پس از بررسی توسط تیم ویترین، آگهی شما منتشر می‌شود. <br>
      <strong>نکته:</strong> تصویر پذیرفته نمی‌شود — همه اطلاعات باید به‌صورت متنی باشند.
    <?php endif; ?>
  </div>
</div>

<form action="<?= url('/vitrine/store') ?>" method="POST" class="mt-3" id="vitrineForm">
  <?= csrf_field() ?>
  <input type="hidden" name="listing_type" value="<?= e($listingType ?? 'sell') ?>">

  <div class="row g-3">

    <!-- ستون اصلی -->
    <div class="col-lg-8">

      <!-- اطلاعات پایه -->
      <div class="card mb-3">
        <div class="card-header">
          <h6 class="card-title mb-0">
            <span class="material-icons icon-lg align-middle">info</span>
            اطلاعات پایه
          </h6>
        </div>
        <div class="card-body">
          <div class="row g-3">

            <!-- دسته‌بندی -->
            <div class="col-md-6">
              <label class="form-label fw-medium">
                دسته‌بندی <span class="text-danger">*</span>
              </label>
              <select name="category" class="form-select" required id="categorySelect">
                <option value="">انتخاب کنید...</option>
                <?php foreach ($categories as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- پلتفرم -->
            <div class="col-md-6" id="platformWrap">
              <label class="form-label fw-medium">پلتفرم</label>
              <select name="platform" class="form-select" id="platformSelect">
                <?php foreach ($platforms as $k => $v): ?>
                  <?php if ($k === '') continue; ?>
                  <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">برای VPS، فیلترشکن یا سایت انتخاب پلتفرم اختیاری است</div>
            </div>

            <!-- عنوان -->
            <div class="col-12">
              <label class="form-label fw-medium">
                عنوان آگهی <span class="text-danger">*</span>
              </label>
              <input type="text" name="title" class="form-control" required
                     maxlength="300" minlength="5"
                     placeholder="<?= $isBuy ? 'مثال: خریدار کانال تلگرام با بیش از ۵۰۰۰ عضو' : 'مثال: فروش کانال تلگرام ۱۰ هزار عضو فارسی' ?>">
              <div class="form-text"><span id="titleCount">0</span>/300 کاراکتر</div>
            </div>

          </div>
        </div>
      </div>

      <!-- توضیحات -->
      <div class="card mb-3">
        <div class="card-header">
          <h6 class="card-title mb-0">
            <span class="material-icons icon-lg align-middle">article</span>
            توضیحات کامل <span class="text-danger">*</span>
          </h6>
        </div>
        <div class="card-body">
          <textarea name="description" class="form-control" rows="7" required
                    minlength="20" id="descTextarea"
                    placeholder="<?= $isBuy
                      ? "مثال:\n- دنبال کانال تلگرام با حداقل ۵۰۰۰ عضو واقعی فارسی هستم\n- موضوع: خبری، علمی، سرگرمی\n- بودجه: تا ۵۰ USDT\n- تاریخ ساخت: حداقل ۲ سال"
                      : "مثال:\n- کانال تلگرام با ۱۰,۰۰۰ عضو فارسی\n- موضوع: آموزشی - برنامه‌نویسی\n- میانگین بازدید هر پست: ۲۰۰۰\n- تاریخ تأسیس: فروردین ۱۴۰۰\n- دلیل فروش: عدم وقت کافی\n- امکانات: ادمین اصلی، پسورد ایمیل متصل"
                    ?>"></textarea>
          <div class="form-text mt-1">
            <span id="descCount">0</span> کاراکتر — حداقل ۲۰ کاراکتر
          </div>
        </div>
      </div>

      <!-- مشخصات فنی -->
      <div class="card mb-3">
        <div class="card-header">
          <h6 class="card-title mb-0">
            <span class="material-icons icon-lg align-middle">settings</span>
            مشخصات فنی <span class="text-muted fw-normal text-12">(اختیاری)</span>
          </h6>
        </div>
        <div class="card-body">
          <textarea name="specs" class="form-control" rows="4"
                    placeholder="<?= $isBuy
                      ? "مشخصات مورد نیاز:\n- حداقل ممبر: ...\n- حداقل بازدید: ...\n- موضوع پرفری: ..."
                      : "مشخصات فنی:\n- نام کاربری فعلی: ...\n- تعداد ادمین: ...\n- سابقه درآمد: ...\n- وضعیت کپی‌رایت: ..."
                    ?>"></textarea>
          <div class="form-text">اطلاعات تخصصی‌تر که در توضیحات جای نمی‌گیرند</div>
        </div>
      </div>

    </div>

    <!-- ستون جانبی -->
    <div class="col-lg-4">

      <!-- آمار -->
      <div class="card mb-3" id="statsCard">
        <div class="card-header">
          <h6 class="card-title mb-0">
            <span class="material-icons icon-lg align-middle">bar_chart</span>
            آمار و ارقام
          </h6>
        </div>
        <div class="card-body">
          <div class="mb-3" id="usernameWrap">
            <label class="form-label fw-medium">نام کاربری / آدرس</label>
            <input type="text" name="username" class="form-control form-control-sm"
                   placeholder="@username یا t.me/...">
          </div>
          <div class="mb-3" id="memberWrap">
            <label class="form-label fw-medium">تعداد عضو / فالوور</label>
            <input type="number" name="member_count" class="form-control form-control-sm"
                   min="0" placeholder="0">
          </div>
          <div class="mb-0" id="dateWrap">
            <label class="form-label fw-medium">تاریخ تأسیس / ساخت</label>
            <input type="date" name="creation_date" class="form-control form-control-sm"
                   max="<?= date('Y-m-d') ?>">
          </div>
        </div>
      </div>

      <!-- قیمت -->
      <div class="card mb-3">
        <div class="card-header">
          <h6 class="card-title mb-0">
            <span class="material-icons icon-lg align-middle">payments</span>
            قیمت (USDT)
          </h6>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-medium">
              <?= $isBuy ? 'حداکثر بودجه' : 'قیمت فروش' ?>
              <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <input type="number" name="price_usdt" class="form-control" required
                     min="1" step="0.01"
                     placeholder="0.00">
              <span class="input-group-text">USDT</span>
            </div>
          </div>
          <?php if (!$isBuy): ?>
          <div class="mb-0">
            <label class="form-label fw-medium">حداقل قیمت قابل قبول</label>
            <div class="input-group">
              <input type="number" name="min_price_usdt" class="form-control form-control-sm"
                     min="0" step="0.01" placeholder="اختیاری">
              <span class="input-group-text">USDT</span>
            </div>
            <div class="form-text">اگر خریدار قیمت پایین‌تری پیشنهاد داد، تا این حد قابل مذاکره است</div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- دکمه ارسال -->
      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary" id="submitBtn">
          <span class="material-icons icon-lg align-middle">send</span>
          <?= $isBuy ? 'ثبت درخواست خرید' : 'ثبت آگهی فروش' ?>
        </button>
        <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">انصراف</a>
      </div>

      <!-- راهنمای مختصر -->
      <div class="card mt-3 border-0 bg-light">
        <div class="card-body py-2 px-3">
          <p class="small text-muted mb-1 fw-medium">قوانین ویترین:</p>
          <ul class="small text-muted mb-0 ps-3">
            <li>فقط محصولات متنی پذیرفته می‌شوند</li>
            <li>کمیسیون: <?= e(setting('vitrine_commission_percent', '5')) ?>٪ از مبلغ معامله</li>
            <li>پس از پرداخت، <?= e(setting('vitrine_escrow_days', '3')) ?> روز مهلت تست</li>
            <li>پشتیبانی در صورت اختلاف</li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</form>

    </main>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">' . (!empty($styles) ? $styles : '');
$styles = '';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/uservitrinecreate.js') . '"></script>';
include view_path('layouts.user');
?>
