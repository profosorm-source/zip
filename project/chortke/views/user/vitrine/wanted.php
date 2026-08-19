<?php
$title = 'ویترین — خریداران (متقاضیان)';
$hideSidebar = true;
ob_start();
?>

<div class="fin-wrap">

<section class="fin-hero">
    <div class="fin-hero__main">
      <div class="fin-hero__icon" style="background:rgba(240,185,11,0.15); color:#F0B90B; border:1px solid #F0B90B;">
        <span class="material-icons">storefront</span>
      </div>
      <div>
        <div class="fin-hero__eyebrow" style="color:#F0B90B;">Vitrine Marketplace Hub</div>
        <h1 class="fin-hero__title">آگهی‌های درخواست خرید (خریداران)</h1>
        <p class="fin-hero__sub">لیست درخواست‌های خرید کاربران؛ در صورتی که دارایی موردنظر را دارید، پیشنهاد فروش دهید</p>
      </div>
    </div>
    <div class="fin-hero__side">
      <a href="<?= url('/vitrine') ?>" class="fin-btn fin-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار ویترین</a>
      <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
    </div>
  </section>

  <div class="fin-hub-layout">
    <?php $activeSpoke = "wanted"; include view_path("user.vitrine._vitrine-nav"); ?>
    <main class="fin-hub-main">
  <div class="d-flex gap-2">
    <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons icon-sm">storefront</span> آگهی‌های فروش
    </a>
    <a href="<?= url('/vitrine/wanted/create') ?>" class="btn btn-info btn-sm text-white">
      <span class="material-icons icon-sm">add</span> ثبت درخواست خرید
    </a>
  </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <select name="category" class="form-select form-select-sm">
          <option value="">همه دسته‌ها</option>
          <?php foreach ($categories as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($filters['category'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select name="platform" class="form-select form-select-sm">
          <?php foreach ($platforms as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($filters['platform'] ?? '') === $k ? 'selected' : '' ?>>
            <?= e($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-9 col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
      </div>
      <div class="col-3 col-md-2">
        <button class="btn btn-primary btn-sm w-100">جستجو</button>
      </div>
    </form>
  </div>
</div>

<?php if (empty($listings)): ?>
<div class="text-center py-5 mt-2">
  <span class="material-icons text-muted icon-xl">search</span>
  <p class="text-muted mt-2">هیچ درخواست خریدی یافت نشد.</p>
</div>
<?php else: ?>

<div class="row g-3 mt-1">
  <?php foreach ($listings as $l): ?>
  <?php
    $catIcons = ['page'=>'person','channel'=>'campaign','group'=>'group',
                 'vps'=>'dns','vpn'=>'vpn_lock','website'=>'language','other'=>'search'];
    $icon  = $catIcons[$l->category]   ?? 'search';
    $cat   = $categories[$l->category] ?? $l->category;
    $plat  = $platforms[$l->platform]  ?? '';
  ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100 border-info border-opacity-25">
      <div class="card-body">
        <!-- هدر -->
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-info bg-opacity-15 text-info">
              <span class="material-icons icon-xs"><?= $icon ?></span>
              <?= e($cat) ?>
            </span>
            <?php if ($plat): ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($plat) ?></span>
            <?php endif; ?>
          </div>
          <span class="badge bg-info text-white badge-xs">خریدار</span>
        </div>

        <!-- عنوان -->
        <h6 class="fw-bold mb-1"><?= e(mb_substr($l->title, 0, 60)) ?></h6>

        <!-- توضیحات -->
        <p class="small text-secondary mb-2 vitrine-desc-3">
          <?= e(mb_substr($l->description, 0, 140)) ?>
        </p>

        <!-- بودجه -->
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="small text-muted">بودجه: </span>
            <span class="fw-bold text-info"><?= number_format((float)$l->price_usdt, 2) ?> USDT</span>
          </div>
          <div class="text-muted small">
            <span class="material-icons icon-xs">person</span>
            <?= e($l->seller_name ?? '—') ?>
            <?php if (($l->seller_kyc ?? '') === 'verified'): ?>
            <span class="material-icons text-success icon-xs" title="KYC">verified</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- دکمه -->
        <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-sm btn-outline-info w-100 mt-2">
          مشاهده و ارسال پیشنهاد
        </a>
      </div>
      <div class="card-footer bg-transparent py-1 px-3">
        <span class="small text-muted">
          <?= e(substr($l->created_at ?? '', 0, 10)) ?>
        </span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (($page ?? 1) > 1): ?>
<nav class="d-flex justify-content-center mt-4">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($i = 1; $i <= ($pages ?? 1); $i++): ?>
    <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

    </main>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '">' . (!empty($styles) ? $styles : '');
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/uservitrineindex.css') . '">';
include view_path('layouts.user');
?>
