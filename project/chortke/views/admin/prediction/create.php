<?php
$sportTypes = $sportTypes ?? [];
$rolloverReserve = (float)($rolloverReserve ?? 0);
$old = $old ?? [];
$errors = $errors ?? [];
$h = static fn($v): string => e((string)($v ?? ''));
$money = static fn($v, int $dec = 4): string => number_format((float)($v ?? 0), $dec);
$value = static fn(string $key, $default = '') => $old[$key] ?? $default;

ob_start();
?>
<div class="pa-wrap pa-create">
  <section class="pa-hero">
    <div>
      <div class="pa-kicker"><span class="material-icons">add_circle</span> تعریف بازی جدید</div>
      <h1>ساخت بازی پیش‌بینی</h1>
      <p>قبل از انتشار، زمان بازی، مهلت ثبت، محدوده مبلغ و کمیسیون را دقیق وارد کنید. ذخیره انتقالی فعلی به اولین بازی جدید اضافه می‌شود.</p>
    </div>
    <div class="pa-hero-actions">
      <a href="<?= e(url('/admin/prediction')) ?>" class="pa-btn pa-btn-ghost"><span class="material-icons">arrow_back</span> بازگشت به لیست</a>
    </div>
  </section>

  <?php if($errors): ?>
    <div class="pa-alert err"><span class="material-icons">error</span><div><?php foreach((array)$errors as $err): ?><p><?= e(is_array($err) ? implode('، ', $err) : (string)$err) ?></p><?php endforeach; ?></div></div>
  <?php endif; ?>

  <div class="pa-create-grid">
    <section class="pa-card">
      <div class="pa-card-head"><h2><span class="material-icons">edit_calendar</span> اطلاعات بازی</h2><p>این اطلاعات در Hub کاربر نمایش داده می‌شود.</p></div>
      <form method="POST" action="<?= e(url('/admin/prediction/store')) ?>" class="pa-form">
        <?= csrf_field() ?>
        <label class="wide"><span>عنوان بازی <b>*</b></span><input type="text" name="title" value="<?= $h($value('title')) ?>" required placeholder="مثلاً فینال جام باشگاه‌ها"></label>
        <div class="pa-form-grid">
          <label><span>تیم میزبان <b>*</b></span><input type="text" name="team_home" value="<?= $h($value('team_home')) ?>" required placeholder="نام تیم میزبان"></label>
          <label><span>تیم مهمان <b>*</b></span><input type="text" name="team_away" value="<?= $h($value('team_away')) ?>" required placeholder="نام تیم مهمان"></label>
          <label><span>نوع ورزش <b>*</b></span><select name="sport_type" required><?php foreach($sportTypes as $key=>$label): ?><option value="<?= e($key) ?>" <?= ((string)$value('sport_type','football')===(string)$key)?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label><span>کمیسیون از پول بازنده‌ها <b>*</b></span><input type="number" name="commission_percent" min="0" max="30" step="0.01" value="<?= $h($value('commission_percent', setting('prediction_commission_percent', 5))) ?>" required></label>
          <label><span>زمان بازی <b>*</b></span><input type="datetime-local" name="match_date" value="<?= $h($value('match_date')) ?>" required></label>
          <label><span>مهلت ثبت پیش‌بینی <b>*</b></span><input type="datetime-local" name="bet_deadline" value="<?= $h($value('bet_deadline')) ?>" required></label>
          <label><span>حداقل مبلغ (USDT) <b>*</b></span><input type="number" name="min_bet_usdt" min="0.01" step="0.01" value="<?= $h($value('min_bet_usdt', 1)) ?>" required></label>
          <label><span>حداکثر مبلغ (USDT) <b>*</b></span><input type="number" name="max_bet_usdt" min="0.01" step="0.01" value="<?= $h($value('max_bet_usdt', 1000)) ?>" required></label>
        </div>
        <label class="wide"><span>توضیح اختیاری برای کاربر</span><textarea name="description" rows="4" placeholder="توضیح کوتاه درباره بازی، منبع نتیجه یا نکته مهم..."><?= $h($value('description')) ?></textarea></label>
        <div class="pa-form-actions">
          <button type="submit" class="pa-btn pa-btn-primary"><span class="material-icons">save</span> ثبت و انتشار بازی</button>
          <a href="<?= e(url('/admin/prediction')) ?>" class="pa-btn pa-btn-ghost">انصراف</a>
        </div>
      </form>
    </section>

    <aside class="pa-card pa-rules-aside">
      <div class="pa-card-head"><h2><span class="material-icons">rule</span> قوانین مالی فعال</h2></div>
      <div class="pa-reserve-box"><small>ذخیره انتقالی آماده مصرف</small><strong><?= $money($rolloverReserve, 4) ?> USDT</strong><p>هنگام ثبت بازی جدید، این مبلغ به پاداش همان بازی اضافه و ذخیره صفر می‌شود.</p></div>
      <ol class="pa-rules-list">
        <li><strong>کمیسیون فقط از بازنده‌هاست.</strong><span>اصل مبلغ برنده‌ها مشمول کمیسیون نیست.</span></li>
        <li><strong>همه درست بگویند:</strong><span>اصل مبلغ‌ها برمی‌گردد، سود و کمیسیون صفر است.</span></li>
        <li><strong>بدون برنده:</strong><span>۵۰٪ استخر به چرخه بعدی و ۵۰٪ سهم سایت می‌شود.</span></li>
        <li><strong>لغو بازی:</strong><span>همه مبالغ در انتظار نتیجه کامل برگشت می‌خورند.</span></li>
      </ol>
    </aside>
  </div>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminprediction.css') . '?v=' . e(config('app.version','1.0.0')) . '">';
include view_path('layouts.admin');
?>
