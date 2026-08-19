<?php
$title = 'اجرای تسک';
$targetUrl = (string)($ad->site_url ?? $ad->target_url ?? $ad->link ?? '#');
$minDuration = (int)($ad->target_duration ?? 60);

ob_start();
?>
<?php
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userseo.css') . '">';
?>

<div id="seoExecuteRoot"
     class="execution-container"
     data-execution-id="<?= e((string)$execution->id) ?>"
     data-min-duration="<?= e((string)$minDuration) ?>"
     data-target-url="<?= e($targetUrl) ?>"
     data-complete-url="<?= e(url('/seo/' . (int)$execution->id . '/complete')) ?>"
     data-cancel-url="<?= e(url('/seo/' . (int)$execution->id . '/cancel')) ?>"
     data-return-url="<?= e(url('/tasks?type=seo')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">
    <div class="execution-header">
        <div class="task-info">
            <h5><?= e($ad->title) ?></h5>
            <p><?= e($ad->keyword ?? '') ?></p>
        </div>
        <div class="task-timer">
            <i class="material-icons">timer</i>
            <span id="timerDisplay">00:00</span>
        </div>
    </div>

    <div class="alert-box alert-info mb-15">
        <i class="material-icons">open_in_new</i>
        <div>
            <strong>روش اجرا:</strong>
            سایت هدف ممکن است داخل iframe باز نشود؛ بنابراین دکمه «باز کردن سایت هدف» را بزنید، تعامل طبیعی انجام دهید، سپس به همین صفحه برگردید و تکمیل را ثبت کنید.
        </div>
    </div>

    <div class="execution-stats">
        <div class="stat-item">
            <span>زمان: <strong id="durationText">0s</strong></span>
        </div>
        <div class="stat-item">
            <span>اسکرول/تعامل: <strong id="scrollText">0%</strong></span>
        </div>
        <div class="stat-item">
            <span>تعامل: <strong id="interactionText">0</strong></span>
        </div>
    </div>

    <div class="execution-actions" style="justify-content:flex-start;margin-bottom:12px;">
        <button id="openTargetBtn" type="button" class="btn btn-primary">
            <i class="material-icons">open_in_new</i> باز کردن سایت هدف
        </button>
        <a href="<?= e($targetUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary" dir="ltr">
            <?= e(parse_url($targetUrl, PHP_URL_HOST) ?: 'Target') ?>
        </a>
    </div>

    <div class="webview-container">
        <iframe id="taskFrame" src="<?= e($targetUrl) ?>" referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>

    <div class="execution-actions">
        <button id="btnComplete" class="btn btn-success btn-lg" disabled>تکمیل</button>
        <button id="btnCancel" class="btn btn-danger" type="button">لغو</button>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/tracker.js') . '"></script><script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userseoexecute.js') . '"></script>';
include view_path('layouts.user');
?>
