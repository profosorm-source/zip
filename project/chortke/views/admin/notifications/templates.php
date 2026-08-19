<?php
$pageTitle = 'قالب‌های نوتیفیکیشن';
ob_start();
?>
<div id="notificationsRoot" data-base="<?= url('/admin/notifications') ?>" data-tpl-save="<?= url('/admin/notifications/templates/save') ?>" data-tpl-delete="<?= url('/admin/notifications/templates/delete') ?>"></div>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h5 class="mb-0"><span class="material-icons" aria-hidden="true">description</span> قالب‌های نوتیفیکیشن</h5>
    <a href="<?= url('/admin/notifications/send') ?>" class="btn btn-sm btn-primary">
        <span class="material-icons" aria-hidden="true">send</span> ارسال اعلان
    </a>
</div>

<div class="alert alert-info">
    <span class="material-icons" aria-hidden="true">info</span>
    قالب‌های پیش‌فرض در کد تعریف شده‌اند. می‌توانید آن‌ها را از اینجا override کنید.
    متغیرهای هر قالب مشخص‌اند — تنها همان متغیرها قابل استفاده‌اند.
</div>

<div class="row g-4">
<?php foreach ($templates as $key => $tpl): ?>
<div class="col-md-6">
    <div class="card h-100 <?= $tpl['has_override'] ? 'border-warning' : '' ?>">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div>
                <code class="text-primary"><?= e($key) ?></code>
                <?php if ($tpl['has_override']): ?>
                    <span class="badge bg-warning text-dark ms-2">Override فعال</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-primary edit-btn"
                        data-key="<?= e($key) ?>"
                        data-title="<?= e(e($tpl['override_title'] ?? $tpl['default_title'])) ?>"
                        data-message="<?= e(e($tpl['override_message'] ?? $tpl['default_message'])) ?>"
                        data-vars='<?= json_encode($tpl['variables'], JSON_UNESCAPED_UNICODE) ?>'>
                    <span class="material-icons" aria-hidden="true">edit</span> ویرایش
                </button>
                <?php if ($tpl['has_override']): ?>
                <button class="btn btn-xs btn-outline-danger reset-btn"
                        data-key="<?= e($key) ?>">
                    <span class="material-icons" aria-hidden="true">undo</span> بازگشت
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- پیش‌فرض -->
            <div class="mb-2">
                <div class="small text-muted mb-1">عنوان <?= $tpl['has_override'] ? '(پیش‌فرض)' : '' ?>:</div>
                <div class="fw-semibold small"><?= e($tpl['default_title']) ?></div>
            </div>
            <div class="mb-3">
                <div class="small text-muted mb-1">متن <?= $tpl['has_override'] ? '(پیش‌فرض)' : '' ?>:</div>
                <div class="text-muted small"><?= e($tpl['default_message']) ?></div>
            </div>

            <!-- override فعال -->
            <?php if ($tpl['has_override']): ?>
            <hr>
            <div class="mb-2">
                <div class="small text-warning mb-1">عنوان (override):</div>
                <div class="fw-semibold small text-warning"><?= e($tpl['override_title']) ?></div>
            </div>
            <div class="mb-2">
                <div class="small text-warning mb-1">متن (override):</div>
                <div class="text-warning small"><?= e($tpl['override_message']) ?></div>
            </div>
            <?php endif; ?>

            <!-- متغیرها -->
            <?php if (!empty($tpl['variables'])): ?>
            <div class="mt-2">
                <div class="small text-muted mb-1">متغیرهای قابل استفاده:</div>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($tpl['variables'] as $var => $desc): ?>
                    <span class="badge bg-light text-dark border" title="<?= e($desc) ?>">
                        <code>{{<?= e($var) ?>}}</code>
                        <span class="text-muted ms-1"><?= e($desc) ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="small text-muted">این قالب متغیر dynamic ندارد.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Modal ویرایش -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ویرایش قالب: <code id="modalTemplateKey">—</code></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editKey">

                <div class="mb-3">
                    <label class="form-label">متغیرهای مجاز این قالب:</label>
                    <div id="modalVarBadges" class="d-flex flex-wrap gap-1 mb-2"></div>
                    <small class="text-muted">روی هر متغیر کلیک کنید تا در فیلد فعال درج شود.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">عنوان</label>
                    <input type="text" class="form-control" id="editTitle" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">متن پیام</label>
                    <textarea class="form-control" id="editMessage" rows="4"></textarea>
                </div>

                <div id="editError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="saveTemplateBtn">
                    <span class="material-icons" aria-hidden="true">save</span> ذخیره Override
                </button>
            </div>
        </div>
    </div>
</div>


<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>

