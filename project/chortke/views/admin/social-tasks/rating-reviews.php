<?php
$title = 'مدیریت';
ob_start();
?>
<div class="container py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><?= h($title) ?></h2>
            <div class="alert alert-info mt-3">
                <strong>آمار:</strong>
                نظرات منتظر بررسی: <?= $stats['pending_reviews'] ?> | 
                تایید‌شده: <?= $stats['approved_reviews'] ?> | 
                رد‌شده: <?= $stats['rejected_reviews'] ?>
            </div>
        </div>
    </div>

    <?php if (!empty($ratings)): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>شماره</th>
                        <th>امتیاز‌دهنده</th>
                        <th>کاربر امتیاز‌شده</th>
                        <th>تسک</th>
                        <th>امتیاز</th>
                        <th>نظر</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ratings as $rating): ?>
                        <tr>
                            <td>#<?= $rating->id ?></td>
                            <td>
                                <strong><?= h($rating->rater_name) ?></strong><br>
                                <small class="text-muted"><?= $rating->rater_type === 'executor' ? 'انجام‌دهنده' : 'تبلیغ‌دهنده' ?></small>
                            </td>
                            <td><?= h($rating->rated_name) ?></td>
                            <td><?= h(substr($rating->execution_title, 0, 30)) ?>...</td>
                            <td>
                                <span class="badge bg-warning">
                                    <?php for ($i = 0; $i < $rating->stars; $i++): ?>
                                        ★
                                    <?php endfor; ?>
                                </span>
                            </td>
                            <td><?= h(substr($rating->comment, 0, 50)) ?>...</td>
                            <td><?= date('Y-m-d H:i', strtotime($rating->created_at)) ?></td>
                            <td>
                                <a href="<?= url('/admin/social-task-reviews/' . $rating->id) ?>" class="btn btn-sm btn-info">
                                    مشاهده
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= url('/admin/social-task-reviews?page=' . ($page - 1)) ?>">
                            قبلی
                        </a>
                    </li>
                <?php endif; ?>

                <li class="page-item active">
                    <span class="page-link"><?= $page ?></span>
                </li>

                <?php if (count($ratings) > 29): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= url('/admin/social-task-reviews?page=' . ($page + 1)) ?>">
                            بعدی
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php else: ?>
        <div class="alert alert-success">هیچ نظری برای بررسی ندارد!</div>
    <?php endif; ?>
</div>

<?php view('layouts.footer') ?>
<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
