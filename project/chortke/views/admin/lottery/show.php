<?php $title = 'جزئیات قرعه‌کشی #' . $round->id;  ob_start(); ?>
<div id="lotteryRoot" data-base="<?= url('/admin/lottery') ?>" data-store="<?= url('/admin/lottery/store') ?>"></div>


<div class="content-header">
    <h4><i class="material-icons">casino</i> <?= e($round->title) ?></h4>
    <a href="<?= url('/admin/lottery') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons">arrow_back</i> بازگشت</a>
</div>

<?php
$sl = [
    'active' => ['فعال', 'badge-success'],
    'voting' => ['رأی‌گیری', 'badge-info'],
    'completed' => ['تکمیل شده', 'badge-primary'],
    'cancelled' => ['لغو شده', 'badge-danger'],
][$round->status] ?? ['؟', 'badge-secondary'];
?>

<div class="card">
    <div class="card-header">
        <h5>اطلاعات دوره</h5>
        <span class="badge <?= e($sl[1]) ?>"><?= e($sl[0]) ?></span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">جایزه</span><span class="detail-value"><?= number_format($round->prize_amount) ?> <?= $round->currency === 'usdt' ? 'تتر' : 'تومان' ?></span></div>
            <div class="detail-item"><span class="detail-label">هزینه ورود</span><span class="detail-value"><?= number_format($round->entry_fee) ?></span></div>
            <div class="detail-item"><span class="detail-label">شرکت‌کنندگان</span><span class="detail-value"><?= e($participantCount) ?></span></div>
            <div class="detail-item"><span class="detail-label">شروع</span><span class="detail-value"><?= e(to_jalali($round->start_date ?? '')) ?></span></div>
            <div class="detail-item"><span class="detail-label">پایان</span><span class="detail-value"><?= e(to_jalali($round->end_date ?? '')) ?></span></div>
            <?php if ($round->winner_name): ?>
            <div class="detail-item"><span class="detail-label">🏆 برنده</span><span class="detail-value"><?= e($round->winner_name) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer">
        <?php if (\in_array($round->status, ['active', 'voting'])): ?>
        <button class="btn btn-info btn-sm" data-click="generateNumbers" data-args="<?= e($round->id) ?>">
            <i class="material-icons">auto_awesome</i> تولید اعداد امروز
        </button>
        <button class="btn btn-success btn-sm" data-click="selectWinner" data-args="<?= e($round->id) ?>">
            <i class="material-icons">emoji_events</i> انتخاب برنده
        </button>
        <button class="btn btn-danger btn-sm" data-click="cancelRound" data-args="<?= e($round->id) ?>">
            <i class="material-icons">cancel</i> لغو دوره
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- توزیع شانس -->
<div class="card mt-4">
    <div class="card-header"><h5>توزیع شانس</h5></div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card stat-green"><div class="stat-info"><span class="stat-label">شانس زیاد (≥80)</span><span class="stat-value"><?= e($distribution['high']) ?></span></div></div>
            <div class="stat-card stat-orange"><div class="stat-info"><span class="stat-label">شانس متوسط (40-79)</span><span class="stat-value"><?= e($distribution['medium']) ?></span></div></div>
            <div class="stat-card stat-red"><div class="stat-info"><span class="stat-label">شانس کم (<40)</span><span class="stat-value"><?= e($distribution['low']) ?></span></div></div>
        </div>
    </div>
</div>

<!-- اعداد روزانه -->
<div class="card mt-4">
    <div class="card-header"><h5>اعداد روزانه</h5></div>
    <div class="card-body">
        <?php if (empty($dailyNumbers)): ?>
        <p class="text-muted text-center">هنوز اعدادی تولید نشده.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>تاریخ</th><th>اعداد</th><th>منتخب</th><th>نوع بررسی</th><th>نهایی</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($dailyNumbers as $d): ?>
                    <tr>
                        <td><?= e(to_jalali($d->date)) ?></td>
                        <td><?= e($d->number_1) ?> - <?= e($d->number_2) ?> - <?= e($d->number_3) ?></td>
                        <td><?= $d->selected_number !== null ? "<span class='badge badge-primary'>{$d->selected_number}</span>" : '-' ?></td>
                        <td><span class="badge badge-secondary"><?= e($d->match_type ?? '-') ?></span></td>
                        <td><?= $d->is_finalized ? '✅' : '⏳' ?></td>
                        <td>
                            <?php if (!$d->is_finalized): ?>
                            <button class="btn btn-xs btn-warning" data-click="finalizeDaily" data-args="<?= e($d->id) ?>">نهایی‌سازی</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- شرکت‌کنندگان -->
<div class="card mt-4">
    <div class="card-header"><h5>شرکت‌کنندگان (<?= e($participantCount) ?>)</h5></div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
        <p class="text-muted text-center">شرکت‌کننده‌ای نیست.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>کاربر</th><th>کد</th><th>امتیاز شانس</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                    <tr class="<?= $p->status === 'winner' ? 'table-success' : '' ?>">
                        <td><?= e($p->user_name ?? '') ?></td>
                        <td dir="ltr"><?= e($p->code) ?></td>
                        <td><strong><?= number_format($p->chance_score, 2) ?></strong></td>
                        <td>
                            <?php if ($p->status === 'winner'): ?>
                            <span class="badge badge-success">🏆 برنده</span>
                            <?php else: ?>
                            <span class="badge badge-secondary"><?= e($p->status) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(to_jalali($p->created_at ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>



<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
