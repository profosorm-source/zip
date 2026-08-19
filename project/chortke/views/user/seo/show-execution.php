<?php
$title = $title ?? 'جزئیات اجرای SEO';
$status = (string)($execution->status ?? 'unknown');
$statusLabels = [
    'started' => 'در حال انجام',
    'completed' => 'تکمیل شده',
    'rejected' => 'رد شده',
    'fraud' => 'تقلب/نامعتبر',
    'cancelled' => 'لغو شده',
    'processing' => 'در حال پردازش',
];
$statusClass = match ($status) {
    'completed' => 'success',
    'rejected', 'fraud' => 'danger',
    'cancelled' => 'secondary',
    default => 'warning',
};
$breakdown = [];
if (!empty($execution->score_breakdown)) {
    $decoded = json_decode((string)$execution->score_breakdown, true);
    $breakdown = is_array($decoded) ? $decoded : [];
}
$engagement = [];
if (!empty($execution->engagement_data)) {
    $decoded = json_decode((string)$execution->engagement_data, true);
    $engagement = is_array($decoded) ? $decoded : [];
}
ob_start();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userseo.css') . '">';
?>

<div class="page-header">
    <h4><i class="material-icons">analytics</i> جزئیات اجرای SEO</h4>
    <a href="<?= url('/seo/history') ?>" class="btn btn-secondary"><i class="material-icons">arrow_forward</i> بازگشت به تاریخچه</a>
</div>

<div class="stats-row">
    <div class="stat-card stat-blue"><span class="stat-num"><?= number_format((float)($execution->final_score ?? 0), 1) ?></span><span class="stat-lbl">امتیاز نهایی</span></div>
    <div class="stat-card stat-green"><span class="stat-num"><?= number_format((float)($execution->payout_amount ?? 0)) ?></span><span class="stat-lbl">پاداش</span></div>
    <div class="stat-card stat-orange"><span class="stat-num"><?= number_format((int)($engagement['duration'] ?? 0)) ?>s</span><span class="stat-lbl">زمان فعال</span></div>
    <div class="stat-card stat-purple"><span class="stat-num"><?= number_format((int)($execution->fraud_score ?? 0)) ?></span><span class="stat-lbl">ریسک/تقلب</span></div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <h5 class="fw-bold mb-1"><?= e($ad->title ?? $execution->ad_title ?? 'کمپین SEO') ?></h5>
                <p class="text-muted mb-0">کلمه کلیدی: <?= e($ad->keyword ?? $execution->target_keyword ?? '—') ?></p>
            </div>
            <span class="badge bg-<?= $statusClass ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
        </div>
        <?php if (!empty($ad->site_url ?? null)): ?>
            <div class="mt-3 small" dir="ltr"><a href="<?= e($ad->site_url) ?>" target="_blank" rel="noopener noreferrer"><?= e($ad->site_url) ?></a></div>
        <?php endif; ?>
        <?php if (!empty($execution->rejection_reason)): ?><div class="alert alert-warning mt-3 mb-0"><strong>دلیل رد:</strong> <?= e($execution->rejection_reason) ?></div><?php endif; ?>
        <?php if (!empty($execution->cancel_reason)): ?><div class="alert alert-secondary mt-3 mb-0"><strong>دلیل لغو:</strong> <?= e($execution->cancel_reason) ?></div><?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
            <h5 class="fw-bold mb-3"><i class="material-icons align-middle">score</i> شکست امتیاز</h5>
            <table class="table table-sm">
                <tr><td>زمان</td><td class="text-end"><?= number_format((float)($execution->time_score ?? 0), 1) ?>/30</td></tr>
                <tr><td>اسکرول</td><td class="text-end"><?= number_format((float)($execution->scroll_score ?? 0), 1) ?>/25</td></tr>
                <tr><td>تعامل</td><td class="text-end"><?= number_format((float)($execution->interaction_score ?? 0), 1) ?>/25</td></tr>
                <tr><td>کیفیت</td><td class="text-end"><?= number_format((float)($execution->quality_score ?? 0), 1) ?>/20</td></tr>
                <tr class="fw-bold"><td>نهایی</td><td class="text-end"><?= number_format((float)($execution->final_score ?? 0), 1) ?>/100</td></tr>
            </table>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
            <h5 class="fw-bold mb-3"><i class="material-icons align-middle">fact_check</i> سیگنال‌های تعامل</h5>
            <table class="table table-sm">
                <tr><td>زمان</td><td class="text-end"><?= number_format((int)($engagement['duration'] ?? 0)) ?> ثانیه</td></tr>
                <tr><td>عمق اسکرول</td><td class="text-end"><?= number_format((float)($engagement['scroll_depth'] ?? 0), 1) ?>٪</td></tr>
                <tr><td>تعامل‌ها</td><td class="text-end"><?= number_format((int)($engagement['interactions'] ?? 0)) ?></td></tr>
                <tr><td>هدف باز شده</td><td class="text-end"><?= !empty($engagement['target_opened']) ? 'بله' : 'خیر' ?></td></tr>
                <tr><td>تغییر فوکوس</td><td class="text-end"><?= number_format((int)($engagement['focus_blur_count'] ?? 0)) ?></td></tr>
            </table>
        </div></div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
