<?php
$title = 'اعلان‌ها';

ob_start();
?>
<div id="notificationsRoot" data-base="<?= url('/admin/notifications') ?>" data-tpl-save="<?= url('/admin/notifications/templates/save') ?>" data-tpl-delete="<?= url('/admin/notifications/templates/delete') ?>"></div>


<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">همه اعلان‌ها</h5>
                <button type="button" class="btn btn-sm btn-primary" id="markAllRead">
                    <i class="material-icons">done_all</i>
                    علامت همه به عنوان خوانده شده
                </button>
            </div>

            <!-- فیلترها -->
            <div class="card-body border-bottom">
                <div class="row g-3">
                    <div class="col-md-4">
                        <select id="filterType" class="form-select form-select-sm">
                            <option value="">همه انواع</option>
                            <option value="kyc_submitted">احراز هویت</option>
                            <option value="bank_card_submitted">کارت بانکی</option>
                            <option value="withdrawal_request">درخواست برداشت</option>
                            <option value="deposit_manual">واریز دستی</option>
                            <option value="new_user">کاربر جدید</option>
                            <option value="new_ticket">تیکت جدید</option>
                            <option value="task_submitted">تسک</option>
                            <option value="story_order">سفارش استوری</option>
                            <option value="content_submitted">محتوا</option>
                            <option value="system_alert">هشدار سیستم</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select id="filterStatus" class="form-select form-select-sm">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="unread">خوانده نشده</option>
                            <option value="read">خوانده شده</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-secondary btn-sm w-100" id="applyFilter">
                            <i class="material-icons">filter_list</i>
                            اعمال فیلتر
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="material-icons text-muted">notifications_none</i>
                        <p class="text-muted mt-3">هیچ اعلانی وجود ندارد</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush" id="notificationList">
                        <?php
                        // نگاشت type → رنگ و آیکون (چون ستون‌های color/icon در جدول نیستند)
                        $typeMap = [
                            'deposit'            => ['color' => 'success',   'icon' => 'account_balance_wallet'],
                            'withdrawal'         => ['color' => 'warning',   'icon' => 'payments'],
                            'task'               => ['color' => 'info',      'icon' => 'task_alt'],
                            'kyc'                => ['color' => 'warning',   'icon' => 'verified_user'],
                            'lottery'            => ['color' => 'purple',    'icon' => 'casino'],
                            'referral'           => ['color' => 'primary',   'icon' => 'people'],
                            'security'           => ['color' => 'danger',    'icon' => 'security'],
                            'investment'         => ['color' => 'success',   'icon' => 'trending_up'],
                            'info'               => ['color' => 'info',      'icon' => 'info'],
                            'system'             => ['color' => 'secondary', 'icon' => 'settings'],
                            'kyc_submitted'      => ['color' => 'warning',   'icon' => 'verified_user'],
                            'bank_card_submitted'=> ['color' => 'info',      'icon' => 'credit_card'],
                            'withdrawal_request' => ['color' => 'danger',    'icon' => 'payments'],
                            'deposit_manual'     => ['color' => 'success',   'icon' => 'account_balance_wallet'],
                            'new_user'           => ['color' => 'primary',   'icon' => 'person_add'],
                            'new_ticket'         => ['color' => 'warning',   'icon' => 'confirmation_number'],
                            'task_submitted'     => ['color' => 'info',      'icon' => 'task_alt'],
                            'story_order'        => ['color' => 'primary',   'icon' => 'auto_stories'],
                            'content_submitted'  => ['color' => 'success',   'icon' => 'article'],
                            'system_alert'       => ['color' => 'danger',    'icon' => 'warning'],
                        ];
                        ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                            // استخراج link، color و icon از ستون‌های واقعی دیتابیس
                            $notifLink  = $notif->action_url ?? null;
                            $notifType  = $notif->type ?? 'system';
                            $notifMeta  = $typeMap[$notifType] ?? ['color' => 'secondary', 'icon' => 'notifications'];
                            $notifColor = $notifMeta['color'];
                            $notifIcon  = $notifMeta['icon'];
                            ?>
                            <a href="<?= $notifLink ?: '#' ?>" 
                               class="list-group-item list-group-item-action notification-item <?= $notif->is_read ? '' : 'unread' ?>"
                               data-id="<?= e($notif->id) ?>"
                               data-click="markAsRead" data-args="<?= e($notif->id) ?>">
                                <div class="d-flex align-items-start">
                                    <!-- آیکون -->
                                    <div class="flex-shrink-0">
                                        <div class="rounded-circle bg-<?= e($notifColor) ?> bg-opacity-10 p-3">
                                            <i class="material-icons text-<?= e($notifColor) ?>">
                                                <?= e($notifIcon) ?>
                                            </i>
                                        </div>
                                    </div>

                                    <!-- محتوا -->
                                    <div class="flex-grow-1 ms-3">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h6 class="mb-0 <?= $notif->is_read ? 'text-muted' : '' ?>">
                                                <?= e($notif->title) ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?= e(time_ago($notif->created_at)) ?>
                                            </small>
                                        </div>
                                        <p class="mb-1 text-muted small">
                                            <?= e($notif->message) ?>
                                        </p>

                                        <!-- Badge نوع -->
                                        <?php
                                        $typeLabels = [
                                            'kyc_submitted'       => ['label' => 'احراز هویت', 'color' => 'warning'],
                                            'bank_card_submitted'  => ['label' => 'کارت بانکی', 'color' => 'info'],
                                            'withdrawal_request'   => ['label' => 'برداشت',      'color' => 'danger'],
                                            'deposit_manual'       => ['label' => 'واریز',        'color' => 'success'],
                                            'new_user'             => ['label' => 'کاربر جدید',  'color' => 'primary'],
                                            'new_ticket'           => ['label' => 'تیکت',         'color' => 'warning'],
                                            'task_submitted'       => ['label' => 'تسک',          'color' => 'info'],
                                            'story_order'          => ['label' => 'استوری',       'color' => 'primary'],
                                            'content_submitted'    => ['label' => 'محتوا',        'color' => 'success'],
                                            'system_alert'         => ['label' => 'هشدار',        'color' => 'danger'],
                                            'deposit'              => ['label' => 'واریز',        'color' => 'success'],
                                            'withdrawal'           => ['label' => 'برداشت',       'color' => 'warning'],
                                            'task'                 => ['label' => 'تسک',          'color' => 'info'],
                                            'kyc'                  => ['label' => 'احراز هویت',   'color' => 'warning'],
                                            'lottery'              => ['label' => 'قرعه‌کشی',     'color' => 'primary'],
                                            'referral'             => ['label' => 'معرفی',        'color' => 'primary'],
                                            'security'             => ['label' => 'امنیتی',       'color' => 'danger'],
                                            'investment'           => ['label' => 'سرمایه‌گذاری', 'color' => 'success'],
                                            'info'                 => ['label' => 'اطلاع‌رسانی',  'color' => 'info'],
                                            'system'               => ['label' => 'سیستم',        'color' => 'secondary'],
                                        ];
                                        $typeBadge = $typeLabels[$notifType] ?? ['label' => $notifType, 'color' => 'secondary'];
                                        ?>
                                        <span class="badge bg-<?= e($typeBadge['color']) ?> badge-sm">
                                            <?= e($typeBadge['label']) ?>
                                        </span>

                                        <?php if (!$notif->is_read): ?>
                                            <span class="badge bg-primary badge-sm ms-1">جدید</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- دکمه حذف -->
                                    <div class="flex-shrink-0 ms-2">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger btn-delete"
                                                data-id="<?= e($notif->id) ?>"
                                                data-click="deleteNotification" data-args="<?= e($notif->id) ?>" data-stop>
                                            <i class="material-icons">delete</i>
                                        </button>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($notifications)): ?>
                <div class="card-footer text-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="loadMore">
                        بارگذاری بیشتر
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>
