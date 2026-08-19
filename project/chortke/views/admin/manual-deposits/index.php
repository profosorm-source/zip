<?php
$pageTitle = $pageTitle ?? 'واریزهای دستی';
$deposits = $deposits ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
$total = $total ?? 0;
$status = $status ?? '';

ob_start();
?>
<div id="depositsRoot" data-base="<?= url('/admin/manual-deposits') ?>" data-verify-url="<?= url('/admin/manual-deposits/verify') ?>" data-reject-url="<?= url('/admin/manual-deposits/reject') ?>"></div>


<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>مدیریت واریزهای دستی</h1>
        <div class="header-stats">
            <div class="stat-badge pending">
                <i class="material-icons">schedule</i>
                <span><?= to_jalali($total, '', true) ?> در انتظار</span>
            </div>
        </div>
    </div>

    <!-- فیلترها -->
    <div class="filters-card">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label>وضعیت:</label>
                <select name="status" class="form-control">
                    <option value="">همه</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="under_review" <?= $status === 'under_review' ? 'selected' : '' ?>>در حال بررسی</option>
                    <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>تأیید شده</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="material-icons">search</i>
                فیلتر
            </button>

            <?php if ($status): ?>
            <a href="<?= url('/admin/manual-deposits') ?>" class="btn btn-outline">
                <i class="material-icons">clear</i>
                حذف فیلتر
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- جدول -->
    <div class="table-card">
        <?php if (empty($deposits)): ?>
        <div class="empty-state">
            <i class="material-icons">account_balance</i>
            <h3>واریزی یافت نشد</h3>
            <p>هیچ درخواست واریز دستی وجود ندارد</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>مبلغ</th>
                        <th>کارت مبدا</th>
                        <th>شماره پیگیری</th>
                        <th>تاریخ واریز</th>
                        <th>ساعت</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deposits as $deposit): ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <strong><?= e($deposit->full_name ?? 'نامشخص') ?></strong>
                                <small><?= e($deposit->email ?? '') ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="amount-badge">
                                <?= number_format($deposit->amount) ?> تومان
                            </span>
                        </td>
                        <td>
                            <code class="card-number">
                                <?= substr($deposit->card_number, 0, 4) ?>-****-****-<?= substr($deposit->card_number, -4) ?>
                            </code>
                            <small class="bank-name"><?= e($deposit->bank_name) ?></small>
                        </td>
                        <td>
                            <code class="tracking-code"><?= e($deposit->tracking_code) ?></code>
                        </td>
                        <td>
                            <span class="date-badge"><?= to_jalali($deposit->deposit_date) ?></span>
                        </td>
                        <td>
                            <span class="time-badge" dir="ltr"><?= e($deposit->deposit_time) ?></span>
                        </td>
                        <td>
                            <?php
                            $statusLabels = [
                                'pending' => 'در انتظار',
                                'under_review' => 'در حال بررسی',
                                'verified' => 'تأیید شده',
                                'rejected' => 'رد شده',
                            ];
                            ?>
                            <span class="status-badge <?= e($deposit->status) ?>">
                                <?= e($statusLabels[$deposit->status] ?? $deposit->status) ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="<?= url('/admin/manual-deposits/review?id=' . $deposit->id) ?>" 
                                   class="btn-icon" 
                                   title="بررسی جزئیات">
                                    <i class="material-icons">visibility</i>
                                </a>
                                <?php if ($deposit->status === 'pending' || $deposit->status === 'under_review'): ?>
                                <button class="btn-icon success" 
                                        data-click="verifyDeposit" data-args="<?= e($deposit->id) ?>" 
                                        title="تأیید">
                                    <i class="material-icons">check_circle</i>
                                </button>
                                <button class="btn-icon danger" 
                                        data-click="showRejectModal" data-args="<?= e($deposit->id) ?>" 
                                        title="رد">
                                    <i class="material-icons">cancel</i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?><?= $status ? '&status=' . $status : '' ?>" class="page-link">
                <i class="material-icons">chevron_right</i>
            </a>
            <?php endif; ?>

            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
            <a href="?page=<?= e($i) ?><?= $status ? '&status=' . $status : '' ?>" 
               class="page-link <?= $i === $currentPage ? 'active' : '' ?>">
                <?= to_jalali($i, '', true) ?>
            </a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?><?= $status ? '&status=' . $status : '' ?>" class="page-link">
                <i class="material-icons">chevron_left</i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- مودال رد واریز -->
<div class="modal" id="rejectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>رد واریز دستی</h3>
            <button class="modal-close" data-click="closeRejectModal">
                <i class="material-icons">close</i>
            </button>
        </div>
        <form id="rejectForm">
            <?= csrf_field() ?>
            <input type="hidden" id="reject_deposit_id" name="deposit_id">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="rejection_reason">دلیل رد: <span class="required">*</span></label>
                    <textarea id="rejection_reason" 
                              name="rejection_reason" 
                              class="form-control" 
                              rows="4"
                              placeholder="لطفاً دلیل رد واریز را به صورت واضح توضیح دهید..."
                              required></textarea>
                    <small class="form-text">این پیام به کاربر نمایش داده می‌شود</small>
                </div>

                <div class="common-reasons">
                    <strong>دلایل متداول:</strong>
                    <button type="button" class="reason-btn" data-click="setReason" data-args="شماره پیگیری نامعتبر است">
                        شماره پیگیری نادرست
                    </button>
                    <button type="button" class="reason-btn" data-click="setReason" data-args="مبلغ واریزی با مبلغ ثبت شده مطابقت ندارد">
                        عدم تطابق مبلغ
                    </button>
                    <button type="button" class="reason-btn" data-click="setReason" data-args="واریز از کارت دیگری انجام شده است">
                        کارت نامتعلق
                    </button>
                    <button type="button" class="reason-btn" data-click="setReason" data-args="اطلاعات ثبت شده صحیح نیست">
                        اطلاعات نادرست
                    </button>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-click="closeRejectModal">انصراف</button>
                <button type="submit" class="btn btn-danger">
                    <i class="material-icons">cancel</i>
                    رد واریز
                </button>
            </div>
        </form>
    </div>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
