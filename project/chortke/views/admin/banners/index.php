<?php
$title = 'مدیریت تبلیغات';
require_once dirname(__DIR__, 3) . '/helpers/banner_helpers.php';
ob_start();
?>

<div class="admin-content" id="adminBannersRoot" data-reject-url="<?= e(url('/admin/banners/reject')) ?>">
    <div class="page-header">
        <h1>📢 مدیریت تبلیغات</h1>
        <div class="actions">
            <a href="<?= url('/admin/banners/create') ?>" class="btn btn-primary">افزودن بنر</a>
            <a href="<?= url('/admin/banners/stats') ?>" class="btn btn-secondary">آمار</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <div class="stat-info">
                <div class="stat-label">کل بنرها</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <div class="stat-label">فعال</div>
                <div class="stat-value"><?= number_format($stats['active']) ?></div>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon">⏳</div>
            <div class="stat-info">
                <div class="stat-label">در انتظار</div>
                <div class="stat-value"><?= number_format($stats['pending']) ?></div>
            </div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon">👆</div>
            <div class="stat-info">
                <div class="stat-label">کل کلیک</div>
                <div class="stat-value"><?= number_format($stats['total_clicks']) ?></div>
            </div>
        </div>
    </div>

    <div class="filters-box">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label>نوع:</label>
                <select name="banner_type">
                    <option value="">همه</option>
                    <option value="system" <?= ($filters['banner_type'] ?? '') === 'system' ? 'selected' : '' ?>>سیستمی</option>
                    <option value="startup" <?= ($filters['banner_type'] ?? '') === 'startup' ? 'selected' : '' ?>>استارتاپی</option>
                    <option value="user" <?= ($filters['banner_type'] ?? '') === 'user' ? 'selected' : '' ?>>کاربری</option>
                    <option value="promo" <?= ($filters['banner_type'] ?? '') === 'promo' ? 'selected' : '' ?>>تبلیغاتی</option>
                </select>
            </div>

            <div class="filter-group">
                <label>جایگاه:</label>
                <select name="placement">
                    <option value="">همه</option>
                    <?php foreach ($placements as $p): ?>
                        <option value="<?= e($p->slug) ?>" <?= ($filters['placement'] ?? '') === $p->slug ? 'selected' : '' ?>>
                            <?= e($p->title) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>وضعیت:</label>
                <select name="is_active">
                    <option value="">همه</option>
                    <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>فعال</option>
                    <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>غیرفعال</option>
                </select>
            </div>

            <div class="filter-group">
                <label>جستجو:</label>
                <input type="text" name="search" placeholder="عنوان..." value="<?= e($filters['search'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary">فیلتر</button>
            <a href="<?= url('/admin/banners') ?>" class="btn btn-secondary">پاک کردن</a>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="60">#</th>
                <th width="100">تصویر</th>
                <th>عنوان</th>
                <th>جایگاه</th>
                <th width="100">نوع</th>
                <th width="100">وضعیت</th>
                <th width="120">آمار</th>
                <th width="180">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banners)): ?>
                <tr><td colspan="8" class="text-center">بنری یافت نشد</td></tr>
            <?php else: ?>
                <?php foreach ($banners as $banner): ?>
                    <tr>
                        <td><?= $banner->id ?></td>
                        <td>
                            <?php if (!empty($banner->image_path)): ?>
                                <img src="<?= e($banner->image_path ?? '') ?>" alt="" class="aggr-cleaned">
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= e($banner->title ?? '—') ?></strong>
                            <?php if (!empty($banner->user_name)): ?>
                                <br><small class="text-muted">کاربر: <?= e($banner->user_name ?? '') ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-info"><?= e($banner->placement ?? '—') ?></span></td>
                        <td><?= banner_type_label($banner->banner_type ?? 'system') ?></td>
                        <td><?= banner_status_badge($banner) ?></td>
                        <td>
                            <small>
                                👁️ <?= number_format((int)($banner->impressions ?? 0)) ?><br>
                                👆 <?= number_format((int)($banner->clicks ?? 0)) ?><br>
                                📈 <?= number_format((float)($banner->ctr ?? 0), 1) ?>%
                            </small>
                        </td>
                        <td class="actions">
                            <?php if (in_array((string)($banner->banner_type ?? 'system'), ['startup', 'user'], true) && empty($banner->approved_at)): ?>
                                <form method="POST" action="<?= url('/admin/banners/approve') ?>">
            <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $banner->id ?>">
                                    <button type="submit" class="btn btn-sm btn-success">✅</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-danger" data-click="rejectBanner" data-args="<?= $banner->id ?>">❌</button>
                            <?php endif; ?>
                            <a href="<?= url('/admin/banners/edit') ?>?id=<?= $banner->id ?>" class="btn btn-sm btn-primary">✏️</a>
                            <form method="POST" action="<?= url('/admin/banners/delete') ?>" data-confirm="حذف شود؟">
            <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $banner->id ?>">
                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    $perPage = max(1, (int)($perPage ?? 20));
    $total = (int)($total ?? 0);
    $totalPages = ceil($total / $perPage);
    if ($totalPages > 1):
    ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= url('/admin/banners') ?>?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>





<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminbannersindex.css') . '">';
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/bannersindex.js') . '"></script>';
include view_path('layouts.admin');
?>

