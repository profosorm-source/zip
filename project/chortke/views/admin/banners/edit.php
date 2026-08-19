<?php
$title = 'ویرایش بنر';
require_once dirname(__DIR__, 3) . '/helpers/banner_helpers.php';
ob_start();
?>

<div class="admin-content">
    <div class="page-header">
        <h1>ویرایش بنر</h1>
        <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">بازگشت</a>
    </div>

    <div class="form-container">
        <form method="POST" action="<?= url('/admin/banners/update') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$banner->id ?>">

            <div class="form-group">
                <label>عنوان *</label>
                <input type="text" name="title" value="<?= e($banner->title ?? '') ?>" required class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>نوع بنر</label>
                    <input type="text" value="<?= banner_type_label($banner->banner_type ?? 'system') ?>" readonly class="form-control">
                </div>

                <?php if (!empty($banner->category)): ?>
                    <div class="form-group">
                        <label>دسته‌بندی</label>
                        <select name="category" class="form-control">
                            <option value="">انتخاب کنید</option>
                            <option value="startup" <?= ($banner->category ?? '') === 'startup' ? 'selected' : '' ?>>استارتاپ</option>
                            <option value="ngo" <?= ($banner->category ?? '') === 'ngo' ? 'selected' : '' ?>>NGO</option>
                            <option value="educational" <?= ($banner->category ?? '') === 'educational' ? 'selected' : '' ?>>آموزشی</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>جایگاه *</label>
                <select name="placement" required class="form-control">
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= e($p->slug) ?>" <?= ($banner->placement ?? '') === $p->slug ? 'selected' : '' ?>>
                            <?= e($p->title) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <?php if (!empty($banner->image_path)): ?>
                    <div >
                        <img src="<?= e($banner->image_path ?? '') ?>" alt="" class="aggr-cleaned">
                    </div>
                <?php endif; ?>
                <label>تصویر جدید (اختیاری)</label>
                <input type="file" name="image" accept="image/*" class="form-control">
            </div>

            <div class="form-group">
                <label>لینک</label>
                <input type="url" name="link" value="<?= e($banner->link ?? '') ?>" class="form-control">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>تاریخ شروع</label>
                    <input type="datetime-local" name="start_date" 
                           value="<?= !empty($banner->start_date) ? date('Y-m-d\TH:i', strtotime($banner->start_date)) : '' ?>" 
                           class="form-control">
                </div>

                <div class="form-group">
                    <label>تاریخ پایان</label>
                    <input type="datetime-local" name="end_date" 
                           value="<?= !empty($banner->end_date) ? date('Y-m-d\TH:i', strtotime($banner->end_date)) : '' ?>" 
                           class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="<?= (int)($banner->sort_order ?? 0) ?>" class="form-control">
                </div>

                <div class="form-group">
                    <label>باز شدن در</label>
                    <select name="target" class="form-control">
                        <option value="_blank" <?= ($banner->target ?? '_blank') === '_blank' ? 'selected' : '' ?>>پنجره جدید</option>
                        <option value="_self" <?= ($banner->target ?? '_blank') === '_self' ? 'selected' : '' ?>>همین پنجره</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>متن جایگزین (alt)</label>
                <input type="text" name="alt_text" value="<?= e($banner->alt_text ?? '') ?>" class="form-control">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($banner->is_active) ? 'checked' : '' ?>>
                    فعال
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">بروزرسانی</button>
                <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>



<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbannersedit.css') . '">';
$content = ob_get_clean();
include view_path('layouts.admin');
?>
