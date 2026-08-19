<?php
$title = 'تنظیمات اعلان‌ها';
$hideSidebar = true;
$preferences = $preferences ?? (object)[];

$channels = [
    'in_app' => ['label' => 'داخل سایت', 'icon' => 'notifications', 'master' => 'in_app_enabled', 'default' => true,  'stat' => 'sup-stat--gold'],
    'email'  => ['label' => 'ایمیل',      'icon' => 'mail',          'master' => 'email_enabled',  'default' => true,  'stat' => 'sup-stat--blue'],
    'push'   => ['label' => 'Push',       'icon' => 'phone_iphone',  'master' => 'push_enabled',   'default' => true,  'stat' => 'sup-stat--green'],
    'sms'    => ['label' => 'SMS',        'icon' => 'sms',           'master' => 'sms_enabled',    'default' => false, 'stat' => 'sup-stat--red'],
];

$types = [
    'deposit'    => ['label' => 'واریز و پرداخت',      'icon' => 'south_west',    'desc' => 'شارژ کیف پول، پرداخت‌ها و واریزها'],
    'withdrawal' => ['label' => 'برداشت',              'icon' => 'north_east',    'desc' => 'درخواست برداشت و وضعیت تسویه'],
    'task'       => ['label' => 'تسک‌ها',              'icon' => 'assignment',    'desc' => 'تسک‌ها، اجراها و پاداش‌ها'],
    'investment' => ['label' => 'سرمایه‌گذاری',        'icon' => 'trending_up',   'desc' => 'پلن‌ها، سود/زیان و برداشت سرمایه‌گذاری'],
    'lottery'    => ['label' => 'قرعه‌کشی',            'icon' => 'redeem',        'desc' => 'دوره‌ها، شانس‌ها و نتایج'],
    'referral'   => ['label' => 'معرفی و کمیسیون',     'icon' => 'group_add',     'desc' => 'دعوت دوستان و کمیسیون‌ها'],
    'security'   => ['label' => 'هشدارهای امنیتی',     'icon' => 'shield',        'desc' => 'ورود جدید، فعالیت مشکوک و ریسک'],
    'kyc'        => ['label' => 'احراز هویت',          'icon' => 'badge',         'desc' => 'ثبت، بررسی، تأیید یا رد KYC'],
    'system'     => ['label' => 'اطلاعیه‌های سیستمی',  'icon' => 'settings',      'desc' => 'پیام‌های عمومی و سیستمی'],
    'marketing'  => ['label' => 'تبلیغاتی / مارکتینگ', 'icon' => 'campaign',      'desc' => 'پیشنهادها و پیام‌های تبلیغاتی'],
];

$defaultFor = static function (string $channel, string $type): bool {
    if ($type === 'marketing') return false;
    if ($channel === 'sms') return in_array($type, ['security', 'withdrawal', 'kyc'], true);
    return true;
};

$dndEnabled = (bool)($preferences->dnd_enabled ?? false);
$dndStart = substr((string)($preferences->dnd_start ?? '23:00:00'), 0, 5);
$dndEnd = substr((string)($preferences->dnd_end ?? '07:00:00'), 0, 5);

ob_start();
?>

<div id="supportNotificationPrefsRoot"
     class="sup-wrap"
     data-update-url="<?= e(url('/settings/notifications/update')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">

    <section class="sup-hero">
        <div class="sup-hero__main">
            <div class="sup-hero__icon"><i class="material-icons">tune</i></div>
            <div>
                <div class="sup-hero__eyebrow">Notification Settings</div>
                <h1 class="sup-hero__title">تنظیمات اعلان‌ها</h1>
                <p class="sup-hero__sub">تنظیم دقیق دریافت اعلان‌ها بر اساس کانال و نوع پیام. اعلان‌ها مستقل از پشتیبانی و از آیکن زنگوله Navbar در دسترس هستند.</p>
            </div>
        </div>
        <div class="sup-hero__side">
            <a href="<?= url('/notifications') ?>" class="sup-btn sup-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به اعلان‌ها</a>
            <button type="submit" form="prefsForm" class="sup-btn sup-btn-primary"><i class="material-icons">save</i> ذخیره تنظیمات</button>
        </div>
    </section>

    <main class="sup-hub-main">
        <form id="prefsForm">
            <?= csrf_field() ?>

            <section class="sup-stats">
                <?php foreach ($channels as $channel => $info): ?>
                    <?php $enabled = (bool)($preferences->{$info['master']} ?? $info['default']); ?>
                    <div class="sup-stat <?= e($info['stat']) ?>">
                        <div class="sup-stat__icon"><i class="material-icons"><?= e($info['icon']) ?></i></div>
                        <div>
                            <span class="sup-stat__lbl"><?= e($info['label']) ?></span>
                            <span class="sup-stat__val"><?= $enabled ? 'فعال' : 'غیرفعال' ?></span>
                            <span class="sup-stat__unit">کل کانال <?= e($info['label']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="sup-section">
                <div class="sup-section__header">
                    <div class="sup-section__title"><i class="material-icons">power_settings_new</i> فعال‌سازی کانال‌ها</div>
                    <span class="sup-badge sup-badge--info">Master Switches</span>
                </div>
                <div class="sup-section__body">
                    <div class="sup-spoke-grid" style="margin-bottom:0;">
                        <?php foreach ($channels as $channel => $info): ?>
                            <?php $enabled = (bool)($preferences->{$info['master']} ?? $info['default']); ?>
                            <label class="sup-spoke-card" style="cursor:pointer;">
                                <span class="sup-spoke-card__icon"><i class="material-icons"><?= e($info['icon']) ?></i></span>
                                <span class="sup-spoke-card__body"><strong><?= e($info['label']) ?></strong><small>روشن/خاموش کردن کل کانال</small></span>
                                <input type="checkbox" class="notif-master-toggle" name="<?= e($info['master']) ?>" data-channel="<?= e($channel) ?>" <?= $enabled ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:#F0B90B;">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="sup-section" style="margin-top:16px;">
                <div class="sup-section__header">
                    <div class="sup-section__title"><i class="material-icons">tune</i> تنظیمات ریز اعلان‌ها</div>
                    <span class="sup-badge sup-badge--warning">Channel × Type</span>
                </div>
                <div class="sup-table-wrap">
                    <table class="sup-table">
                        <thead>
                            <tr>
                                <th>نوع اعلان</th>
                                <?php foreach ($channels as $channel => $info): ?>
                                    <th><?= e($info['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $type => $typeInfo): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <span class="sup-spoke-card__icon" style="width:34px;height:34px;border-radius:10px;"><i class="material-icons" style="font-size:17px;"><?= e($typeInfo['icon']) ?></i></span>
                                            <div>
                                                <strong style="color:var(--sup-text);"><?= e($typeInfo['label']) ?></strong><br>
                                                <small style="color:var(--sup-text-faint);"><?= e($typeInfo['desc']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <?php foreach ($channels as $channel => $info): ?>
                                        <?php
                                            $field = $channel . '_' . $type;
                                            $checked = (bool)($preferences->{$field} ?? $defaultFor($channel, $type));
                                        ?>
                                        <td>
                                            <label class="sup-badge <?= $checked ? 'sup-badge--success' : 'sup-badge--muted' ?>" style="cursor:pointer;">
                                                <input type="checkbox" class="notif-type-toggle" data-channel="<?= e($channel) ?>" name="<?= e($field) ?>" <?= $checked ? 'checked' : '' ?>>
                                                <?= $checked ? 'فعال' : 'خاموش' ?>
                                            </label>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sup-section" style="margin-top:16px;">
                <div class="sup-section__header">
                    <div class="sup-section__title"><i class="material-icons">bedtime</i> مزاحم نشوید</div>
                    <label class="sup-badge <?= $dndEnabled ? 'sup-badge--success' : 'sup-badge--muted' ?>" style="cursor:pointer;">
                        <input type="checkbox" id="dnd_enabled" name="dnd_enabled" <?= $dndEnabled ? 'checked' : '' ?>> فعال
                    </label>
                </div>
                <div class="sup-section__body">
                    <p style="color:var(--sup-text-dim);font-size:12.5px;line-height:1.8;margin-top:0;">
                        در بازه مزاحم نشوید، اعلان‌های غیر فوری به بعد از پایان بازه موکول می‌شوند.
                    </p>
                    <div class="sup-form-row">
                        <div class="sup-form-group"><label>از ساعت</label><input type="time" id="dnd_start" name="dnd_start" class="sup-input" value="<?= e($dndStart) ?>"></div>
                        <div class="sup-form-group"><label>تا ساعت</label><input type="time" id="dnd_end" name="dnd_end" class="sup-input" value="<?= e($dndEnd) ?>"></div>
                    </div>
                </div>
            </section>

            <div class="sup-actions">
                <button type="submit" class="sup-btn sup-btn-primary"><i class="material-icons">save</i> ذخیره تنظیمات</button>
                <a href="<?= url('/notifications') ?>" class="sup-btn sup-btn-secondary">انصراف</a>
            </div>
        </form>
    </main>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usersupport.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usernotificationspreferences.js') . '"></script>';
include view_path('layouts.user');
?>
