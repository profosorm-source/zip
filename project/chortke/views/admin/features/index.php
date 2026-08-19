<?php
$pageTitle = 'مدیریت پیشرفته فیچرها';
ob_start();
?>



<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>
            <i class="material-icons">flag</i>
            مدیریت فوق پیشرفته فیچرها (Feature Flags Ultimate)
        </h4>
        <div>
            <button class="btn btn-success" data-click="showCreateModal">
                <i class="material-icons">add</i>
                افزودن فیچر جدید
            </button>
            <button class="btn btn-info" data-click="refreshStats">
                <i class="material-icons">refresh</i>
                بروزرسانی آمار
            </button>
        </div>
    </div>

    <!-- Advanced Features Banner -->
    <div class="advanced-features mb-4">
        <h5>
            <i class="material-icons">stars</i>
            قابلیت‌های فوق پیشرفته فعال شده
        </h5>
        <div class="row">
            <div class="col-md-3">
                <i class="material-icons">location_on</i>
                <small>Targeting جغرافیایی</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">devices</i>
                <small>Targeting دستگاهی</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">schedule</i>
                <small>زمان‌بندی هوشمند</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">analytics</i>
                <small>متریک‌های پیشرفته</small>
            </div>
        </div>
    </div>

    <div class="stats-card">
        <h5 class="mb-3">
            <i class="material-icons">analytics</i>
            آمار فیچرها
        </h5>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value" id="totalFeatures"><?= is_array($features ?? null) ? count($features) : 0 ?></div>
                    <div class="stat-label">کل فیچرها</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-success" id="activeFeatures">
                        <?= is_array($features ?? null) ? count(array_filter($features, fn($f) => is_object($f) ? (!empty($f->enabled)) : (!empty($f['enabled'])))) : 0 ?>
                    </div>
                    <div class="stat-label">فعال</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-warning" id="abTestingFeatures">
                        <?= count(array_filter($features, fn($f) => $f->enabled_percentage < 100)) ?>
                    </div>
                    <div class="stat-label">A/B Testing</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-info" id="targetedFeatures">
                        <?= count(array_filter($features, fn($f) => 
                            ($f->enabled_for_roles || $f->enabled_for_users || 
                             $f->enabled_for_countries || $f->enabled_for_devices)
                        )) ?>
                    </div>
                    <div class="stat-label">Targeted</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="material-icons">info</i>
        <strong>راهنما:</strong> 
        با Feature Flags می‌توانید بخش‌های مختلف را بدون تغییر کد فعال/غیرفعال کنید، 
        فیچرها را محدود به نقش‌ها یا کاربران خاص کنید، و A/B Testing انجام دهید.
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <input type="text" id="searchFeature" class="form-control" 
                           placeholder="جستجوی نام یا توضیحات...">
                </div>
                <div class="col-md-2">
                    <select id="filterStatus" class="form-control">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="enabled">فعال</option>
                        <option value="disabled">غیرفعال</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterTargeting" class="form-control">
                        <option value="">همه انواع</option>
                        <option value="public">عمومی</option>
                        <option value="role">نقش</option>
                        <option value="user">کاربر</option>
                        <option value="country">کشور</option>
                        <option value="device">دستگاه</option>
                        <option value="route">مسیر</option>
                        <option value="age">سن</option>
                        <option value="time">زمان</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterEnvironment" class="form-control">
                        <option value="">همه محیط‌ها</option>
                        <option value="production">Production</option>
                        <option value="staging">Staging</option>
                        <option value="development">Development</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary w-100" data-click="clearFilters">
                        پاک کردن فیلترها
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="featuresContainer">
        <?php foreach ($features as $feature): ?>
            <?php
            $rolesArray = $feature->enabled_for_roles ? json_decode($feature->enabled_for_roles, true) : [];
            $usersArray = $feature->enabled_for_users ? json_decode($feature->enabled_for_users, true) : [];
            $countriesArray = $feature->enabled_for_countries ? json_decode($feature->enabled_for_countries, true) : [];
            $devicesArray = $feature->enabled_for_devices ? json_decode($feature->enabled_for_devices, true) : [];
            $routesArray = $feature->enabled_for_routes ? json_decode($feature->enabled_for_routes, true) : [];
            $dependsOnArray = $feature->depends_on ? json_decode($feature->depends_on, true) : [];
            $environmentsArray = $feature->environments ? json_decode($feature->environments, true) : [];
            $tagsArray = $feature->tags ? json_decode($feature->tags, true) : [];
            ?>
            <div class="card feature-card mb-3" 
                 data-name="<?= e($feature->name) ?>"
                 data-status="<?= e($feature->enabled ? 'enabled' : 'disabled') ?>"
                 data-percentage="<?= e((int)$feature->enabled_percentage) ?>"
                 data-targeting="<?= e(
        (!empty($rolesArray) ? 'role ' : '') .
        (!empty($usersArray) ? 'user ' : '') .
        (!empty($countriesArray) ? 'country ' : '') .
        (!empty($devicesArray) ? 'device ' : '') .
        (!empty($routesArray) ? 'route ' : '') .
        (($feature->min_age || $feature->max_age) ? 'age ' : '') .
        (($feature->enabled_from || $feature->enabled_until) ? 'time ' : '')) ?>"
                 data-environment="<?= e(implode(' ', $environmentsArray)) ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <code class="text-primary"><?= e($feature->name) ?></code>
                            <span class="badge ml-2 <?= $feature->enabled ? 'badge-success' : 'badge-secondary' ?>">
                                <i class="material-icons">
                                    <?= $feature->enabled ? 'toggle_on' : 'toggle_off' ?>
                                </i>
                                <?= $feature->enabled ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </h5>
                        <small class="text-muted"><?= e($feature->description) ?></small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" data-click="showMetrics" data-args="<?= e($feature->name) ?>">
                            <i class="material-icons">analytics</i>
                        </button>
                        <button class="btn btn-sm btn-outline-info" data-click="showHistory" data-args="<?= e($feature->name) ?>">
                            <i class="material-icons">history</i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" data-click="editFeature" data-args="<?= e($feature->name) ?>">
                            <i class="material-icons">edit</i>
                        </button>
                        <button class="btn btn-sm <?= $feature->enabled ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                data-click="toggleFeature" data-args="<?= e($feature->name) ?>">
                            <i class="material-icons">power_settings_new</i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">percent</i>
                                    <strong>درصد فعال‌سازی</strong>
                                </div>
                                <div class="percentage-display text-center">
                                    <?= e($feature->enabled_percentage) ?>%
                                </div>
                                <?php if ($feature->enabled_percentage < 100): ?>
                                    <small class="text-info d-block text-center">
                                        <i class="material-icons">science</i>
                                        A/B Testing فعال
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">group</i>
                                    <strong>نقش‌ها</strong>
                                </div>
                                <?php if (!empty($rolesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($rolesArray as $role): ?>
                                            <span class="tag">
                                                <?= e($role) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه نقش‌ها</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">location_on</i>
                                    <strong>کشورها</strong>
                                </div>
                                <?php if (!empty($countriesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($countriesArray as $country): ?>
                                            <span class="tag">
                                                <?= e($country) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه کشورها</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">devices</i>
                                    <strong>دستگاه‌ها</strong>
                                </div>
                                <?php if (!empty($devicesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($devicesArray as $device): ?>
                                            <span class="tag">
                                                <?= e($device) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه دستگاه‌ها</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Targeting Row -->
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">schedule</i>
                                    <strong>زمان‌بندی</strong>
                                </div>
                                <?php if ($feature->enabled_from || $feature->enabled_until): ?>
                                    <small class="d-block">
                                        از: <?= $feature->enabled_from ? e(date('Y/m/d H:i', strtotime($feature->enabled_from))) : 'همیشه' ?>
                                    </small>
                                    <small class="d-block">
                                        تا: <?= $feature->enabled_until ? e(date('Y/m/d H:i', strtotime($feature->enabled_until))) : 'همیشه' ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">همیشه فعال</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">person</i>
                                    <strong>سن</strong>
                                </div>
                                <?php if ($feature->min_age || $feature->max_age): ?>
                                    <small class="d-block">
                                        حداقل: <?= $feature->min_age ?? 'بدون محدودیت' ?>
                                    </small>
                                    <small class="d-block">
                                        حداکثر: <?= $feature->max_age ?? 'بدون محدودیت' ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">همه سنین</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">settings</i>
                                    <strong>متادیتا</strong>
                                </div>
                                <small class="text-muted">
                                    اولویت: <?= $feature->priority ?? 0 ?> |
                                    محیط: <?= implode(', ', $environmentsArray) ?: 'همه' ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="editFeatureModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">tune</i>
                    ویرایش پیشرفته فیچر: <code id="modalFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editFeatureForm">
  <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" id="editFeatureName">
                    
                    <div class="form-group">
                        <label>
                            <i class="material-icons">description</i>
                            توضیحات
                        </label>
                        <textarea id="editDescription" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="material-icons">percent</i>
                            درصد فعال‌سازی (A/B Testing)
                        </label>
                        <div class="row align-items-center">
                            <div class="col-9">
                                <input type="range" id="editPercentage" class="percentage-slider" 
                                       min="0" max="100" value="100" step="5">
                            </div>
                            <div class="col-3">
                                <input type="number" id="editPercentageValue" class="form-control" 
                                       min="0" max="100" value="100">
                            </div>
                        </div>
                        <small class="text-muted">
                            با مقدار کمتر از 100، فقط درصدی از کاربران دسترسی خواهند داشت (برای تست تدریجی)
                        </small>
                    </div>

                    <!-- Advanced Targeting Fields -->
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="material-icons">tune</i>
                                تنظیمات پیشرفته هدف‌گیری
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">group</i>
                                            نقش‌ها (Roles)
                                        </label>
                                        <div class="tag-input" id="rolesTagInput">
                                            <input type="text" id="roleInput" class="border-0 flex-grow-1" placeholder="نقش را تایپ کرده و Enter بزنید...">
                                        </div>
                                        <small class="text-muted">مثال: admin, moderator, premium_user</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">person</i>
                                            کاربران خاص (User IDs)
                                        </label>
                                        <div class="tag-input" id="usersTagInput">
                                            <input type="text" id="userInput" class="border-0 flex-grow-1" placeholder="User ID را تایپ کرده و Enter بزنید...">
                                        </div>
                                        <small class="text-muted">مثال: 123, 456, 789</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">location_on</i>
                                            کشورها (Countries)
                                        </label>
                                        <div class="tag-input" id="countriesTagInput">
                                            <input type="text" id="countryInput" class="border-0 flex-grow-1" placeholder="کد کشور را تایپ کرده و Enter بزنید...">
                                        </div>
                                        <small class="text-muted">مثال: IR, US, GB, DE</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">devices</i>
                                            دستگاه‌ها (Devices)
                                        </label>
                                        <div class="tag-input" id="devicesTagInput">
                                            <input type="text" id="deviceInput" class="border-0 flex-grow-1" placeholder="نوع دستگاه را تایپ کرده و Enter بزنید...">
                                        </div>
                                        <small class="text-muted">مثال: mobile, desktop, tablet</small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">route</i>
                                            مسیرها (Routes)
                                        </label>
                                        <div class="tag-input" id="routesTagInput">
                                            <input type="text" id="routeInput" class="border-0 flex-grow-1" placeholder="مسیر را تایپ کرده و Enter بزنید...">
                                        </div>
                                        <small class="text-muted">مثال: /admin/*, /api/v1/*</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">person</i>
                                            محدوده سنی (Age Range)
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="number" id="editMinAge" class="form-control" 
                                                       placeholder="حداقل سن" min="0" max="120">
                                            </div>
                                            <div class="col-6">
                                                <input type="number" id="editMaxAge" class="form-control" 
                                                       placeholder="حداکثر سن" min="0" max="120">
                                            </div>
                                        </div>
                                        <small class="text-muted">خالی بگذارید برای بدون محدودیت</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">schedule</i>
                                            زمان‌بندی (Scheduling)
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="datetime-local" id="editEnabledFrom" class="form-control">
                                                <small class="text-muted">از تاریخ</small>
                                            </div>
                                            <div class="col-6">
                                                <input type="datetime-local" id="editEnabledUntil" class="form-control">
                                                <small class="text-muted">تا تاریخ</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">settings</i>
                                            تنظیمات پیشرفته
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="number" id="editPriority" class="form-control" 
                                                       placeholder="اولویت" min="0" max="100" value="0">
                                            </div>
                                            <div class="col-6">
                                                <div class="tag-input" id="environmentsTagInput">
                                                    <input type="text" id="environmentInput" class="border-0 flex-grow-1" placeholder="محیط...">
                                                </div>
                                                <small class="text-muted">محیط‌ها: production, staging</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light">
                        <strong>
                            <i class="material-icons">preview</i>
                            پیش‌نمایش تنظیمات:
                        </strong>
                        <div id="settingsPreview" class="mt-2"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" data-click="saveFeature">
                    <i class="material-icons">save</i>
                    ذخیره تنظیمات
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Metrics Modal -->
<div class="modal fade" id="metricsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">analytics</i>
                    آمار و تحلیل: <code id="metricsFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="metricsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">در حال بارگذاری...</span>
                        </div>
                        <p class="mt-2">در حال دریافت آمار...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">history</i>
                    تاریخچه تغییرات: <code id="historyFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="historyContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">در حال بارگذاری...</span>
                        </div>
                        <p class="mt-2">در حال دریافت تاریخچه...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>




<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/adminfeaturesindex.css') . '">';
$content = ob_get_clean();
include view_path('layouts.admin');
?>

