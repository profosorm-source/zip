<?php
$title = 'جلسات فعال';
$hideSidebar = true;
$sessions = $sessions ?? [];
$currentSessionId = $currentSessionId ?? session_id();

ob_start();
?>

<div id="accountSessionsRoot" class="acc-wrap" data-terminate-base="<?= e(url('/sessions/terminate')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">devices</i></div>
            <div>
                <div class="acc-hero__eyebrow">Active Sessions</div>
                <h1 class="acc-hero__title">جلسات فعال</h1>
                <p class="acc-hero__sub">تمام دستگاه‌هایی که به حساب شما وارد شده‌اند را بررسی و نشست‌های ناشناس را خارج کنید.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/profile') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز حساب</a>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'sessions'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <div class="acc-alert acc-alert-info"><i class="material-icons">info</i><div>اگر دستگاه یا مرورگری را نمی‌شناسید، همان نشست را از حساب خارج کنید و رمز عبور خود را تغییر دهید.</div></div>

            <section class="acc-section">
                <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">devices</i> دستگاه‌های واردشده</div><span class="acc-badge acc-badge--info"><?= number_format(count($sessions)) ?> نشست</span></div>
                <div class="acc-section__body">
                    <?php if (empty($sessions)): ?>
                        <div class="acc-empty"><i class="material-icons">devices</i><h3>هیچ نشست فعالی یافت نشد</h3><p>در حال حاضر دستگاه دیگری برای نمایش وجود ندارد.</p></div>
                    <?php else: ?>
                        <div class="acc-session-grid">
                            <?php foreach ($sessions as $session): ?>
                                <?php
                                $isCurrent = ($session->session_id ?? '') === $currentSessionId;
                                $icon = match($session->device_type ?? 'desktop') { 'mobile' => 'smartphone', 'tablet' => 'tablet', default => 'computer' };
                                ?>
                                <article class="acc-session-card <?= $isCurrent ? 'current' : '' ?>">
                                    <div class="acc-session-head">
                                        <div class="acc-session-device">
                                            <i class="material-icons"><?= e($icon) ?></i>
                                            <div>
                                                <strong><?= e($session->browser ?? 'مرورگر') ?> - <?= e($session->os ?? 'سیستم') ?></strong><br>
                                                <small><?= e($session->device_type ?? 'desktop') ?></small>
                                            </div>
                                        </div>
                                        <?php if ($isCurrent): ?><span class="acc-badge acc-badge--success">نشست فعلی</span><?php endif; ?>
                                    </div>
                                    <div class="acc-session-meta">
                                        <span><i class="material-icons" style="font-size:15px;vertical-align:middle;">place</i> IP: <code><?= e($session->ip_address ?? '—') ?></code></span>
                                        <span><i class="material-icons" style="font-size:15px;vertical-align:middle;">schedule</i> آخرین فعالیت: <?= to_jalali(date('Y/m/d H:i', strtotime($session->last_activity ?? 'now'))) ?></span>
                                    </div>
                                    <?php if (!$isCurrent): ?>
                                        <div class="acc-actions"><button type="button" class="acc-btn acc-btn-danger btn-terminate" data-id="<?= e((string)($session->id ?? 0)) ?>"><i class="material-icons">logout</i> خروج از این دستگاه</button></div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="acc-alert acc-alert-warning" style="margin-top:16px;"><i class="material-icons">shield</i><div><strong>نکات امنیتی:</strong> نشست ناشناس را خارج کنید، رمز عبور را تغییر دهید و در صورت نیاز احراز دو مرحله‌ای را فعال کنید.</div></div>
        </main>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usersessionsindex.js') . '"></script>';
include view_path('layouts.user');
?>
