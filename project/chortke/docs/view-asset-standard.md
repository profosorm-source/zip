# استاندارد دارایی‌های ویو (View Asset Standard)

> هدف: یکدست‌سازی ویوها، حذف استایل و اسکریپت‌های inline، و جداسازی مسئولیت‌ها طبق معماری تمیز.

## ۱. ساختار کلی

هر ویو فقط مسئول نمایش داده است. هیچ استایل یا منطق JS به‌صورت inline در ویوها نباید وجود داشته باشد.

```
views/
  admin/users/index.php
  admin/users/create.php
  user/dashboard.php
  user/wallet/deposit.php

public/assets/
  css/
    shared/          ← استایل‌های مشترک (layout, components, utilities, ...)
    views/           ← استایل اختصاصی هر ویو
  js/
    shared/          ← اسکریپت‌های مشترک (core, csrf, flash, theme, confirm, ...)
    views/           ← اسکریپت اختصاصی هر ویو
```

## ۲. قرارداد نام‌گذاری

- فقط حروف کوچک انگلیسی و اعداد.
- **بدون خط تیره (-)، بدون زیرخط (_)، بدون فاصله.**
- **بدون نام پروژه (چرتکه / chortke) در نام فایل یا کلاس CSS.**
- نام‌ها کوتاه، ساده و یکدست باشند.
- استفاده از پوشه برای تفکیک بخش admin/user مجاز است.

### ۲.۱ فایل‌های مشترک

```
css/shared/layout.css
css/shared/components.css
css/shared/utilities.css
css/shared/forms.css
css/shared/tables.css
css/shared/cards.css
css/shared/modal.css
css/shared/toast.css
css/shared/chart.css

js/shared/core.js
js/shared/csrf.js
js/shared/flash.js
js/shared/theme.js
js/shared/confirm.js
js/shared/table.js
js/shared/loader.js
js/shared/charts.js
```

### ۲.۲ فایل‌های اختصاصی ویو

برای ویو `views/admin/users/index.php`:
```
css/views/admin/users.css
js/views/admin/users.js
```

برای ویو `views/admin/users/create.php`:
```
css/views/admin/usersform.css
js/views/admin/usersform.js
```

برای ویو `views/user/wallet/deposit.php`:
```
css/views/user/walletdeposit.css
js/views/user/walletdeposit.js
```

قاعده: مسیر ویو بدون خط تیره و فقط با بخش‌های ضروری.

## ۳. ساختار استاندارد یک ویو

```php
<?php
$title = 'عنوان صفحه';
ob_start();
?>

<!-- محتوا فقط با کلاس‌ها؛ هیچ style= و هیچ onclick= -->
<div class="page-header">
  <h1>عنوان</h1>
</div>

<?php
$content = ob_get_clean();

$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admin/users.css') . '">';
$scripts = '<script src="' . asset('assets/js/views/admin/users.js') . '"></script>';

include view_path('layouts.admin');
```

## ۴. قواعد CSS

- هیچ `style="..."` در HTML مجاز نیست.
- هیچ `<style>` inline در ویو مجاز نیست.
- از کلاس‌های مشترک موجود استفاده شود؛ اگر نیاز به کلاس جدید است، در فایل CSS ویو یا shared تعریف شود.
- کلاس‌ها نیز بدون نام پروژه باشند. به‌جای `.chortke-card` از `.card` یا `.panel-card` استفاده شود.
- متغیرهای CSS مشترک در `:root` فایل `shared/layout.css` تعریف می‌شوند.

## ۵. قواعد JavaScript

- هیچ `<script>` inline در ویو مجاز نیست.
- هیچ `onclick="..."` یا `onchange="..."` مجاز نیست.
- استفاده از data-attributes و event delegation در `js/shared/core.js`.
- اگر منطق ویو به داده PHP نیاز دارد، داده‌ها از طریق `<script type="application/json" id="...">` پاس داده شوند.
- تمام اسکریپت‌های shared با nonce معتبر بارگذاری شوند.

## ۶. پترن داده‌رسانی به JS

```php
<script type="application/json" id="view-data">
<?= json_encode($viewData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
</script>
<script src="<?= asset('assets/js/views/admin/users.js') ?>"></script>
```

در JS:
```js
const data = JSON.parse(document.getElementById('view-data').textContent);
```

## ۷. چک‌لیست بازبینی ویو

- [ ] هیچ `<style>` وجود ندارد.
- [ ] هیچ `<script>` وجود ندارد.
- [ ] هیچ `style="..."` وجود ندارد.
- [ ] هیچ `onclick/onchange/onsubmit` وجود ندارد.
- [ ] فایل CSS ویو با نام ساده و بدون خط تیره ایجاد شده است.
- [ ] فایل JS ویو با نام ساده و بدون خط تیره ایجاد شده است.
- [ ] منطق‌های تکراری به shared منتقل شده است.
- [ ] ویو از layout مشترک استفاده می‌کند.
- [ ] داده‌های PHP به JS از طریق JSON ایمن منتقل شده است.
