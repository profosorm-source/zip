<?php
/** @var object $stats */
/** @var array $submissions */
$title = 'مدیریت محتوای درآمدزا';
$activeAdminContent = 'submissions';
$filters = $filters ?? [];
$search = $search ?? '';
$submissions = $submissions ?? [];
$total = (int)($total ?? 0);
$currentPage = (int)($currentPage ?? 1);
$totalPages = (int)($totalPages ?? 1);

$h = static fn($value): string => e((string)($value ?? ''));
$int = static fn($value): string => function_exists('fa_number') ? fa_number((int)$value) : number_format((int)$value);
$platformLabel = static fn($platform): string => match ((string)$platform) {
    'aparat' => 'آپارات',
    'youtube' => 'یوتیوب',
    'upload_center' => 'آپلود سنتر',
    default => (string)$platform ?: 'نامشخص',
};
$platformClass = static fn($platform): string => match ((string)$platform) {
    'aparat' => 'info',
    'youtube' => 'danger',
    'upload_center' => 'primary',
    default => 'muted',
};
$statusMeta = static fn($status): array => match ((string)$status) {
    'pending' => ['در انتظار بررسی', 'warning', 'hourglass_empty'],
    'under_review' => ['در حال بررسی', 'info', 'rate_review'],
    'approved' => ['تأیید شده', 'success', 'check_circle'],
    'published' => ['منتشر شده', 'primary', 'public'],
    'rejected' => ['رد شده', 'danger', 'cancel'],
    'suspended' => ['تعلیق شده', 'dark', 'block'],
    default => ['نامشخص', 'muted', 'help'],
};

ob_start();
?>
<div id="contentRoot" class="ac-page"
     data-base="<?= e(url('/admin/content')) ?>"
     data-revenue-base="<?= e(url('/admin/content/revenue')) ?>"
     data-bulk-approve="<?= e(url('/admin/content/bulk-approve')) ?>"
     data-bulk-reject="<?= e(url('/admin/content/bulk-reject')) ?>"
     data-export-url="<?= e(url('/admin/content/export')) ?>"
     data-filter-status="<?= $h($filters['status'] ?? '') ?>"
     data-filter-platform="<?= $h($filters['platform'] ?? '') ?>"
     data-filter-search="<?= $h($search) ?>">

    <section class="ac-hero">
        <div>
            <div class="ac-kicker"><span class="material-icons">movie_filter</span> مرکز مدیریت کسب درآمد از محتوا</div>
            <h1>بررسی محتواهای ارسالی کاربران</h1>
            <p>در این بخش محتواها را تأیید یا رد کنید، انتشار را ثبت کنید و بعد از فعال شدن محتوا، درآمد دوره‌ای را ایجاد و مدیریت کنید.</p>
        </div>
        <div class="ac-hero-actions">
            <a href="<?= e(url('/admin/content/revenues')) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">payments</span> درآمدها</a>
            <button type="button" class="ac-btn ac-btn-ghost" data-click="exportContent"><span class="material-icons">download</span> خروجی CSV</button>
        </div>
    </section>

    <?php include view_path('admin.content._admin-nav'); ?>

    <section class="ac-stats-grid" aria-label="آمار مدیریت محتوا">
        <article class="ac-stat-card"><span class="ac-stat-icon gold"><i class="material-icons">video_library</i></span><div><small>کل محتواها</small><strong><?= $int($stats->total ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon warning"><i class="material-icons">hourglass_empty</i></span><div><small>در انتظار</small><strong><?= $int($stats->pending_count ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon success"><i class="material-icons">check_circle</i></span><div><small>تأیید شده</small><strong><?= $int($stats->approved_count ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon primary"><i class="material-icons">public</i></span><div><small>منتشر شده</small><strong><?= $int($stats->published_count ?? 0) ?></strong></div></article>
        <article class="ac-stat-card"><span class="ac-stat-icon danger"><i class="material-icons">cancel</i></span><div><small>رد/تعلیق</small><strong><?= $int((int)($stats->rejected_count ?? 0) + (int)($stats->suspended_count ?? 0)) ?></strong></div></article>
    </section>

    <section class="ac-card ac-filter-card">
        <form method="GET" class="ac-filter-form">
            <div class="ac-input-wrap search">
                <span class="material-icons">search</span>
                <input type="text" name="search" value="<?= $h($search) ?>" placeholder="جستجو بر اساس عنوان، لینک یا کاربر..." aria-label="جستجو">
            </div>
            <select name="status" aria-label="فیلتر وضعیت">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach (['pending' => 'در انتظار', 'under_review' => 'در حال بررسی', 'approved' => 'تأیید شده', 'published' => 'منتشر شده', 'rejected' => 'رد شده', 'suspended' => 'تعلیق شده'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="platform" aria-label="فیلتر پلتفرم">
                <option value="">همه پلتفرم‌ها</option>
                <?php foreach (['aparat' => 'آپارات', 'youtube' => 'یوتیوب', 'upload_center' => 'آپلود سنتر'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['platform'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ac-btn ac-btn-primary"><span class="material-icons">filter_alt</span> اعمال فیلتر</button>
            <a href="<?= e(url('/admin/content')) ?>" class="ac-btn ac-btn-ghost"><span class="material-icons">restart_alt</span> پاک‌سازی</a>
            <span class="ac-result-count"><?= $int($total) ?> مورد</span>
        </form>
    </section>

    <section class="ac-card">
        <div class="ac-card-head">
            <div>
                <h2>فهرست محتواها</h2>
                <p>عملیات اصلی هر محتوا از همین جدول یا صفحه جزئیات انجام می‌شود.</p>
            </div>
            <button type="button" class="ac-btn ac-btn-ghost ac-hidden" data-click="showBulkActions" id="bulkActionsBtn"><span class="material-icons">done_all</span> عملیات گروهی</button>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="ac-empty">
                <span class="material-icons">inbox</span>
                <h3>محتوایی پیدا نشد</h3>
                <p>با تغییر فیلترها یا جستجو، دوباره بررسی کنید.</p>
            </div>
        <?php else: ?>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" data-change="toggleSelectAll" data-pass-el aria-label="انتخاب همه"></th>
                        <th>شناسه</th>
                        <th>عنوان و کاربر</th>
                        <th>پلتفرم</th>
                        <th>وضعیت</th>
                        <th>تاریخ ثبت</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submissions as $item):
                        $status = (string)($item->status ?? '');
                        $meta = $statusMeta($status);
                        $userName = $item->user_name ?? $item->full_name ?? $item->user_email ?? 'نامشخص';
                        $titleText = (string)($item->title ?? 'بدون عنوان');
                    ?>
                        <tr>
                            <td><input type="checkbox" class="content-checkbox" value="<?= (int)$item->id ?>" data-change="updateBulkActionsBtn" aria-label="انتخاب محتوا #<?= (int)$item->id ?>"></td>
                            <td class="ac-id">#<?= $int($item->id ?? 0) ?></td>
                            <td>
                                <div class="ac-title-cell">
                                    <a href="<?= e(url('/admin/content/' . (int)$item->id)) ?>"><?= $h(mb_substr($titleText, 0, 70)) ?><?= mb_strlen($titleText) > 70 ? '…' : '' ?></a>
                                    <small><span class="material-icons">person</span> <?= $h($userName) ?></small>
                                </div>
                            </td>
                            <td><span class="ac-badge <?= e($platformClass($item->platform ?? '')) ?>"><?= e($platformLabel($item->platform ?? '')) ?></span></td>
                            <td><span class="ac-badge <?= e($meta[1]) ?>"><i class="material-icons"><?= e($meta[2]) ?></i><?= e($meta[0]) ?></span></td>
                            <td><?= $h(!empty($item->created_at) ? to_jalali($item->created_at) : '—') ?></td>
                            <td>
                                <div class="ac-actions">
                                    <a href="<?= e(url('/admin/content/' . (int)$item->id)) ?>" class="ac-icon-btn" title="جزئیات"><span class="material-icons">visibility</span></a>
                                    <?php if (in_array($status, ['pending', 'under_review'], true)): ?>
                                        <button type="button" class="ac-icon-btn success" data-click="approveContent" data-args="<?= (int)$item->id ?>" title="تأیید"><span class="material-icons">check</span></button>
                                        <button type="button" class="ac-icon-btn danger" data-click="rejectContent" data-args="<?= (int)$item->id ?>" title="رد"><span class="material-icons">close</span></button>
                                    <?php endif; ?>
                                    <?php if ($status === 'approved'): ?>
                                        <button type="button" class="ac-icon-btn primary" data-click="publishContent" data-args="<?= (int)$item->id ?>" title="ثبت انتشار"><span class="material-icons">public</span></button>
                                    <?php endif; ?>
                                    <?php if (in_array($status, ['approved', 'published'], true)): ?>
                                        <button type="button" class="ac-icon-btn dark" data-click="suspendContent" data-args="<?= (int)$item->id ?>" title="تعلیق"><span class="material-icons">block</span></button>
                                    <?php endif; ?>
                                    <?php if ($status === 'published'): ?>
                                        <a href="<?= e(url('/admin/content/' . (int)$item->id . '/revenue/create')) ?>" class="ac-icon-btn gold" title="ثبت درآمد"><span class="material-icons">add_chart</span></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="ac-pagination" aria-label="صفحه‌بندی محتوا">
                    <?php for ($i = 1; $i <= $totalPages; $i++):
                        $query = array_filter([
                            'status' => $filters['status'] ?? '',
                            'platform' => $filters['platform'] ?? '',
                            'search' => $search,
                            'page' => $i,
                        ], static fn($v): bool => $v !== '' && $v !== null);
                    ?>
                        <a href="<?= e(url('/admin/content?' . http_build_query($query))) ?>" class="<?= $i === $currentPage ? 'active' : '' ?>" <?= $i === $currentPage ? 'aria-current="page"' : '' ?>><?= $int($i) ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admincontent.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/admin/content.js') . '"></script>';
include view_path('layouts.admin');
?>
