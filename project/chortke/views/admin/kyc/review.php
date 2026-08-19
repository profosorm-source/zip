<?php
$verification = $verification ?? ($kyc ?? null);
$v = $verification;
$u = $user ?? null;
$title = 'بررسی KYC #' . (int)($v->id ?? 0);
ob_start();
?>
<div id="kycRoot" data-verify-base="<?= url('/admin/kyc/verify') ?>" data-reject-base="<?= url('/admin/kyc/reject') ?>" data-list-url="<?= url('/admin/kyc') ?>" data-v-id="<?= (int)($v->id ?? 0) ?>"></div>
<?php
$docTypes = ['national_id'=>'کارت ملی','passport'=>'پاسپورت','driving_license'=>'گواهینامه'];
$stMap    = ['pending'=>'badge-warning','under_review'=>'badge-info','verified'=>'badge-success','rejected'=>'badge-danger'];
$stNames  = ['pending'=>'در انتظار','under_review'=>'در بررسی','verified'=>'تأیید شده','rejected'=>'رد شده'];
$s = $v->status ?? 'pending';
?>

<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--blue"><i class="material-icons">verified_user</i></div>
    <div>
      <h1 class="bx-page-header__title">بررسی احراز هویت <span class="bx-page-header__id">#<?= (int)($v->id ?? 0) ?></span></h1>
      <p class="bx-page-header__sub">
        <?= $docTypes[$v->document_type ?? ''] ?? '—' ?>
        &nbsp;·&nbsp; <?= to_jalali($v->created_at ?? '') ?>
        &nbsp;·&nbsp; <span class="bx-badge <?= $stMap[$s] ?? 'badge-muted' ?>"><?= $stNames[$s] ?? $s ?></span>
      </p>
    </div>
  </div>
  <a href="<?= url('/admin/kyc') ?>" class="bx-btn bx-btn--secondary bx-btn--sm"><i class="material-icons">arrow_forward</i>بازگشت</a>
</div>

<div class="bx-review-layout">

  <!-- SIDEBAR -->
  <div class="bx-review-layout__side">

    <!-- User Info -->
    <div class="bx-info-card">
      <div class="bx-info-card__header"><i class="material-icons">person</i><h6>اطلاعات کاربر</h6></div>
      <div class="bx-info-card__body">
        <div class="bx-user-profile bx-user-profile--lg">
          <div class="bx-user-profile__avatar bx-user-profile__avatar--lg">
            <?= e(mb_substr($u->full_name ?? 'ک', 0, 1, 'UTF-8')) ?>
          </div>
          <div class="bx-user-profile__info">
            <strong><?= e($u->full_name ?? '—') ?></strong>
            <small><?= e($u->email ?? '—') ?></small>
            <small><?= e($u->mobile ?? '—') ?></small>
          </div>
        </div>
        <div class="bx-divider"></div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">نام قانونی</span>
          <strong><?= e($v->legal_name ?? '—') ?></strong>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">کد ملی</span>
          <code dir="ltr"><?= e($v->national_code ?? '—') ?></code>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">نوع مدرک</span>
          <span class="bx-badge badge-muted"><?= $docTypes[$v->document_type ?? ''] ?? '—' ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">تاریخ ارسال</span>
          <span><?= to_jalali($v->created_at ?? '') ?></span>
        </div>
      </div>
    </div>

    <!-- Action -->
    <?php if (in_array($s, ['pending','under_review'])): ?>
    <div class="bx-info-card bx-info-card--action">
      <div class="bx-info-card__header"><i class="material-icons">gavel</i><h6>تصمیم‌گیری</h6></div>
      <div class="bx-info-card__body">
        <p >پس از بررسی مدارک زیر تصمیم خود را ثبت کنید.</p>
        <button class="bx-btn bx-btn--success" data-click="doApprove">
          <i class="material-icons">verified_user</i>تأیید احراز هویت
        </button>
        <button class="bx-btn bx-btn--danger" data-click="showRejectBox">
          <i class="material-icons">cancel</i>رد احراز هویت
        </button>
        <div id="rejectBox">
          <div class="bx-field-group">
            <label>دلیل رد</label>
            <textarea id="rejectReason" class="bx-input" rows="3" placeholder="دلیل رد برای کاربر..."></textarea>
          </div>
          <button class="bx-btn bx-btn--danger" data-click="confirmReject">
            <i class="material-icons">send</i>ثبت رد
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- MAIN: Documents -->
  <div class="bx-review-layout__main">

    <!-- Document Images -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">photo_library</i><h6>تصاویر مدارک</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-doc-grid">
          <?php
          $docs = [
            ['front_image', 'تصویر جلو مدرک', 'badge_filled'],
            ['back_image',  'تصویر پشت مدرک', 'badge'],
            ['selfie_image','سلفی با مدرک',    'face'],
          ];
          foreach ($docs as [$field, $label, $icon]):
            $imgPath = $v->$field ?? null;
            if (!$imgPath && $field === 'selfie_image' && !empty($v->verification_image)) {
                $imgPath = '/file/view/kyc/' . rawurlencode(basename((string)$v->verification_image));
            }
          ?>
          <div class="bx-doc-item <?= $imgPath ? '' : 'bx-doc-item--empty' ?>">
            <div class="bx-doc-item__label">
              <i class="material-icons"><?= $icon ?></i><?= $label ?>
            </div>
            <?php if ($imgPath): ?>
            <a href="<?= url($imgPath) ?>" target="_blank" class="bx-doc-item__img-wrap">
              <img src="<?= url($imgPath) ?>" alt="<?= $label ?>" loading="lazy">
              <div class="bx-doc-item__overlay"><i class="material-icons">zoom_in</i></div>
            </a>
            <?php else: ?>
            <div class="bx-doc-item__placeholder">
              <i class="material-icons">image_not_supported</i>
              <span>بارگذاری نشده</span>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- History / Notes -->
    <?php if (!empty($v->admin_notes)): ?>
    <div class="bx-info-card">
      <div class="bx-info-card__header"><i class="material-icons">notes</i><h6>یادداشت مدیر</h6></div>
      <div class="bx-info-card__body">
        <p ><?= nl2br(e($v->admin_notes)) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($v->reject_reason)): ?>
    <div class="bx-alert bx-alert--red">
      <i class="material-icons">error</i>
      <div><strong>دلیل رد:</strong> <?= e($v->reject_reason) ?></div>
    </div>
    <?php endif; ?>

  </div>

</div>



<?php $content = ob_get_clean();
include view_path('layouts.admin'); require_once view_path('layouts.admin'); ?>

