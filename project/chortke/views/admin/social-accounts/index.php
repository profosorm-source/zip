<?php
// views/admin/social-accounts/index.php
$title = 'مدیریت حساب‌های اجتماعی';

ob_start();
?>
<div id="socialAccountsRoot" data-base="<?= url('/admin/social-accounts') ?>"></div>


<div class="page-header">
    <h4><i class="material-icons">people_outline</i> حساب‌های اجتماعی</h4>
</div>

<!-- فیلتر -->
<div class="filter-card">
    <form method="GET" action="<?= url('/admin/social-accounts') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه وضعیت‌ها</option>
            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
            <option value="verified" <?= ($filters['status'] ?? '') === 'verified' ? 'selected' : '' ?>>تایید شده</option>
            <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد شده</option>
        </select>
        <select name="platform" class="form-control-sm">
            <option value="">همه پلتفرم‌ها</option>
            <option value="instagram" <?= ($filters['platform'] ?? '') === 'instagram' ? 'selected' : '' ?>>اینستاگرام</option>
            <option value="youtube" <?= ($filters['platform'] ?? '') === 'youtube' ? 'selected' : '' ?>>یوتیوب</option>
            <option value="telegram" <?= ($filters['platform'] ?? '') === 'telegram' ? 'selected' : '' ?>>تلگرام</option>
            <option value="tiktok" <?= ($filters['platform'] ?? '') === 'tiktok' ? 'selected' : '' ?>>تیک‌تاک</option>
            <option value="twitter" <?= ($filters['platform'] ?? '') === 'twitter' ? 'selected' : '' ?>>توییتر</option>
        </select>
        <input type="text" name="search" class="form-control-sm" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
    <span class="filter-count">مجموع: <?= number_format($total) ?></span>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>کاربر</th>
                <th>پلتفرم</th>
                <th>نام کاربری</th>
                <th>فالوور</th>
                <th>پست</th>
                <th>قدمت</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $acc): ?>
                <tr data-id="<?= e($acc->id) ?>">
                    <td><?= e($acc->id) ?></td>
                    <td>
                        <div class="user-cell">
                            <strong><?= e($acc->user_name ?? '') ?></strong>
                            <small><?= e($acc->user_email ?? '') ?></small>
                        </div>
                    </td>
                    <td>
                        <span class="badge-sm platform-<?= e($acc->platform) ?>">
                            <?= e(social_platform_label($acc->platform)) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= e($acc->profile_url) ?>" target="_blank" class="ltr-text">
                            @<?= e($acc->username) ?>
                        </a>
                    </td>
                    <td><?= number_format($acc->follower_count) ?></td>
                    <td><?= number_format($acc->post_count) ?></td>
                    <td><?= e($acc->account_age_months) ?> ماه</td>
                    <td>
                        <span class="badge badge-<?= e(social_status_badge($acc->status)) ?>">
                            <?= e(social_status_label($acc->status)) ?>
                        </span>
                    </td>
                    <td><?= to_jalali($acc->created_at) ?></td>
                    <td>
                        <?php if ($acc->status === 'pending'): ?>
                            <button class="btn btn-xs btn-success btn-verify" data-id="<?= e($acc->id) ?>">
                                <i class="material-icons">check</i>
                            </button>
                            <button class="btn btn-xs btn-danger btn-reject" data-id="<?= e($acc->id) ?>">
                                <i class="material-icons">close</i>
                            </button>
                        <?php else: ?>
                            <a href="<?= url('/admin/social-accounts/' . $acc->id) ?>" class="btn btn-xs btn-outline-secondary">
                                <i class="material-icons">visibility</i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
            $qs = $_GET;
            $qs['page'] = $i;
            $qsStr = http_build_query($qs);
            ?>
            <a href="<?= url('/admin/social-accounts?' . $qsStr) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= e($i) ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
