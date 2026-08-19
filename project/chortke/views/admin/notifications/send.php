<?php
$pageTitle = 'ارسال اعلان';
ob_start();
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><span class="material-icons" aria-hidden="true">campaign</span> ارسال اعلان به کاربران</h5>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/notifications/stats') ?>" class="btn btn-sm btn-outline-info">
                <span class="material-icons" aria-hidden="true">bar_chart</span> آمار
            </a>
            <a href="<?= url('/admin/notifications/templates') ?>" class="btn btn-sm btn-outline-secondary">
                <span class="material-icons" aria-hidden="true">description</span> قالب‌ها
            </a>
        </div>
    </div>

    <div class="card-body">
        <form method="POST" action="<?= url('/admin/notifications/send') ?>" id="sendForm">
            <?= csrf_field() ?>

            <div class="row g-3">

                <!-- ستون چپ — مخاطب -->
                <div class="col-lg-5">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <h6 class="mb-3 text-muted">مخاطب</h6>

                            <!-- target selector -->
                            <div class="mb-3">
                                <label class="form-label">نوع ارسال</label>
                                <div class="d-flex gap-2 flex-wrap">
                                    <input type="radio" class="btn-check" name="target" id="target_all" value="all" checked>
                                    <label class="btn btn-outline-primary btn-sm" for="target_all">
                                        <span class="material-icons" aria-hidden="true">group</span> همه کاربران
                                    </label>

                                    <input type="radio" class="btn-check" name="target" id="target_segment" value="segment">
                                    <label class="btn btn-outline-warning btn-sm" for="target_segment">
                                        <span class="material-icons" aria-hidden="true">filter_list</span> گروه / Segment
                                    </label>

                                    <input type="radio" class="btn-check" name="target" id="target_user" value="user">
                                    <label class="btn btn-outline-secondary btn-sm" for="target_user">
                                        <span class="material-icons" aria-hidden="true">person</span> کاربر خاص
                                    </label>
                                </div>
                            </div>

                            <!-- Segment selector -->
                            <div id="segmentSection" class="mb-3">
                                <label class="form-label">انتخاب Segment</label>
                                <select name="segment" class="form-select">
                                    <?php foreach ($segments as $key => $label): ?>
                                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- فیلترهای custom segment -->
                                <div id="customFilters" class="mt-3">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small">وضعیت کاربر</label>
                                            <select name="filters[status]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="active">فعال</option>
                                                <option value="inactive">غیرفعال</option>
                                                <option value="banned">مسدود</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">وضعیت KYC</label>
                                            <select name="filters[kyc_status]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="approved">تأیید‌شده</option>
                                                <option value="pending">در انتظار</option>
                                                <option value="rejected">رد‌شده</option>
                                                <option value="none">بدون KYC</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">سطح کاربر</label>
                                            <select name="filters[level]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="silver">نقره</option>
                                                <option value="gold">طلا</option>
                                                <option value="vip">VIP</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">ثبت‌نام از تاریخ</label>
                                            <input type="date" name="filters[registered_after]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- user ID -->
                            <div id="userSection" class="mb-3">
                                <label class="form-label">شناسه کاربر</label>
                                <input type="number" name="user_id" class="form-control" placeholder="مثلاً ۱۲۳۴">
                            </div>

                            <!-- زمان‌بندی -->
                            <hr>
                            <div class="mb-0">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="enableSchedule">
                                    <label class="form-check-label" for="enableSchedule">زمان‌بندی ارسال</label>
                                </div>
                                <div id="scheduleSection">
                                    <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm"
                                           min="<?= date('Y-m-d\TH:i') ?>">
                                    <small class="text-muted">اگر خالی بماند، فوراً ارسال می‌شود.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ستون راست — محتوا -->
                <div class="col-lg-7">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">نوع اعلان</label>
                            <select name="type" class="form-select" required>
                                <?php foreach ($notification_types as $val => $label): ?>
                                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">اولویت</label>
                            <select name="priority" class="form-select">
                                <option value="normal">عادی</option>
                                <option value="high">مهم</option>
                                <option value="urgent">فوری</option>
                                <option value="low">کم‌اهمیت</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">عنوان <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" maxlength="255" required
                                   placeholder="عنوان نوتیفیکیشن">
                            <small class="text-muted char-counter" data-target="title">۰ / ۲۵۵</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label">متن پیام <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control" rows="4" required
                                      placeholder="متن نوتیفیکیشن..."></textarea>
                            <small class="text-muted char-counter" data-target="message">۰ / ۵۰۰</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">لینک دکمه (اختیاری)</label>
                            <input type="text" name="action_url" class="form-control"
                                   placeholder="/wallet یا https://...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">متن دکمه (اختیاری)</label>
                            <input type="text" name="action_text" class="form-control"
                                   placeholder="مثلاً: مشاهده کیف پول">
                        </div>

                        <!-- پیش‌نمایش -->
                        <div class="col-12">
                            <div class="notif-preview p-3 rounded bg-light border" id="notifPreview">
                                <div class="d-flex gap-3">
                                    <div class="notif-preview-icon">
                                        <span class="material-icons text-primary" aria-hidden="true">notifications</span>
                                    </div>
                                    <div>
                                        <strong id="previewTitle">—</strong>
                                        <p class="mb-1 small" id="previewMessage">—</p>
                                        <a href="#" id="previewAction" class="small text-primary">پیش‌نمایش لینک</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- دکمه‌ها -->
                <div class="col-12 d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span class="material-icons" aria-hidden="true">send</span> ارسال اعلان
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                        <span class="material-icons" aria-hidden="true">visibility</span> پیش‌نمایش
                    </button>
                    <a href="<?= url('/admin/notifications') ?>" class="btn btn-outline-secondary ms-auto">
                        انصراف
                    </a>
                </div>

            </div>
        </form>
    </div>
</div>


<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>

