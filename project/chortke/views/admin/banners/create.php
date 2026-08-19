<?php
$title = 'افزودن بنر';
ob_start();
?>

<div class="admin-content">
    <div class="page-header">
        <h1>افزودن بنر جدید</h1>
        <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="form-container">
        <form method="POST" action="<?= url('/admin/banners/store') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" required class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>نوع بنر *</label>
                    <select name="banner_type" class="form-control" data-change="toggleCategory" data-pass-value>
                        <option value="system">سیستمی</option>
                        <option value="startup">استارتاپی</option>
                        <option value="user">کاربری</option>
                        <option value="promo">تبلیغاتی</option>
                    </select>
                </div>

                <div class="form-group" id="categoryGroup">
                    <label>دسته‌بندی</label>
                    <select name="category" class="form-control">
                        <option value="">انتخاب کنید</option>
                        <option value="startup">استارتاپ</option>
                        <option value="ngo">NGO</option>
                        <option value="educational">آموزشی</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>جایگاه *</label>
                <select name="placement" required class="form-control">
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= e($p->slug) ?>"><?= e($p->title) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>تصویر</label>
                <input type="file" name="image" accept="image/*" class="form-control">
                <small>فرمت: JPG, PNG, GIF | حداکثر: 2MB</small>
            </div>

            <div class="form-group">
                <label>لینک</label>
                <input type="url" name="link" class="form-control" placeholder="https://example.com">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ شروع</label>
                    <input type="datetime-local" name="start_date" class="form-control">
                </div>

                <div class="form-group">
                    <label>تاریخ پایان</label>
                    <input type="datetime-local" name="end_date" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="0" class="form-control">
                </div>

                <div class="form-group">
                    <label>باز شدن در</label>
                    <select name="target" class="form-control">
                        <option value="_blank">پنجره جدید</option>
                        <option value="_self">همین پنجره</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>متن جایگزین (alt)</label>
                <input type="text" name="alt_text" class="form-control">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" checked>
                    فعال
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">ذخیره</button>
                <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>





<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbannerscreate.css') . '">';
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/bannerscreate.js') . '"></script>';
include view_path('layouts.admin');
?>

