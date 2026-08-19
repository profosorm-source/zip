<?php
$title = 'قرعه‌کشی';
ob_start();
?>

<div class="content-header">
    <h4><span class="material-icons icon-lg">casino</span> قرعه‌کشی روزانه</h4>
</div>

<?php if ($activeRound): ?>
<!-- دوره فعال -->
<div class="card lottery-hero">
    <div class="card-body text-center">
        <h3><?= e($activeRound->title) ?></h3>
        <p class="text-muted">
            <?= e(to_jalali($activeRound->start_date ?? '')) ?> تا <?= e(to_jalali($activeRound->end_date ?? '')) ?>
        </p>
        <div class="prize-badge">
            <span class="material-icons">emoji_events</span>
            <span>جایزه: <strong><?= number_format($activeRound->prize_amount) ?></strong>
                <?= ($activeRound->currency ?? 'irt') === 'usdt' ? 'تتر' : 'تومان' ?></span>
        </div>

        <?php if (!$participation): ?>
        <!-- دکمه شرکت -->
        <div class="mt-4">
            <?php $entryFee = (float)($activeRound->entry_fee ?? $activeRound->ticket_price ?? 0); ?>
            <?php if ($entryFee > 0): ?>
            <p class="text-warning">هزینه ورود: <?= number_format($entryFee) ?>
                <?= ($activeRound->currency ?? 'irt') === 'usdt' ? 'تتر' : 'تومان' ?></p>
            <?php else: ?>
            <p class="text-success">رایگان!</p>
            <?php endif; ?>
            <button class="btn btn-primary btn-lg" data-action="join-lottery" data-round-id="<?= e($activeRound->id) ?>">
                <span class="material-icons">add_circle</span> شرکت در قرعه‌کشی
            </button>

            <!-- 🛡️ OPT-IN REWARD VIDEO BUTTON (افزایش درصد شانس برنده شدن) -->
            <?php $lotteryChanceBoost = (int)config('video_rewards.lottery_chance_boost', setting('lottery_chance_boost', 25)); ?>
            <button type="button" class="btn btn-success btn-lg ms-2" onclick="startLotteryRewardedVideo('tapsell', 15)">
                <span class="material-icons align-middle">auto_awesome</span> افزایش <?= $lotteryChanceBoost ?> درصدی شانس برنده شدن با ویدیو
            </button>

            <!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S قرعه‌کشی -->
            <div class="reward-modal-wrap" id="lotteryRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
                <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="lotteryRewardModalBox">
                    <div style="width: 80px; height: 80px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 25px;" id="lotteryRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="lotteryRewardModalIconTxt">hourglass_empty</span></div>
                    <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="lotteryRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
                    <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="lotteryRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#10b981;" id="lotteryRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
                    <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="lotteryRewardCloseBtn" onclick="closeLotteryRewardModal()">بستن و اعمال افزایش شانس</button>
                </div>
            </div>

            <script nonce="<?= e(csp_nonce()) ?>">
            function startLotteryRewardedVideo(network, duration) {
                const modal = document.getElementById('lotteryRewardModalWrap');
                const box = document.getElementById('lotteryRewardModalBox');
                const title = document.getElementById('lotteryRewardModalTitle');
                const body = document.getElementById('lotteryRewardModalBody');
                const icon = document.getElementById('lotteryRewardModalIconTxt');
                const iconBox = document.getElementById('lotteryRewardModalIcon');
                const countTxt = document.getElementById('lotteryRewardCountdown');
                const closeBtn = document.getElementById('lotteryRewardCloseBtn');

                modal.style.opacity = '1';
                modal.style.pointerEvents = 'auto';
                box.style.transform = 'scale(1)';
                title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
                iconBox.style.borderColor = '#10b981';
                iconBox.style.background = 'rgba(16,185,129,0.2)';
                iconBox.style.color = '#10b981';
                icon.innerText = 'hourglass_empty';
                closeBtn.style.display = 'none';

                let timer = duration;
                countTxt.innerText = timer;
                
                const interval = setInterval(() => {
                    timer--;
                    countTxt.innerText = timer;
                    if (timer <= 0) {
                        clearInterval(interval);
                        iconBox.style.borderColor = '#10b981';
                        iconBox.style.background = 'rgba(16,185,129,0.2)';
                        iconBox.style.color = '#10b981';
                        icon.innerText = 'verified_user';
                        title.innerText = 'نمایش ویدیو با موفقیت به اتمام رسید!';
                        body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ شانس برنده شدن شما در این دوره <?= $lotteryChanceBoost ?>٪ افزایش یافت!';
                        closeBtn.style.display = 'block';
                    }
                }, 1000);
            }
            function closeLotteryRewardModal() {
                document.getElementById('lotteryRewardModalWrap').style.opacity = '0';
                document.getElementById('lotteryRewardModalWrap').style.pointerEvents = 'none';
                alert('افزایش <?= $lotteryChanceBoost ?> درصدی شانس برنده شدن با موفقیت اعمال شد.');
            }
            </script>
        </div>
        <?php else: ?>
        <!-- اطلاعات شرکت‌کننده -->
        <div class="mt-4">
            <div class="code-display">
                <span class="code-label">کد/شماره بلیت شما:</span>
                <div class="code-digits">
                    <?php $ticketCode = (string)($participation->ticket_number ?? ('LT-' . ($participation->id ?? ''))); ?>
                    <?php foreach (str_split($ticketCode) as $digit): ?>
                    <span class="digit"><?= e($digit) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($participation && $todayNumbers): ?>
<!-- رأی‌گیری امروز -->
<div class="card mt-4">
    <div class="card-header">
        <h5><span class="material-icons">how_to_vote</span> رأی‌گیری امروز</h5>
        <?php if ($todayNumbers->selected_number !== null): ?>
        <span class="badge badge-success">عدد منتخب: <?= e($todayNumbers->selected_number) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body text-center">
        <p>یکی از اعداد زیر را انتخاب کنید:</p>
        <div class="vote-numbers">
            <?php
            $nums = [(int)($todayNumbers->number1 ?? $todayNumbers->number_1 ?? 0), (int)($todayNumbers->number2 ?? $todayNumbers->number_2 ?? 0), (int)($todayNumbers->number3 ?? $todayNumbers->number_3 ?? 0)];
            foreach ($nums as $n):
                $isVoted = $userVote && (int)$userVote->voted_number === $n;
            ?>
            <button class="vote-btn <?= $isVoted ? 'voted' : '' ?>"
                    <?= $userVote ? 'disabled' : '' ?>
                    data-action="cast-vote" data-round-id="<?= e($activeRound->id) ?>" data-number="<?= e($n) ?>">
                <?= e($n) ?>
                <?php if ($isVoted): ?><span class="material-icons icon-sm">check</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php if ($userVote): ?>
        <p class="mt-3 text-success"><span class="material-icons icon-sm">check_circle</span> رأی شما ثبت شد.</p>
        <?php else: ?>
        <p class="mt-3 text-muted">هر کاربر فقط یک رأی در روز دارد.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- آمار عمومی -->
<?php if ($distribution): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><span class="material-icons">bar_chart</span> وضعیت کلی شرکت‌کنندگان</h5>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card stat-green">
                <div class="stat-info">
                    <span class="stat-label">شانس زیاد</span>
                    <span class="stat-value"><?= e($distribution['high']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-info">
                    <span class="stat-label">شانس متوسط</span>
                    <span class="stat-value"><?= e($distribution['medium']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-red">
                <div class="stat-info">
                    <span class="stat-label">شانس کم</span>
                    <span class="stat-value"><?= e($distribution['low']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-info">
                    <span class="stat-label">کل شرکت‌کنندگان</span>
                    <span class="stat-value"><?= e($distribution['total']) ?> نفر</span>
                </div>
            </div>
        </div>
        <p class="text-muted mt-2 text-12">
            <span class="material-icons icon-xs">info</span>
            رتبه فردی و مقدار شانس واقعی نمایش داده نمی‌شود.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه اعداد روزانه -->
<?php if (!empty($dailyHistory)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><span class="material-icons">history</span> تاریخچه اعداد</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>تاریخ</th><th>اعداد روز</th><th>عدد منتخب</th><th>Seed Hash</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyHistory as $d): ?>
                    <tr>
                        <td><?= e(to_jalali($d->date)) ?></td>
                        <td><?= e($d->number1 ?? $d->number_1 ?? '-') ?> - <?= e($d->number2 ?? $d->number_2 ?? '-') ?> - <?= e($d->number3 ?? $d->number_3 ?? '-') ?></td>
                        <td>
                            <?php if ($d->selected_number !== null): ?>
                            <span class="badge badge-primary"><?= e($d->selected_number) ?></span>
                            <?php else: ?>
                            <span class="text-muted">منتظر...</span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr" class="text-11 text-ellipsis">
                            <?= e($d->seed_hash ?? '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- بدون دوره فعال -->
<div class="card">
    <div class="card-body text-center p-5">
        <span class="material-icons icon-xl text-muted">casino</span>
        <h5 class="mt-3">در حال حاضر قرعه‌کشی فعالی وجود ندارد</h5>
        <p class="text-muted">منتظر اعلام دوره جدید باشید!</p>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه برندگان -->
<?php if (!empty($completedRounds)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><span class="material-icons">emoji_events</span> برندگان قبلی</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>عنوان</th><th>جایزه</th><th>برنده</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($completedRounds as $cr): ?>
                    <tr>
                        <td><?= e($cr->title) ?></td>
                        <td><?= number_format((float)$cr->prize_amount) ?> <?= ($cr->currency ?? 'irt') === 'usdt' ? 'تتر' : 'تومان' ?></td>
                        <td><?= e($cr->winner_name ?? 'نامشخص') ?></td>
                        <td><?= e(to_jalali($cr->end_date ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- شفافیت سیستم -->
<div class="card mt-4">
    <div class="card-header">
        <h5><span class="material-icons">verified_user</span> شفافیت سیستم قرعه‌کشی</h5>
    </div>
    <div class="card-body">
        <div class="bg-light p-3 rounded text-13 text-muted line-height-22">
            <?= nl2br(e($transparencyText)) ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userlottery.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userlotteryindex.js') . '" data-join-url="' . e(url('/lottery/join')) . '" data-vote-url="' . e(url('/lottery/vote')) . '"></script>';
include view_path('layouts.user');
?>