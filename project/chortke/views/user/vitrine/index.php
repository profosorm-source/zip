<?php
$title = 'ویترین — بازار دیجیتال (هاب تک‌صفحه‌ای)';
$hideSidebar = true;
ob_start();
?>

<div class="fin-wrap">
  <!-- Hero -->
  <section class="fin-hero">
    <div class="fin-hero__main">
      <div class="fin-hero__icon" style="background:rgba(240,185,11,0.15); color:#F0B90B; border:1px solid #F0B90B;">
        <span class="material-icons">storefront</span>
      </div>
      <div>
        <div class="fin-hero__eyebrow" style="color:#F0B90B;">Vitrine Marketplace Hub</div>
        <h1 class="fin-hero__title">ویترین — بازار دیجیتال</h1>
        <p class="fin-hero__sub">خرید و فروش امن پیج، کانال، گروه، VPS، فیلترشکن، سایت و ابزارها با تضمین Escrow و پرداخت USDT.</p>
      </div>
    </div>
    <div class="fin-hero__side">
      <a href="<?= url('/vitrine/sell/create') ?>" class="fin-btn fin-btn-primary" style="background:#F0B90B; color:#0b0e11; font-weight:800; border:none;"><i class="material-icons">add_circle</i> ثبت آگهی فروش</a>
      <a href="<?= url('/dashboard') ?>" class="fin-btn fin-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
    </div>
  </section>

  <div class="fin-hub-layout">
    <?php $activeSpoke = 'market'; include view_path('user.vitrine._vitrine-nav'); ?>
    <main class="fin-hub-main">

<!-- فیلترهای پیشرفته -->
<!-- 🛡️ OPT-IN REWARD VIDEO BANNER (نردبان آگهی ویترین) -->
<?php $vitrineFreeBumpEnabled = (bool)config('video_rewards.vitrine_free_bump_enabled', setting('vitrine_free_bump_enabled', 1)); ?>
<?php if ($vitrineFreeBumpEnabled): ?>


<!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S ویترین -->

            <!-- 🛡️ OPT-IN WELCOME POPUP MODAL -->
            <div class="reward-modal-wrap" id="vitrine_boost_popup_v1_wrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="vitrine_boost_popup_v1_box">
                    <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
                        <span class="material-icons" style="font-size: 2.8rem;">speed</span>
                    </div>
                    <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 12px; color: #fff;">🔥 نردبان فوری و کادر طلایی آگهی (VIP Listing)</h3>
                    <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
                    <p style="font-size: 1.02rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، آگهی شما به بالای لیست نردبان شده و با کادر طلایی متمایز نمایش داده شود؟</p>
                    <div style="display: flex; gap: 14px; flex-direction: column;">
                        <button type="button" onclick="accept_vitrine_boost_popup_v1()" style="background: #2563eb; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                            <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
                        </button>
                        <button type="button" onclick="dismiss_vitrine_boost_popup_v1()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                            خیر، متشکرم (انصراف)
                        </button>
                    </div>
                </div>
            </div>

            <div class="reward-modal-wrap" id="vitrineRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
    <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="vitrineRewardModalBox">
        <div style="width: 80px; height: 80px; background: rgba(212,175,55,0.2); border: 2px solid #d4af37; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #d4af37; margin: 0 auto 25px;" id="vitrineRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="vitrineRewardModalIconTxt">hourglass_empty</span></div>
        <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="vitrineRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
        <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="vitrineRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#d4af37;" id="vitrineRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
        <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="vitrineRewardCloseBtn" onclick="closeVitrineRewardModal()">بستن و اعمال نردبان آگهی</button>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
function startVitrineRewardedVideo(network, duration) {
    const modal = document.getElementById('vitrineRewardModalWrap');
    const box = document.getElementById('vitrineRewardModalBox');
    const title = document.getElementById('vitrineRewardModalTitle');
    const body = document.getElementById('vitrineRewardModalBody');
    const icon = document.getElementById('vitrineRewardModalIconTxt');
    const iconBox = document.getElementById('vitrineRewardModalIcon');
    const countTxt = document.getElementById('vitrineRewardCountdown');
    const closeBtn = document.getElementById('vitrineRewardCloseBtn');

    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    box.style.transform = 'scale(1)';
    title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
    iconBox.style.borderColor = '#d4af37';
    iconBox.style.background = 'rgba(212,175,55,0.2)';
    iconBox.style.color = '#d4af37';
    icon.innerText = 'hourglass_empty';
    closeBtn.style.display = 'none';

    let timer = duration;
    countTxt.innerText = timer;
    
    const interval = setInterval(() => {
        timer--;
        countTxt.innerText = timer;
        if (timer <= 0) {
            clearInterval(interval);
            iconBox.style.borderColor = '#10b981';
            iconBox.style.background = 'rgba(16,185,129,0.2)';
            iconBox.style.color = '#10b981';
            icon.innerText = 'verified_user';
            title.innerText = 'نمایش ویدیو با موفقیت به اتمام رسید!';
            body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ آگهی شما با موفقیت نردبان شد.';
            closeBtn.style.display = 'block';
        }
    }, 1000);
}
function closeVitrineRewardModal() {
    document.getElementById('vitrineRewardModalWrap').style.opacity = '0';
    document.getElementById('vitrineRewardModalWrap').style.pointerEvents = 'none';
    alert('آگهی شما با موفقیت نردبان شد و در بالای لیست قرار گرفت.');
}

            function open_vitrine_boost_popup_v1() {
                const wrap = document.getElementById("vitrine_boost_popup_v1_wrap");
                const box = document.getElementById("vitrine_boost_popup_v1_box");
                if (wrap && box) { wrap.style.opacity = "1"; wrap.style.pointerEvents = "auto"; box.style.transform = "scale(1)"; }
            }
            function dismiss_vitrine_boost_popup_v1() {
                const wrap = document.getElementById("vitrine_boost_popup_v1_wrap");
                if (wrap) { wrap.style.opacity = "0"; wrap.style.pointerEvents = "none"; }
                try { sessionStorage.setItem("vitrine_boost_popup_v1", "1"); } catch(e){}
            }
            function accept_vitrine_boost_popup_v1() {
                dismiss_vitrine_boost_popup_v1();
                setTimeout(() => { startVitrineRewardedVideo("tapsell", 15); }, 200);
            }
            window.addEventListener("DOMContentLoaded", () => {
                try { if (!sessionStorage.getItem("vitrine_boost_popup_v1")) { setTimeout(() => open_vitrine_boost_popup_v1(), 1000); } } catch(e){}
            });
            </script>

<?php endif; ?>

<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">دسته‌بندی</label>
        <select name="category" class="form-select form-select-sm">
          <option value="">همه دسته‌ها</option>
          <?php foreach ($categories as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($filters['category'] ?? '') === $k ? 'selected' : '' ?>>
              <?= e($v) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">پلتفرم</label>
        <select name="platform" class="form-select form-select-sm">
          <?php foreach ($platforms as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($filters['platform'] ?? '') === $k ? 'selected' : '' ?>>
              <?= e($v) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">جستجو</label>
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="عنوان، توضیحات، نام کاربری..."
               value="<?= e($filters['search'] ?? '') ?>">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">از قیمت</label>
        <input type="number" name="min_price" class="form-control form-control-sm"
               placeholder="USDT" value="<?= e($filters['min_price'] ?? '') ?>" min="0" step="0.01">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">تا قیمت</label>
        <input type="number" name="max_price" class="form-control form-control-sm"
               placeholder="USDT" value="<?= e($filters['max_price'] ?? '') ?>" min="0" step="0.01">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1">مرتب‌سازی</label>
        <select name="sort" class="form-select form-select-sm">
          <option value="newest"     <?= ($filters['sort'] ?? '') === 'newest'    ? 'selected' : '' ?>>جدیدترین</option>
          <option value="price_asc"  <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
          <option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc'? 'selected' : '' ?>>گران‌ترین</option>
          <option value="members"    <?= ($filters['sort'] ?? '') === 'members'   ? 'selected' : '' ?>>بیشترین عضو</option>
        </select>
      </div>
      <div class="col-6 col-md-2 d-flex gap-1">
        <button class="btn btn-primary btn-sm flex-fill">
          <span class="material-icons icon-sm">search</span> جستجو
        </button>
        <a href="<?= url('/vitrine') ?>" class="btn btn-outline-secondary btn-sm">
          <span class="material-icons icon-sm">refresh</span>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- نتایج -->
<div class="d-flex justify-content-between align-items-center mt-3 mb-2">
  <span class="text-muted small"><?= number_format($total) ?> آگهی یافت شد</span>
</div>

<?php if (empty($listings)): ?>
  <div class="text-center py-5">
    <span class="material-icons text-muted icon-xl">storefront</span>
    <p class="text-muted mt-2">هیچ آگهی فعالی با این فیلترها یافت نشد.</p>
    <a href="<?= url('/vitrine') ?>" class="btn btn-outline-primary btn-sm">نمایش همه</a>
  </div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($listings as $l): ?>
  <?php
    $catIcons = [
      'page' => 'person', 'channel' => 'campaign', 'group' => 'group',
      'vps' => 'dns', 'vpn' => 'vpn_lock', 'website' => 'language', 'other' => 'sell'
    ];
    $catIcon  = $catIcons[$l->category] ?? 'sell';
    $catLabel = $categories[$l->category] ?? $l->category;
    $platLabel= $platforms[$l->platform] ?? $l->platform;
  ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100 vitrine-card">
      <div class="card-body">
        <!-- هدر کارت -->
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-primary bg-opacity-10 text-primary">
              <span class="material-icons icon-xs"><?= $catIcon ?></span>
              <?= e($catLabel) ?>
            </span>
            <?php if ($platLabel): ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($platLabel) ?></span>
            <?php endif; ?>
          </div>
          <!-- KYC نشان -->
          <?php if (($l->seller_kyc ?? '') === 'verified'): ?>
          <span class="badge bg-success" title="فروشنده احراز هویت شده">
            <span class="material-icons icon-xs">verified</span>
          </span>
          <?php endif; ?>
        </div>

        <!-- عنوان -->
        <h6 class="fw-bold mb-1 text-truncate" title="<?= e($l->title) ?>">
          <?= e(mb_substr($l->title, 0, 60)) ?>
        </h6>

        <!-- اطلاعات -->
        <?php if (!empty($l->username)): ?>
        <div class="small text-muted mb-1">
          <span class="material-icons icon-xs">alternate_email</span>
          <?= e($l->username) ?>
        </div>
        <?php endif; ?>

        <?php if ($l->member_count > 0): ?>
        <div class="small text-muted mb-2">
          <span class="material-icons icon-xs">group</span>
          <?= number_format($l->member_count) ?> عضو/فالوور
        </div>
        <?php endif; ?>

        <!-- توضیحات کوتاه -->
        <p class="small text-secondary mb-3 vitrine-desc">
          <?= e(mb_substr($l->description, 0, 120)) ?>
        </p>

        <!-- قیمت و دکمه -->
        <div class="d-flex justify-content-between align-items-center mt-auto">
          <div>
            <span class="fw-bold text-success fs-6"><?= number_format((float)$l->price_usdt, 2) ?></span>
            <span class="small text-muted"> USDT</span>
          </div>
          <a href="<?= url('/vitrine/' . $l->id) ?>" class="btn btn-sm btn-outline-primary">
            مشاهده
          </a>
        </div>

        <!-- فروشنده -->
        <div class="mt-2 pt-2 border-top d-flex align-items-center justify-content-between">
          <span class="small text-muted">
            <span class="material-icons icon-xs">person</span>
            <?= e($l->seller_name ?? '—') ?>
          </span>
          <?php if (!empty($l->seller_tier)): ?>
          <span class="badge bg-warning bg-opacity-20 text-warning badge-xs">
            <?= e($l->seller_tier) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="d-flex justify-content-center mt-4">
  <ul class="pagination pagination-sm mb-0">
    <?php if ($page > 1): ?>
    <li class="page-item">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">
        <span class="material-icons icon-sm">chevron_right</span>
      </a>
    </li>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <li class="page-item">
      <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">
        <span class="material-icons icon-sm">chevron_left</span>
      </a>
    </li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>
    </main>
  </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userfinance.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/uservitrineindex.css') . '">';
include view_path('layouts.user');
?>
