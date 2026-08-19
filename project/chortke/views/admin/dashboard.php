<?php
ob_start();

$gradients   = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)','linear-gradient(135deg,#a855f7,#ec4899)','linear-gradient(135deg,#06b6d4,#3b82f6)','linear-gradient(135deg,#ef4444,#f59e0b)'];
$roleColors  = ['admin'=>'badge-danger','support'=>'badge-warning','user'=>'badge-muted','advertiser'=>'badge-purple'];
$roleNames   = ['admin'=>'مدیر','support'=>'پشتیبان','user'=>'کاربر','advertiser'=>'تبلیغ‌دهنده'];
$statusColors= ['active'=>'badge-success','inactive'=>'badge-muted','suspended'=>'badge-warning','banned'=>'badge-danger'];
$statusNames = ['active'=>'فعال','inactive'=>'غیرفعال','suspended'=>'تعلیق','banned'=>'مسدود'];

?>
<form method="POST" class="dashboard-hidden-form"><?= csrf_field() ?></form>

<!-- ══ Welcome Banner ══ -->
<div class="welcome-banner">
    <div class="welcome-text">
        <h2>خوش آمدید، <?= e($fullName ?? 'مدیر') ?> 👋</h2>
        <p>آخرین ورود: <?= e(jdate('Y/m/d H:i', time())) ?> &nbsp;·&nbsp; پنل مدیریت <?= e(setting('site_name','چورتکه')) ?></p>
    </div>
    <div class="welcome-time">
        <div class="time-big" id="dash-clock">--:--</div>
        <div class="date-small"><?= e(jdate('Y/m/d', time())) ?></div>
    </div>
</div>

<!-- ══ Alert ══ -->
<?php
$alerts = [];
if (($stats['pending_kyc'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/kyc').'" class="alert-link-inherit">'.fa_number($stats['pending_kyc']).' درخواست KYC</a>';
if (($stats['pending_withdrawals'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/withdrawals').'" class="alert-link-inherit">'.fa_number($stats['pending_withdrawals']).' برداشت در انتظار</a>';
if (($stats['open_tickets'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/tickets').'" class="alert-link-inherit">'.fa_number($stats['open_tickets']).' تیکت باز</a>';
if (!empty($alerts)):
?>
<div class="alert alert-warning">
    <span class="material-icons" aria-hidden="true">warning_amber</span>
    <span>نیاز به بررسی: <?= implode(' &nbsp;·&nbsp; ', $alerts) ?></span>
</div>
<?php endif; ?>

<!-- ══ Stats Row 1 ══ -->
<div class="stats-grid stats-4">

    <!-- کاربران -->
    <div class="stat-card stat-card-accent-gold">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">group</span></div>
            <div class="stat-card-body">
                <div class="stat-label">کل کاربران</div>
                <div class="stat-value"><?= e(fa_number(number_format($stats['total_users'] ?? 0))) ?></div>
                <span class="stat-change up"><span class="material-icons" aria-hidden="true">arrow_upward</span>+<?= e(fa_number($stats['today_users'] ?? 0)) ?> امروز</span>
            </div>
        </div>
        <div class="stat-footer">
            <span>این ماه: <strong><?= e(fa_number($stats['month_users'] ?? 0) )?></strong></span>
            <a href="<?= url('/admin/users') ?>" class="stat-footer-link">جزئیات <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

    <!-- درآمد -->
    <div class="stat-card stat-card-accent-up">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">payments</span></div>
            <div class="stat-card-body">
                <div class="stat-label">درآمد این ماه (تومان)</div>
                <div class="stat-value smaller"><?= e(fa_number(number_format((int)($stats['monthly_revenue'] ?? 0)))) ?></div>
                <span class="stat-change up"><span class="material-icons" aria-hidden="true">trending_up</span>کل: <?= e(fa_number(number_format((int)($stats['total_revenue'] ?? 0)))) ?></span>
            </div>
        </div>
        <div class="stat-footer">
            <span>واریز امروز: <strong><?= e(fa_number(number_format((int)($stats['today_deposits'] ?? 0)))) ?></strong></span>
            <a href="<?= url('/admin/transactions') ?>" class="stat-footer-link">گزارش <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

    <!-- تسک‌ها -->
    <div class="stat-card stat-card-accent-warn">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">assignment</span></div>
            <div class="stat-card-body">
                <div class="stat-label">تسک‌های فعال</div>
                <div class="stat-value"><?= e(fa_number(number_format($stats['active_tasks'] ?? 0))) ?></div>
                <span class="stat-change warn"><span class="material-icons" aria-hidden="true">hourglass_empty</span>کل: <?= e(fa_number($stats['total_tasks'] ?? 0)) ?></span>
            </div>
        </div>
        <?php $totalTasks = $stats['total_tasks'] ?? 0; $activeTasks = $stats['active_tasks'] ?? 0; $taskPct = $totalTasks > 0 ? round(($activeTasks/$totalTasks)*100) : 0; ?>
        <div class="stat-progress">
            <div class="stat-progress-bar"><div class="stat-progress-fill stat-progress-fill-var"></div></div>
            <div class="stat-progress-labels"><span>فعال: <?= $taskPct ?>٪</span><span>کل: <?= e(fa_number($stats['total_tasks'] ?? 0)) ?></span></div>
        </div>
    </div>

    <!-- کیف‌پول -->
    <div class="stat-card stat-card-accent-purple">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">account_balance_wallet</span></div>
            <div class="stat-card-body">
                <div class="stat-label">موجودی کل کیف‌پول‌ها</div>
                <div class="stat-value smaller"><?= e(fa_number(number_format((int)($stats['total_wallet_balance'] ?? 0)))) ?></div>
                <span class="stat-change neutral"><span class="material-icons" aria-hidden="true">info</span>مجموع کاربران</span>
            </div>
        </div>
        <div class="stat-footer">
            <span>میانگین هر کاربر: <strong><?= e(fa_number(number_format((int)(($stats['total_wallet_balance'] ?? 0) / max(1, $stats['total_users'] ?? 1)))) )?></strong></span>
            <a href="<?= url('/admin/transactions') ?>" class="stat-footer-link">کیف‌پول‌ها <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

</div>

<!-- ══ Stats Row 2 ══ -->
<div class="stats-grid stats-4 mt-2">

    <!-- تیکت‌ها -->
    <div class="stat-card stat-card-compact stat-card-accent-down">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">support_agent</span></div>
            <div class="stat-card-body">
                <div class="stat-label">تیکت‌های باز</div>
                <div class="stat-value"><?= e(fa_number($stats['open_tickets'] ?? 0)) ?></div>
                <div class="stat-desc">
                    <?php if (($stats['urgent_tickets'] ?? 0) > 0): ?><span class="pulse-dot"></span><?php endif; ?>
                    <?= e(fa_number($stats['urgent_tickets'] ?? 0)) ?> تیکت اورژانسی
                </div>
            </div>
        </div>
        <div class="stat-footer">
            <span>بدون پاسخ</span>
            <a href="<?= url('/admin/tickets') ?>" class="stat-footer-link">بررسی <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

    <!-- KYC -->
    <div class="stat-card stat-card-compact stat-card-accent-info">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">how_to_reg</span></div>
            <div class="stat-card-body">
                <div class="stat-label">KYC در انتظار</div>
                <div class="stat-value"><?= e(fa_number($stats['pending_kyc'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons" aria-hidden="true">schedule</span>نیاز به تأیید مدیر</div>
            </div>
        </div>
        <div class="stat-footer">
            <span>تأیید شده: <strong><?= e(fa_number($stats['approved_kyc'] ?? 0)) ?></strong></span>
            <a href="<?= url('/admin/kyc') ?>" class="stat-footer-link">بررسی <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

    <!-- برداشت -->
    <div class="stat-card stat-card-compact stat-card-accent-warn">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">account_balance</span></div>
            <div class="stat-card-body">
                <div class="stat-label">برداشت در انتظار</div>
                <div class="stat-value"><?= e(fa_number($stats['pending_withdrawals'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons" aria-hidden="true">payments</span><?= e(fa_number(number_format((int)($stats['pending_withdrawal_amount'] ?? 0)))) ?> تومان</div>
            </div>
        </div>
        <div class="stat-footer">
            <span>میانگین: <strong><?= e(fa_number(number_format((int)(($stats['pending_withdrawal_amount'] ?? 0) / max(1, $stats['pending_withdrawals'] ?? 1)))) )?></strong></span>
            <a href="<?= url('/admin/withdrawals') ?>" class="stat-footer-link">تأیید <span class="material-icons" aria-hidden="true">chevron_left</span></a>
        </div>
    </div>

    <!-- کاربران فعال -->
    <div class="stat-card stat-card-compact stat-card-accent-up">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons" aria-hidden="true">check_circle</span></div>
            <div class="stat-card-body">
                <div class="stat-label">کاربران فعال</div>
                <div class="stat-value"><?= e(fa_number($stats['active_users'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons" aria-hidden="true">block</span>مسدود: <?= e(fa_number($stats['banned_users'] ?? 0)) ?></div>
            </div>
        </div>
        <?php $totalUsers = $stats['total_users'] ?? 0; $activeUsers = $stats['active_users'] ?? 0; $activePct = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100) : 0; ?>
        <div class="stat-progress">
            <div class="stat-progress-bar"><div class="stat-progress-fill stat-progress-fill-var"></div></div>
            <div class="stat-progress-labels"><span>فعال: <?= $activePct ?>٪</span><span>کل: <?= e(fa_number($stats['total_users'] ?? 0)) ?></span></div>
        </div>
    </div>

</div>

<!-- ══ Main Grid (2:1) ══ -->
<div class="dashboard-grid mt-3">

    <!-- ─── ستون اصلی ─── -->
    <div>

        <!-- نمودار ثبت‌نام -->
        <div class="card">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">show_chart</span>ثبت‌نام‌های ۳۰ روز اخیر</h3>
                <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-secondary">گزارش کامل</a>
            </div>
            <div class="card-body">
                <canvas id="usersChart" height="70"></canvas>
            </div>
        </div>

        <!-- فعالیت‌های اخیر کاربران -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">timeline</span>فعالیت‌های اخیر کاربران</h3>
                <div class="filter-row">
                    <label for="activityTypeFilter" class="sr-only">فیلتر نوع فعالیت</label>
                    <select id="activityTypeFilter" class="form-control filter-select" aria-label="فیلتر نوع فعالیت">
                        <option value="all">همه فعالیت‌ها</option>
                        <option value="register">ثبت‌نام</option>
                        <option value="login">ورود</option>
                        <option value="kyc">احراز هویت</option>
                        <option value="task">انجام تسک</option>
                        <option value="withdraw">برداشت</option>
                        <option value="deposit">واریز</option>
                        <option value="card">افزودن کارت</option>
                        <option value="ad">ثبت تبلیغ</option>
                    </select>
                    <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-sm btn-secondary">مشاهده همه</a>
                </div>
            </div>
            <div class="card-body card-body-clean">
                <div id="userActivitiesContainer" class="activity-container">
                    <!-- محتوا از طریق AJAX بارگذاری می‌شود (skeleton اولیه توسط admin/dashboard.js جایگزین می‌شود) -->
                    <?php for ($s = 0; $s < 4; $s++): ?>
                    <div class="user-activity-item activity-item">
                        <div class="sk sk-circle skeleton-circle"></div>
                        <div class="activity-info">
                            <div class="sk sk-line skeleton-line"></div>
                            <div class="sk sk-line skeleton-line"></div>
                            <div class="sk sk-line skeleton-line"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div id="loadMoreContainer" class="load-more-wrap">
                    <button id="loadMoreBtn" class="btn btn-sm btn-secondary">بارگذاری بیشتر</button>
                </div>
            </div>
        </div>

        <!-- برداشت‌های در انتظار -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">payments</span>برداشت‌های در انتظار بررسی</h3>
                <a href="<?= url('/admin/withdrawals') ?>" class="btn btn-sm btn-primary">مشاهده همه</a>
            </div>
            <?php if (empty($pendingWithdrawalsList)): ?>
                <div class="empty-state empty-state-clean">
                    <span class="material-icons" aria-hidden="true" class="material-icons empty-icon-lg empty-icon-up">task_alt</span>
                    <p class="empty-title">هیچ برداشتی در انتظار نیست</p>
                    <p class="empty-subtitle">همهٔ درخواست‌های برداشت بررسی شده‌اند 🎉</p>
                    <a href="<?= url('/admin/withdrawals') ?>" class="btn btn-sm btn-secondary">تاریخچهٔ برداشت‌ها</a>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>مبلغ (تومان)</th>
                            <th>بانک</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                   <tbody>
<?php foreach ($pendingWithdrawalsList as $w):
    $userId = (int)($w['user_id'] ?? 0);
    $fullName = (string)($w['full_name'] ?? '-');
    $email = (string)($w['email'] ?? '');
    $amount = (int)($w['amount'] ?? 0);
    $bankName = (string)($w['bank_name'] ?? '-');
    $createdAt = (string)($w['created_at'] ?? 'now');

    $wg = $gradients[$userId % count($gradients)];
    $wi = mb_substr($fullName !== '' ? $fullName : 'ک', 0, 1, 'UTF-8');
?>
    <tr>
        <td data-label="کاربر">
            <div class="user-cell">
                <div class="user-avatar-sm"><?= e($wi) ?></div>
                <div class="user-cell-info">
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e($email) ?></small>
                </div>
            </div>
        </td>
        <td data-label="مبلغ (تومان)"><span class="amount-cell positive"><?= fa_number(number_format($amount)) ?></span></td>
        <td data-label="بانک" class="cell-muted-sm"><?= e($bankName) ?></td>
        <td data-label="زمان" class="cell-muted-xs"><?= jdate('Y/m/d H:i', strtotime($createdAt)) ?></td>
        <td data-label="عملیات">
            <div class="action-btns">
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn approve" title="تأیید"   aria-label="تأیید برداشت <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">check</span></a>
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn reject"  title="رد"      aria-label="رد برداشت <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">close</span></a>
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn view"    title="جزئیات"  aria-label="جزئیات برداشت <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">visibility</span></a>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- کاربران جدید -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">person_add</span>آخرین کاربران ثبت‌نام شده</h3>
                <a href="<?= url('/admin/users') ?>" class="btn btn-sm btn-secondary">مشاهده همه</a>
            </div>
            <?php if (empty($recentUsers)): ?>
                <div class="empty-state empty-state-clean">
                    <span class="material-icons" aria-hidden="true" class="material-icons empty-icon-lg empty-icon-muted">group_off</span>
                    <p class="empty-title">هنوز کاربری ثبت‌نام نکرده</p>
                    <p class="empty-subtitle">به‌محض ثبت‌نام اولین کاربر، اینجا نمایش داده می‌شود</p>
                    <a href="<?= url('/admin/users') ?>" class="btn btn-sm btn-secondary">مدیریت کاربران</a>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>موبایل</th>
                            <th>نقش</th>
                            <th>وضعیت</th>
                            <th>تاریخ عضویت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                <tbody>
<?php foreach ($recentUsers as $u):
    $uid = (int)($u['id'] ?? 0);
    $fullName = (string)($u['full_name'] ?? '-');
    $email = (string)($u['email'] ?? '');
    $mobile = (string)($u['mobile'] ?? '-');
    $role = (string)($u['role'] ?? '');
    $status = (string)($u['status'] ?? '');
    $createdAt = (string)($u['created_at'] ?? 'now');

    $ug = $gradients[$uid % count($gradients)];
    $ui = mb_substr($fullName !== '' ? $fullName : 'ک', 0, 1, 'UTF-8');
?>
    <tr>
        <td data-label="کاربر">
            <div class="user-cell">
                <div class="user-avatar-sm"><?= e($ui) ?></div>
                <div class="user-cell-info">
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e($email) ?></small>
                </div>
            </div>
        </td>
        <td data-label="موبایل" class="cell-muted-sm cell-ltr"><?= e($mobile) ?></td>
        <td data-label="نقش"><span class="badge <?= e($roleColors[$role] ?? 'badge-muted' ) ?>"><?= e($roleNames[$role] ?? 'کاربر' ) ?></span></td>
        <td data-label="وضعیت"><span class="badge <?= e($statusColors[$status] ?? 'badge-muted' ) ?>"><?= e($statusNames[$status] ?? '-' ) ?></span></td>
        <td data-label="تاریخ عضویت" class="cell-muted-xs"><?= jdate('Y/m/d', strtotime($createdAt)) ?></td>
        <td data-label="عملیات">
            <div class="action-btns">
                <a href="<?= url('/admin/users/' . $uid) ?>"      class="icon-btn view"   title="مشاهده" aria-label="مشاهده کاربر <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">visibility</span></a>
                <a href="<?= url('/admin/users/edit/' . $uid) ?>" class="icon-btn edit"   title="ویرایش" aria-label="ویرایش کاربر <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">edit</span></a>
                <a href="<?= url('/admin/users') ?>"              class="icon-btn delete" title="مسدود"  aria-label="مسدود کردن کاربر <?= e($fullName) ?>"><span class="material-icons" aria-hidden="true">block</span></a>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /ستون اصلی -->

    <!-- ─── ستون راست ─── -->
    <div class="right-column">

        <!-- اقدامات سریع -->
        <div class="card">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">flash_on</span>اقدامات سریع</h3>
            </div>
            <div class="card-body">
                <div class="quick-action-grid">
                    <a class="quick-action" href="<?= url('/admin/kyc') ?>"                class="quick-action-orange">
                        <span class="material-icons" aria-hidden="true">how_to_reg</span><span>بررسی KYC</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/withdrawals') ?>"        class="quick-action-green">
                        <span class="material-icons" aria-hidden="true">payments</span><span>تأیید برداشت</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/tickets') ?>"            class="quick-action-accent">
                        <span class="material-icons" aria-hidden="true">support_agent</span><span>تیکت‌ها</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/notifications/send') ?>" class="quick-action-purple">
                        <span class="material-icons" aria-hidden="true">notification_add</span><span>ارسال اعلان</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/users') ?>"              class="quick-action-cyan">
                        <span class="material-icons" aria-hidden="true">group</span><span>کاربران</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/settings') ?>"           class="quick-action-muted">
                        <span class="material-icons" aria-hidden="true">settings</span><span>تنظیمات</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- وضعیت سیستم -->
        <div class="w-card w-card-up">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">monitor_heart</span></div>
                    <h3>وضعیت سیستم</h3>
                </div>
                <div class="w-header-actions">
                    <span class="w-badge" id="sysStatusBadge">سرویس‌ها</span>
                    <button id="refreshSystemStatus" type="button" class="w-refresh-btn" title="بروزرسانی" aria-label="بروزرسانی وضعیت سیستم"><span class="material-icons" aria-hidden="true">refresh</span></button>
                </div>
            </div>
            <div id="systemStatusContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- Cron Jobs -->
        <div class="w-card w-card-purple">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">schedule</span></div>
                    <h3>Cron Jobs</h3>
                </div>
                <span class="w-badge w-badge-purple" id="cronBadge">بارگذاری...</span>
            </div>
            <div id="cronJobsContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- درگاه‌های پرداخت -->
        <div class="w-card w-card-gold">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">payment</span></div>
                    <h3>درگاه‌های پرداخت</h3>
                </div>
                <span class="w-badge" id="gatesBadge">درگاه‌ها</span>
            </div>
            <div id="paymentGatesContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- صف ایمیل -->
        <div class="w-card w-card-cyan">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">email</span></div>
                    <h3>صف ایمیل</h3>
                </div>
                <span class="w-badge w-badge-warn" id="emailBadge">...</span>
            </div>
            <div id="emailQueueContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- منابع سرور -->
        <div class="w-card w-card-info">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">memory</span></div>
                    <h3>منابع سرور</h3>
                </div>
            </div>
            <div id="serverResourcesContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- سلامت دیتابیس -->
        <div class="w-card w-card-purple">
            <a href="<?= url('/admin/database-health') ?>" style="text-decoration:none;color:inherit">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">dns</span></div>
                    <h3>سلامت دیتابیس</h3>
                </div>
                <span class="w-badge w-badge-info" id="dbHealthBadge">...</span>
            </div>
            </a>
            <div id="dbHealthContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- آمار خلاصه -->
        <?php
        $totalU = $stats['total_users'] ?? 0; $actU = $stats['active_users'] ?? 0;
        $activeRate = $totalU > 0 ? min(round(($actU / $totalU) * 100), 100) : 0;
        $rateColor  = $activeRate >= 70 ? 'var(--up)' : ($activeRate >= 40 ? 'var(--warn)' : 'var(--down)');
        ?>
        <div class="w-card w-card-gold">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons" aria-hidden="true">bar_chart</span></div>
                    <h3>خلاصه آمار</h3>
                </div>
                <a href="<?= url('/admin/kpi') ?>" class="w-kpi-link">
                    <span class="material-icons" aria-hidden="true">insights</span>KPI
                </a>
            </div>
            <div class="w-body">
                <div class="w-mini-grid">
                    <div class="w-mini-stat w-mini-up">
                        <div class="w-mini-label">کاربران فعال</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['active_users'] ?? 0)) ?></div>
                        <div class="w-mini-sub w-mini-sub-up">+<?= e(fa_number($stats['today_users'] ?? 0)) ?> امروز</div>
                    </div>
                    <div class="w-mini-stat w-mini-down">
                        <div class="w-mini-label">کاربران مسدود</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['banned_users'] ?? 0)) ?></div>
                        <div class="w-mini-sub w-mini-sub-down"><?= (100 - $activeRate) ?>٪ کل</div>
                    </div>
                    <div class="w-mini-stat w-mini-warn">
                        <div class="w-mini-label">تسک‌های فعال</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['active_tasks'] ?? 0)) ?></div>
                        <div class="w-mini-sub">از <?= e(fa_number($stats['total_tasks'] ?? 0)) ?> کل</div>
                    </div>
                    <div class="w-mini-stat w-mini-down">
                        <div class="w-mini-label">تیکت اورژانسی</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['urgent_tickets'] ?? 0)) ?></div>
                        <div class="w-mini-sub w-mini-sub-down">بی‌پاسخ</div>
                    </div>
                    <div class="w-mini-stat w-mini-full w-mini-info">
                        <div class="w-mini-stat-row">
                            <div>
                                <div class="w-mini-label">KYC در انتظار</div>
                                <div class="w-mini-val"><?= e(fa_number($stats['pending_kyc'] ?? 0)) ?></div>
                            </div>
                            <a href="<?= url('/admin/kyc') ?>" class="w-inline-link">
                                بررسی<span class="material-icons" aria-hidden="true">chevron_left</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="w-rate-card">
                    <div class="w-rate-header">
                        <span class="w-rate-title">نرخ فعال‌سازی کاربران</span>
                        <span class="w-rate-pct w-rate-pct-var"><?= $activeRate ?>٪</span>
                    </div>
                    <div class="w-prog-bar">
                        <div class="w-prog-fill w-prog-fill-var"></div>
                    </div>
                    <div class="w-rate-sub">فعال: <?= fa_number($stats['active_users'] ?? 0) ?> از <?= fa_number($stats['total_users'] ?? 0) ?> کاربر</div>
                </div>
            </div>
        </div>

        <!-- ورود/خروج مدیران -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons" aria-hidden="true">admin_panel_settings</span>دسترسی مدیران</h3>
            </div>
            <div class="card-body card-body-sm">
                <?php
                $adminAccessLog = $adminAccessLog ?? [];
                $actionLabels = [
                    'login'         => ['label' => 'ورود',         'icon' => 'login',        'color' => 'var(--green)'],
                    'login_success' => ['label' => 'ورود',         'icon' => 'login',        'color' => 'var(--green)'],
                    'admin_login'   => ['label' => 'ورود ادمین',   'icon' => 'admin_panel_settings', 'color' => 'var(--green)'],
                    'logout'        => ['label' => 'خروج',         'icon' => 'logout',       'color' => 'var(--orange)'],
                    'admin_logout'  => ['label' => 'خروج ادمین',   'icon' => 'logout',       'color' => 'var(--orange)'],
                    'login_failed'  => ['label' => 'ورود ناموفق',  'icon' => 'lock',         'color' => 'var(--red)'],
                ];
                // رنگ نقش‌ها (با همان نام‌های مشترک $roleNames بالای فایل هماهنگ است)
                $roleChip = ['admin' => 'role-admin', 'support' => 'role-support'];
                if (!empty($adminAccessLog)): ?>
                    <ul class="access-list" aria-label="آخرین ورود و خروج مدیران">
                    <?php foreach (array_slice($adminAccessLog, 0, 10) as $log):
                        $log      = is_array($log) ? $log : [];
                        $action   = $log['type'] ?? $log['action'] ?? 'login';
                        $meta     = $actionLabels[$action] ?? ['label' => $action, 'icon' => 'history', 'color' => 'var(--text-muted)'];
                        $role     = (string)($log['role'] ?? '');
                        $roleName = $roleNames[$role] ?? 'کاربر';
                        $chipCls  = $roleChip[$role] ?? 'role-user';
                    ?>
                        <li class="access-row">
                            <span class="material-icons access-icon access-icon-var" aria-hidden="true"><?= e($meta['icon']) ?></span>
                            <div class="access-info">
                                <div class="access-line">
                                    <span class="access-name"><?= e($log['full_name'] ?? 'نامشخص') ?></span>
                                    <span class="role-chip <?= $chipCls ?>"><?= e($roleName) ?></span>
                                    <span class="access-action access-action-var"><?= e($meta['label']) ?></span>
                                </div>
                                <div class="access-meta">
                                    <span><?= e($log['time_ago'] ?? '') ?></span>
                                    <?php if (!empty($log['ip_address'])): ?>
                                        <span aria-hidden="true">•</span><span dir="ltr"><?= e($log['ip_address']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state empty-state-sm">
                        <span class="material-icons empty-icon-md" aria-hidden="true">manage_accounts</span>
                        <p class="empty-title-sm">هنوز ورود یا خروجی ثبت نشده</p>
                        <p class="empty-subtitle-sm">پس از اولین ورود ادمین اینجا نمایش داده می‌شود</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /ستون راست -->

</div><!-- /dashboard-grid -->
<?php
// بعد از header یا در جای مناسب
$sentryWidgetPath = BASE_PATH . '/views/partials/sentry-widget.php';
if (is_file($sentryWidgetPath)) {
    include $sentryWidgetPath;
}
?>
<?php
$content = ob_get_clean();

/* ── آماده‌سازی داده‌های موردنیاز جاوااسکریپت ──────────────────────
 * این داده‌ها به‌جای hard-code داخل اسکریپت، به‌صورت یک تگ JSON امن
 * به فایل خارجی admin/dashboard.js پاس داده می‌شوند.
 * آدرس‌ها همگی با asset()/url() ساخته می‌شوند تا روی هر سرور یا
 * زیرشاخه‌ای بدون شکستن آدرس‌دهی کار کنند.
 */
$chartLabels = [];
for ($i = 29; $i >= 0; $i--) {
    $chartLabels[] = jdate('m/d', strtotime("-{$i} days"));
}

$dashboardBootstrap = [
    'chartData'   => array_values($chartData ?? array_fill(0, 30, 0)),
    'chartLabels' => $chartLabels,
    'urls'        => [
        'recentActivity' => url('/admin/dashboard/recent-activity'),
        'systemStatus'   => url('/admin/dashboard/system-status'),
    ],
    'assets'      => [
        'defaultAvatar'  => asset('assets/images/default-avatar.png'),
    ],
];

/* اسکریپت‌های این صفحه به جایگاه استاندارد <?= $scripts
ob_start();
?>
<!-- داده‌های داشبورد (فقط داده؛ اجرا نمی‌شود، نیازی به nonce ندارد) -->





<?php
$scripts
?>
<?php /* ── بلوک قدیمی JS inline حذف شد و به public/assets/js/admin/dashboard.js منتقل گردید ── */ ?>

<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admin/dashboard.css') . '">';
include view_path('layouts.admin');
?>