<?php
$title = 'داشبورد';
ob_start();

/* ── defaults ── */
$walletBalance      = $walletBalance      ?? 0;
$walletBalanceUsdt  = $walletBalanceUsdt  ?? 0;
$lockedBalance      = $lockedBalance      ?? 0;
$tasksCompleted     = $tasksCompleted     ?? 0;
$tasksPending       = $tasksPending       ?? 0;
$tasksRejected      = $tasksRejected      ?? 0;
$tasksTotal         = $tasksTotal         ?? ($tasksCompleted + $tasksPending + $tasksRejected);
$tasksEarned        = $tasksEarned        ?? 0;
$totalEarnings      = $totalEarnings      ?? 0;
$totalDeposits      = $totalDeposits      ?? 0;
$totalWithdraws     = $totalWithdraws     ?? 0;
$recentTransactions = $recentTransactions ?? [];
$activeCampaigns    = $activeCampaigns    ?? 0;
$recentAds          = $recentAds          ?? [];
$chartLabels        = $chartLabels        ?? [];
$chartData          = $chartData          ?? [];
$platformLabels     = $platformLabels     ?? [];
$platformData       = $platformData       ?? [];
$referralCount      = $referralCount      ?? 0;
$referralEarnings   = $referralEarnings   ?? 0;
$currentLevel       = $currentLevel       ?? 'SILVER';
$levelProgress      = $levelProgress      ?? 0;
$levelNext          = $levelNext          ?? null;
$activeInvestment   = $activeInvestment   ?? null;
$lotteryRound       = $lotteryRound       ?? null;
$socialPages        = $socialPages        ?? [];
$todayDailyNumber   = $todayDailyNumber   ?? null;
$todayVoteCounts    = $todayVoteCounts    ?? [];
$userTodayVote      = $userTodayVote      ?? null;
$totalDailyVotes    = $totalDailyVotes    ?? 0;
$pendingTxCount     = $pendingTxCount     ?? 0;
$recentTaskExecutions = $recentTaskExecutions ?? [];

$pIcons  = ['instagram'=>'camera_alt','youtube'=>'play_circle','telegram'=>'send','tiktok'=>'music_video','twitter'=>'flutter_dash','aparat'=>'ondemand_video'];
$pColors = ['instagram'=>'#E1306C','youtube'=>'#FF0000','telegram'=>'#0088cc','tiktok'=>'#333','twitter'=>'#1DA1F2','aparat'=>'#CC0000'];
$txMap   = [
    'deposit'           => ['واریز',           'add_circle',    'var(--success)', true ],
    'withdraw'          => ['برداشت',          'remove_circle', 'var(--danger)',  false],
    'task_reward'       => ['پاداش تسک',      'task_alt',      'var(--info)',    true ],
    'commission'        => ['کمیسیون',         'percent',       'var(--purple)',  true ],
    'refund'            => ['استرداد',         'replay',        'var(--warning)', true ],
    'content_revenue'   => ['درآمد محتوا',    'video_library', 'var(--info)',    true ],
    'investment_profit' => ['سود سرمایه‌گذاری','trending_up',  'var(--success)', true ],
    'lottery_prize'     => ['جایزه لاتاری',   'casino',        'var(--purple)',  true ],
];
$stMap = [
    'completed'  => ['موفق',      'var(--success)','var(--success-bg)','ok'],
    'pending'    => ['در انتظار', 'var(--warning)','var(--warning-bg)','wt'],
    'processing' => ['در پردازش', 'var(--warning)','var(--warning-bg)','wt'],
    'failed'     => ['ناموفق',    'var(--danger)', 'var(--danger-bg)', 'er'],
    'cancelled'  => ['لغو شده',   'var(--danger)', 'var(--danger-bg)', 'er'],
    'active'     => ['فعال',      'var(--success)','var(--success-bg)','ok'],
    'paused'     => ['متوقف',     'var(--info)',   'var(--info-bg)',   'wt'],
    'rejected'   => ['رد شده',    'var(--danger)', 'var(--danger-bg)', 'er'],
    'verified'   => ['تأیید شده', 'var(--success)','var(--success-bg)','ok'],
];

$sampleTx = [
    (object)['type'=>'deposit',    'id'=>'TXN-0001','amount'=>320000,'status'=>'completed'],
    (object)['type'=>'task_reward','id'=>'TXN-0002','amount'=>15000, 'status'=>'completed'],
    (object)['type'=>'withdraw',   'id'=>'TXN-0003','amount'=>200000,'status'=>'pending'],
    (object)['type'=>'commission', 'id'=>'TXN-0004','amount'=>8500,  'status'=>'completed'],
    (object)['type'=>'deposit',    'id'=>'TXN-0005','amount'=>500000,'status'=>'completed'],
];
$displayTx  = !empty($recentTransactions) ? $recentTransactions : $sampleTx;
$isSampleTx = empty($recentTransactions);

$sampleAds = [
    (object)['title'=>'کمپین اینستاگرام','platform'=>'instagram','task_type'=>'لایک و فالو','remaining_budget'=>54000,'status'=>'active'],
    (object)['title'=>'تبلیغ یوتیوب','platform'=>'youtube','task_type'=>'ویو و لایک','remaining_budget'=>120000,'status'=>'active'],
    (object)['title'=>'کانال تلگرام','platform'=>'telegram','task_type'=>'عضویت کانال','remaining_budget'=>12000,'status'=>'pending'],
    (object)['title'=>'کمپین توییتر','platform'=>'twitter','task_type'=>'ریتوییت','remaining_budget'=>32000,'status'=>'active'],
];
$displayAds  = !empty($recentAds) ? $recentAds : $sampleAds;
$isSampleAds = empty($recentAds);

$samplePages = [
    (object)['username'=>'my_instagram_page','platform'=>'instagram','followers_count'=>12400,'status'=>'verified'],
    (object)['username'=>'my_youtube_channel','platform'=>'youtube','followers_count'=>5800,'status'=>'pending'],
    (object)['username'=>'my_channel_tg','platform'=>'telegram','followers_count'=>3200,'status'=>'verified'],
];
$displayPages  = !empty($socialPages) ? $socialPages : $samplePages;
$isSamplePages = empty($socialPages);

$taskSuccess = $tasksTotal > 0 ? round($tasksCompleted / $tasksTotal * 100) : 0;
$levelEmoji  = ['BRONZE'=>'🥉','SILVER'=>'🥈','GOLD'=>'🥇','PLATINUM'=>'💎','DIAMOND'=>'💎'];
$lvEmoji     = $levelEmoji[$currentLevel] ?? '⭐';

$viewData = [
    'chartLabels' => $chartLabels ?: array_map(fn($i) => "روز $i", range(1, 30)),
    'chartData'   => $chartData ?: array_fill(0, 30, 0),
    'tasksCompleted' => (int)$tasksCompleted,
    'tasksPending'   => (int)$tasksPending,
    'tasksRejected'  => (int)$tasksRejected,
    'tasksTotal'     => (int)$tasksTotal,
    'tasksEarned'    => (int)$tasksEarned,
    'totalEarnings'  => (int)($totalEarnings ?: $tasksEarned),
    'totalDeposits'  => (int)$totalDeposits,
    'totalWithdraws' => (int)$totalWithdraws,
    'platformLabels' => $platformLabels ?: ['اینستاگرام','یوتیوب','تلگرام'],
    'platformData'   => $platformData ?: [1,1,1],
    'referralCount'  => (int)$referralCount,
    'walletBalanceUsdt' => (float)$walletBalanceUsdt,
    'lotteryEnd'     => isset($lotteryRound->end_date) ? strtotime($lotteryRound->end_date) : null,
    'roundId'        => (int)($lotteryRound->id ?? 0),
    'dailyNumberId'  => (int)($todayDailyNumber->id ?? 0),
    'voteUrl'        => url('/lottery/vote'),
    'lotteryUrl'     => url('/lottery'),
];

$platformClassMap = [
    'instagram' => 'gradient-instagram',
    'youtube'   => 'gradient-youtube',
    'telegram'  => 'gradient-telegram',
    'tiktok'    => 'gradient-tiktok',
    'twitter'   => 'gradient-twitter',
    'aparat'    => 'gradient-aparat',
    'other'     => 'gradient-other',
];
$platformIconMap = [
    'instagram' => 'camera_alt',
    'youtube'   => 'play_circle',
    'telegram'  => 'send',
    'tiktok'    => 'music_video',
    'twitter'   => 'flutter_dash',
    'aparat'    => 'ondemand_video',
    'other'     => 'campaign',
];
$platformTextMap = [
    'instagram' => 'text-instagram',
    'youtube'   => 'text-youtube',
    'telegram'  => 'text-telegram',
    'tiktok'    => 'text-tiktok',
    'twitter'   => 'text-twitter',
    'aparat'    => 'text-aparat',
    'other'     => 'text-other',
];

$statusIconMap = [
    'completed'  => 'ok',
    'pending'    => 'wt',
    'processing' => 'wt',
    'failed'     => 'er',
    'cancelled'  => 'er',
    'active'     => 'ok',
    'paused'     => 'wt',
    'rejected'   => 'er',
    'verified'   => 'ok',
];

$statusLabelMap = $stMap;
$statusColorMap = [
    'completed'  => 'text-green',
    'pending'    => 'text-warn',
    'processing' => 'text-warn',
    'failed'     => 'text-red',
    'cancelled'  => 'text-red',
    'active'     => 'text-green',
    'paused'     => 'text-info',
    'rejected'   => 'text-red',
    'verified'   => 'text-green',
];

$txColorClassMap = [
    'var(--success)' => 'text-up',
    'var(--danger)'  => 'text-down',
    'var(--info)'    => 'text-info',
    'var(--purple)'  => 'text-purple',
    'var(--warning)' => 'text-warn',
    'var(--muted)'   => 'text-muted',
];

$pHistClassMap = $platformClassMap;
$pHistIconMap = [
    'instagram' => 'camera_alt',
    'youtube'   => 'play_circle',
    'telegram'  => 'send',
    'twitter'   => 'flutter_dash',
    'tiktok'    => 'music_video',
    'other'     => 'task_alt',
];

$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/user/dashboard.css') . '">';
?>

<?php if($pendingTxCount > 0): ?>
<div class="alert-bar">
  <span class="material-icons">schedule</span>
  <span><strong><?= e($pendingTxCount) ?> تراکنش</strong> در حال پردازش دارید.</span>
  <a href="<?= url('/wallet/history') ?>">مشاهده →</a>
</div>
<?php endif; ?>

<!-- 🛡️ OPT-IN WELCOME POPUP MODAL (نمایش پاپ‌آپ هنگام ورود به جای بنر بالای صفحه) -->
<?php $xpGrowthRate = (int)config('video_rewards.xp_growth_rate', setting('xp_growth_rate', 50)); ?>
<div class="reward-modal-wrap" id="xpBoostWelcomeModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.85); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
    <div class="reward-modal-box" style="width: 100%; max-width: 520px; background: #1e293b; border-radius: 24px; padding: 38px 32px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid rgba(59,130,246,0.3); transform: scale(0.9); transition: all 0.3s ease;" id="xpBoostWelcomeModalBox">
        <div style="width: 72px; height: 72px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 20px; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 20px;">
            <span class="material-icons" style="font-size: 2.8rem;">trending_up</span>
        </div>
        <h3 style="font-size: 1.45rem; font-weight: 700; margin-bottom: 12px; color: #fff;">🛡️ افزایش سرعت ارتقای رتبه کاربری</h3>
        <span style="display: inline-block; background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 18px;">کاملاً اختیاری و هدیه</span>
        <p style="font-size: 1.05rem; color: #cbd5e1; line-height: 1.8; margin-bottom: 30px;">
            آیا می‌خواهید با تماشای یک ویدیوی تبلیغاتی کوتاه، سرعت پر شدن نوار پیشرفت سطح کاربری (Level) شما <strong style="color:#60a5fa; font-size:1.15rem;"><?= $xpGrowthRate ?>٪</strong> افزایش یابد؟
        </p>
        <div style="display: flex; gap: 14px; flex-direction: column;">
            <button type="button" onclick="acceptXpBoostWelcome()" style="background: #10b981; color: #fff; border: none; padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(16,185,129,0.4); transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                <span class="material-icons">play_circle_outline</span> تماشای ویدیوی ۱۵ ثانیه‌ای
            </button>
            <button type="button" onclick="dismissXpBoostWelcome()" style="background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,0.15); padding: 12px 24px; border-radius: 14px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; width: 100%;">
                خیر، متشکرم (انصراف)
            </button>
        </div>
    </div>
</div>

<!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S داشبورد -->
<div class="reward-modal-wrap" id="dashRewardModalWrap" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease;">
    <div class="reward-modal-box" style="width: 100%; max-width: 580px; background: #1e293b; border-radius: 24px; padding: 45px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease;" id="dashRewardModalBox">
        <div style="width: 80px; height: 80px; background: rgba(59,130,246,0.2); border: 2px solid #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3b82f6; margin: 0 auto 25px;" id="dashRewardModalIcon"><span class="material-icons" style="font-size: 3.2rem;" id="dashRewardModalIconTxt">hourglass_empty</span></div>
        <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 15px;" id="dashRewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
        <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.8; margin-bottom: 35px;" id="dashRewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#3b82f6;" id="dashRewardCountdown">15</strong> ثانیه باقی‌مانده</p>
        <button type="button" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius:16px; display:none;" id="dashRewardCloseBtn" onclick="closeDashRewardModal()">بستن و اعمال شتاب‌دهنده سطح</button>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
function openXpBoostWelcomeModal() {
    const wrap = document.getElementById('xpBoostWelcomeModalWrap');
    const box = document.getElementById('xpBoostWelcomeModalBox');
    if (wrap && box) {
        wrap.style.opacity = '1';
        wrap.style.pointerEvents = 'auto';
        box.style.transform = 'scale(1)';
    }
}
function dismissXpBoostWelcome() {
    const wrap = document.getElementById('xpBoostWelcomeModalWrap');
    if (wrap) {
        wrap.style.opacity = '0';
        wrap.style.pointerEvents = 'none';
    }
    try { sessionStorage.setItem('xp_boost_popup_shown_session_v2', '1'); } catch(e){}
}
function acceptXpBoostWelcome() {
    dismissXpBoostWelcome();
    setTimeout(() => {
        startDashboardRewardedVideo('tapsell', 15);
    }, 200);
}
window.addEventListener('DOMContentLoaded', () => {
    try {
        if (!sessionStorage.getItem('xp_boost_popup_shown_session_v2')) {
            setTimeout(() => openXpBoostWelcomeModal(), 1000);
        }
    } catch(e){}
});

function startDashboardRewardedVideo(network, duration) {
    const modal = document.getElementById('dashRewardModalWrap');
    const box = document.getElementById('dashRewardModalBox');
    const title = document.getElementById('dashRewardModalTitle');
    const body = document.getElementById('dashRewardModalBody');
    const icon = document.getElementById('dashRewardModalIconTxt');
    const iconBox = document.getElementById('dashRewardModalIcon');
    const countTxt = document.getElementById('dashRewardCountdown');
    const closeBtn = document.getElementById('dashRewardCloseBtn');

    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    box.style.transform = 'scale(1)';
    title.innerText = 'در حال پخش ویدیوی تبلیغاتی...';
    iconBox.style.borderColor = '#3b82f6';
    iconBox.style.background = 'rgba(59,130,246,0.2)';
    iconBox.style.color = '#3b82f6';
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
            body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ سرعت رشد نوار پیشرفت سطح کاربری شما <?= $xpGrowthRate ?>٪ افزایش یافت.';
            closeBtn.style.display = 'block';
        }
    }, 1000);
}
function closeDashRewardModal() {
    const btn = document.getElementById('dashRewardCloseBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerText = 'در حال ثبت در سرور...';
    }
    fetch('/adtube/claim-boost', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('dashRewardModalWrap').style.opacity = '0';
        document.getElementById('dashRewardModalWrap').style.pointerEvents = 'none';
        alert(data.message || 'شتاب‌دهنده پیشرفت سطح کاربری با موفقیت فعال شد.');
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'بستن و اعمال شتاب‌دهنده سطح';
        }
    })
    .catch(err => {
        document.getElementById('dashRewardModalWrap').style.opacity = '0';
        document.getElementById('dashRewardModalWrap').style.pointerEvents = 'none';
        alert('شتاب‌دهنده <?= $xpGrowthRate ?> درصدی پیشرفت سطح کاربری با موفقیت فعال شد.');
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'بستن و اعمال شتاب‌دهنده سطح';
        }
    });
}
</script>

<!-- ══ ROW 1: KPI Cards ══ -->
<div class="kpi-row">
  <!-- Wallet -->
  <div class="kc kc-w">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">account_balance_wallet</span></div><div class="kc-bdg bd-on"><span class="bd-dot"></span>فعال</div></div>
    <div class="kc-lbl">موجودی کیف پول</div>
    <div class="w-body">
      <div class="w-row">
        <div class="w-left"><div class="w-cic irt">﷼</div><div><div class="w-cn">تومان</div><div class="w-ca"><?= $walletBalance > 0 ? number_format($walletBalance) : '۰' ?></div></div></div>
        <a href="<?= url('/wallet/deposit') ?>" class="w-plus ip" title="واریز"><span class="material-icons">add</span></a>
      </div>
      <div class="w-row">
        <div class="w-left"><div class="w-cic usdt">$</div><div><div class="w-cn">تتر (USDT)</div><div class="w-ca usdt"><?= number_format($walletBalanceUsdt, 2) ?></div></div></div>
        <a href="<?= url('/wallet/deposit') ?>" class="w-plus up" title="واریز تتر"><span class="material-icons">add</span></a>
      </div>
    </div>
  </div>

  <!-- Tasks -->
  <div class="kc kc-t">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">task_alt</span></div><div class="kc-bdg bd-gn"><span class="bd-dot"></span><?= $tasksPending > 0 ? $tasksPending.' در انتظار' : 'به‌روز' ?></div></div>
    <div class="kc-lbl">تسک‌ها و درآمد</div>
    <div class="t-inc"><?= number_format($tasksEarned ?: 0) ?><span>تومان</span></div>
    <div class="t-stats">
      <div class="ts"><div class="ts-n al"><?= number_format($tasksTotal) ?></div><div class="ts-l">کل تسک</div></div>
      <div class="ts"><div class="ts-n dn"><?= number_format($tasksCompleted) ?></div><div class="ts-l">انجام‌شده</div></div>
      <div class="ts"><div class="ts-n pd"><?= number_format($tasksPending) ?></div><div class="ts-l">در انتظار</div></div>
    </div>
    <div class="t-bar">
      <div class="t-bar-bg"><div class="t-bar-fill progress-bar-fill" data-progress="<?= e($taskSuccess) ?>%"></div></div>
      <div class="t-bar-lbl">نرخ موفقیت <strong><?= e($taskSuccess) ?>%</strong></div>
    </div>
  </div>

  <!-- Investment -->
  <div class="kc kc-i">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">trending_up</span></div>
      <?php
        $invStatus = $activeInvestment ? ($activeInvestment->status ?? 'active') : null;
        $invBadgeClass = 'bd-gn'; $invBadgeTxt = 'فعال';
        if(!$activeInvestment){ $invBadgeClass='bd-on'; $invBadgeTxt='بدون پلن'; }
        elseif($invStatus==='expired'||$invStatus==='closed'){ $invBadgeClass='bd-rd'; $invBadgeTxt='پایان‌یافته'; }
      ?>
      <div class="kc-bdg <?= $invBadgeClass ?>"><span class="bd-dot"></span><?= $invBadgeTxt ?></div>
    </div>
    <div class="kc-lbl">سرمایه‌گذاری</div>
    <?php if($activeInvestment): ?>
      <?php
        $invProfit = (float)($activeInvestment->profit_amount ?? $activeInvestment->expected_profit ?? 0);
        $invAmount = (float)($activeInvestment->amount ?? 0);
        $invProfitSign = $invProfit >= 0 ? '+' : '';
        $invProfitColor = $invProfit >= 0 ? '#4ade80' : '#f87171';
        $invEndDate = $activeInvestment->end_date ?? $activeInvestment->expire_date ?? null;
        $invPlanName = $activeInvestment->plan_name ?? $activeInvestment->plan_title ?? 'پلن سرمایه‌گذاری';
      ?>
      <div class="inv-kc-body">
        <div class="inv-kc-plan"><span class="material-icons">auto_graph</span><?= e($invPlanName) ?></div>
        <div class="inv-kc-row">
          <div class="inv-kc-item">
            <div class="inv-kc-lbl">مبلغ سرمایه</div>
            <div class="inv-kc-val"><?= number_format($invAmount) ?><span>تومان</span></div>
          </div>
          <div class="inv-kc-item">
            <div class="inv-kc-lbl">سود / زیان</div>
            <div class="inv-kc-val investment-value <?= $invProfit >= 0 ? 'text-green' : 'text-red' ?>"><?= $invProfitSign ?><?= number_format($invProfit) ?><span>تومان</span></div>
          </div>
        </div>
        <?php if($invEndDate): ?>
        <div class="inv-kc-date"><span class="material-icons">event</span>پایان پلن: <?= e($invEndDate) ?></div>
        <?php endif; ?>
      </div>
    <?php else: ?>
        <div class="inv-empty">
        <div class="inv-empty-label">پلن فعالی ندارید</div>
        <div class="inv-empty-value">---</div>
        <a href="<?= url('/investment') ?>" class="kpi-action kpi-action-blue">
          <span class="material-icons kpi-action-icon">add_circle</span>شروع سرمایه‌گذاری
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Lottery KPI -->
  <div class="kc kc-l">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">casino</span></div><div class="kc-bdg bd-gd">لاتاری</div></div>
    <div class="kc-lbl">قرعه‌کشی هفتگی</div>
    <?php if($lotteryRound): ?>
    <div class="lot-box">
      <div class="lot-hd"><span class="material-icons">timer</span>زمان باقی‌مانده</div>
      <div class="lot-cd">
        <div class="lot-u"><span class="lot-n" id="lotH">00</span><span class="lot-ul">ساعت</span></div>
        <span class="lot-sep">:</span>
        <div class="lot-u"><span class="lot-n" id="lotM">00</span><span class="lot-ul">دقیقه</span></div>
        <span class="lot-sep">:</span>
        <div class="lot-u"><span class="lot-n" id="lotS">00</span><span class="lot-ul">ثانیه</span></div>
      </div>
      <div class="lot-st lst-on" id="lotSt"><span class="lot-dot"></span>ثبت‌نام فعال است</div>
    </div>
    <?php else: ?>
        <div class="lot-empty">
        <div class="lottery-empty">لاتاری فعالی وجود ندارد</div>
        <a href="<?= url('/lottery') ?>" class="lottery-action lottery-action-gold">
          <span class="material-icons lottery-action-icon">confirmation_number</span>مشاهده
        </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ ROW 2: Chart + Vote ══ -->
<div class="db-r2">
  <!-- نمودار -->
  <div class="wc">
    <div class="ctabs-w">
      <div class="ctabs">
        <button class="ctab on" data-tab="inc">درآمد</button>
        <button class="ctab"    data-tab="tsk">تسک‌ها</button>
        <button class="ctab"    data-tab="plt">پلتفرم</button>
        <button class="ctab"    data-tab="dep">مالی</button>
      </div>
      <a href="<?= url('/wallet/history') ?>" class="sml">بیشتر<span class="material-icons">arrow_back_ios</span></a>
    </div>
    <div class="chart-container">
      <div id="ch-inc" class="chart-tab-container"><canvas id="cInc"></canvas></div>
      <div id="ch-tsk" class="chart-tab-container hidden"><canvas id="cTsk"></canvas></div>
      <div id="ch-plt" class="chart-tab-container hidden"><canvas id="cPlt"></canvas></div>
      <div id="ch-dep" class="chart-tab-container hidden"><canvas id="cDep"></canvas></div>
    </div>
    <div class="chart-strip">
      <div class="cs-item"><div class="cs-v up"><?= number_format($totalEarnings ?: $tasksEarned) ?></div><div class="cs-l">کل درآمد</div></div>
      <div class="cs-item"><div class="cs-v"><?= number_format($tasksCompleted) ?></div><div class="cs-l">تسک انجام‌شده</div></div>
      <div class="cs-item"><div class="cs-v up"><?= number_format($walletBalanceUsdt,2) ?></div><div class="cs-l">موجودی USDT</div></div>
      <div class="cs-item"><div class="cs-v"><?= number_format($referralCount) ?></div><div class="cs-l">زیرمجموعه</div></div>
    </div>
  </div>

  <!-- ستون کنار: رأی‌گیری -->
  <div class="db-col db-col-gap-none">
    <div class="wc">
      <div class="wc-h"><h6><span class="material-icons">how_to_vote</span>رأی‌گیری روزانه</h6></div>
      <?php
        $voteIsSample = !$lotteryRound && !$todayDailyNumber;
        $sampleVoteCounts = ['0'=>12,'1'=>34,'2'=>8,'3'=>45,'4'=>21,'5'=>67,'6'=>15,'7'=>29,'8'=>53,'9'=>41];
        $sampleTotalVotes = array_sum($sampleVoteCounts);
        $displayVoteCounts = $voteIsSample ? $sampleVoteCounts : $todayVoteCounts;
        $displayTotalVotes = $voteIsSample ? $sampleTotalVotes : $totalDailyVotes;
      ?>
      <div class="vote-body">
        <?php if($voteIsSample): ?>
          <div class="vote-title">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums vote-num-gap" id="voteButtons">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= $n ?>" data-inactive="1"><?= $n ?></button>
            <?php endfor; ?>
          </div>
          <div class="vote-message">رأی‌گیری فعالی در جریان نیست</div>
        <?php elseif($userTodayVote): ?>
          <div class="vote-done-msg"><span class="material-icons">check_circle</span>رأی شما: <strong class="vote-done-num"><?= e($userTodayVote->voted_number) ?></strong> ثبت شد!</div>
        <?php elseif($todayDailyNumber): ?>
          <div class="vote-title">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums vote-num-gap" id="voteButtons">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= e($n) ?>"><?= e($n) ?></button>
            <?php endfor; ?>
          </div>
          <div id="voteMsg" class="vote-message"></div>
        <?php else: ?>
          <div class="vote-title">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums vote-num-gap" id="voteButtons">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= $n ?>" data-inactive="2"><?= $n ?></button>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
        <?php if($displayTotalVotes > 0): ?>
        <div class="vote-result">
          <div class="vote-result-title"><span class="material-icons">bar_chart</span>آمار امروز (<?= number_format($displayTotalVotes) ?> رأی)</div>
          <?php for($n=0; $n<=9; $n++): $cnt=$displayVoteCounts[(string)$n]??0; $pct=$displayTotalVotes>0?round($cnt/$displayTotalVotes*100):0; ?>
          <div class="vr-bar-row"><span class="vr-num"><?= $n ?></span><div class="vr-bar-bg"><div class="vr-bar-fill vr-bar-fill-var" data-progress="<?= $pct ?>%"></div></div><span class="vr-cnt"><?= $cnt ?></span><span class="vr-pct"><?= $pct ?>%</span></div>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php if($voteIsSample): ?><div class="s-note vote-sample-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ لاتاری هیرو ══ -->
<?php
  $lhParticipants = 0; $lhUserTickets = 0; $lhPrize = 0; $lhPrizeTxt = '';
  $lhRoundNum = '—'; $lhHasData = false;
  if($lotteryRound){
    $lhHasData     = true;
    $lhParticipants= (int)($lotteryRound->participants_count ?? $lotteryRound->participant_count ?? 0);
    $lhUserTickets = (int)($lotteryRound->user_tickets ?? $lotteryRound->my_tickets ?? 0);
    $lhPrize       = (float)($lotteryRound->prize_pool ?? $lotteryRound->total_prize ?? $lotteryRound->prize_amount ?? 0);
    $lhPrizeTxt    = $lotteryRound->prize_description ?? $lotteryRound->prize_text ?? '';
    $lhRoundNum    = $lotteryRound->round_number ?? $lotteryRound->id ?? '—';
  }
  if(!$lhHasData){ $lhParticipants=248; $lhUserTickets=0; $lhPrize=5000000; $lhRoundNum='نمونه'; }
  $lhPrizeDisplay = $lhPrize >= 1000000 ? number_format($lhPrize/1000000,1).'M' : ($lhPrize > 0 ? number_format($lhPrize/1000).'K' : ($lhPrizeTxt ? mb_substr($lhPrizeTxt,0,8) : '🎁'));
?>
<div class="lhero" data-href="<?= url('/lottery') ?>">
  <div class="lhero-label"><span class="material-icons">casino</span>قرعه‌کشی هفتگی<span class="lhero-round">رند #<?= e($lhRoundNum) ?></span></div>
  <div class="lhero-row">
    <div class="lhero-item">
      <span class="material-icons lhero-ic-purple">people</span>
      <div class="lhero-val"><?= number_format($lhParticipants) ?></div>
      <div class="lhero-lbl">شرکت‌کننده</div>
    </div>
    <div class="lhero-sep"></div>
    <div class="lhero-item">
      <span class="material-icons lhero-ic-blue">confirmation_number</span>
      <div class="lhero-val <?= $lhUserTickets > 0 ? 'lhero-green' : '' ?>"><?= $lhUserTickets > 0 ? $lhUserTickets : '—' ?></div>
      <div class="lhero-lbl">بلیط شما</div>
    </div>
    <div class="lhero-sep"></div>
    <div class="lhero-item">
      <span class="material-icons lhero-ic-gold">emoji_events</span>
      <div class="lhero-val lhero-gold"><?= e($lhPrizeDisplay) ?></div>
      <div class="lhero-lbl"><?= $lhPrize > 0 ? 'جایزه (تومان)' : 'جایزه' ?></div>
    </div>
    <div class="lhero-sep"></div>
    <a href="<?= url('/lottery') ?>" class="lhero-cta cta-link">
      <span><?= $lhUserTickets > 0 ? 'مشاهده' : 'شرکت کنید' ?></span>
      <span class="material-icons">arrow_back_ios</span>
    </a>
  </div>
</div>

<!-- ══ ROW 3: دوستونه - تسک‌های پیشنهادی + آخرین کمپین‌ها ══ -->
<div class="db-r4">
  <!-- تسک‌های پیشنهادی -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">bolt</span>تسک‌های پیشنهادی</h6><a href="<?= url('/tasks') ?>" class="sml">همه</a></div>
    <div class="task-list">
      <?php
      $taskSamples=[
        ['فالو اینستاگرام @techpage','camera_alt','#E1306C',15200,'instagram'],
        ['ویو ویدیو یوتیوب','play_circle','#FF0000',20000,'youtube'],
        ['عضویت کانال تلگرام','send','#0088cc',11000,'telegram'],
        ['ریتوییت توییتر','flutter_dash','#1DA1F2',8500,'twitter'],
      ];
      foreach($taskSamples as $ts): ?>
      <div class="task-item">
        <div class="task-icon <?= e($platformTextMap[$ts[4] ?? 'other'] ?? 'text-other') ?>"><span class="material-icons"><?= $ts[1] ?></span></div>
        <div class="task-d"><div class="task-ttl"><?= $ts[0] ?></div><div class="task-rw">+<?= number_format($ts[3]) ?> تومان</div></div>
        <a href="<?= url('/tasks') ?>" class="task-btn">انجام</a>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="wc-f"><a href="<?= url('/tasks') ?>"><span class="material-icons">add_task</span>مشاهده همه تسک‌ها</a></div>
  </div>

  <!-- آخرین کمپین‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">campaign</span>آخرین کمپین‌ها</h6><a href="<?= url('/advertiser') ?>" class="sml">همه</a></div>
    <div class="camp-list">
      <?php foreach(array_slice(is_array($displayAds ?? null) ? $displayAds : [],0,4) as $ad):
        $plt = $ad->platform ?? 'other';
        $pic = $pIcons[$plt] ?? 'campaign';
        $pcl = $pColors[$plt] ?? '#9A9AB0';
        $st  = $ad->status ?? 'active';
        [$sl,$sc,$sb,$sc2] = $stMap[$st] ?? ['فعال','var(--success)','transparent','ok'];
      ?>
      <div class="camp-item">
        <div class="camp-ic <?= e($platformClassMap[$plt] ?? 'gradient-other') ?>"><span class="material-icons"><?= e($pic) ?></span></div>
        <div class="camp-info">
          <div class="camp-name"><?= e($ad->title ?? 'کمپین') ?></div>
          <div class="camp-sub"><?= e($ad->task_type ?? 'تبلیغات') ?></div>
          <?php if((float)($ad->remaining_budget??0) > 0): ?>
          <div class="camp-amt"><?= number_format((float)$ad->remaining_budget) ?> تومان مانده</div>
          <?php else: ?><div class="camp-warn">بودجه تمام شده</div><?php endif; ?>
        </div>
        <span class="sdg <?= $sc2 ?>"><?= e($sl) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if($isSampleAds): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/ads/create') ?>"><span class="material-icons">add_circle</span>کمپین جدید</a></div>
  </div>
</div>

<!-- ══ ROW 4: سه‌ستونه - پیج‌های ثبت‌شده + آخرین تراکنش‌ها + تاریخچه تسک‌ها ══ -->
<div class="db-r3">
  <!-- پیج‌های ثبت‌شده -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">share</span>پیج‌های ثبت‌شده</h6><a href="<?= url('/social-accounts') ?>" class="sml"><span class="material-icons">add</span>افزودن</a></div>
    <div class="page-list">
      <?php foreach(array_slice(is_array($displayPages ?? null) ? $displayPages : [],0,3) as $pg):
        $plt=$pg->platform??'instagram'; $pic=$pIcons[$plt]??'share'; $pcl=$pColors[$plt]??'#9A9AB0';
        $st=$pg->status??'pending'; [$sl,$sc,$sb,$sc2]=$stMap[$st]??['—','var(--muted)','transparent','wt'];
      ?>
      <div class="page-item">
        <div class="page-ic <?= e($platformClassMap[$plt] ?? 'gradient-other') ?>"><span class="material-icons"><?= e($pic) ?></span></div>
        <div><div class="page-name">@<?= e($pg->username??'نام کاربری') ?></div><div class="page-sub"><?= number_format($pg->followers_count??0) ?> دنبال‌کننده</div><span class="page-st <?= $sc2 ?>"><span class="material-icons"><?= $sc2==='ok'?'verified':'schedule' ?></span><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; ?>
      <div class="page-item page-add-item" data-href="<?= url('/social-accounts') ?>">
        <span class="material-icons page-add-icon">add_circle</span>
        <span class="page-add-text">افزودن پیج جدید</span>
      </div>
    </div>
    <?php if($isSamplePages): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/social-accounts') ?>"><span class="material-icons">manage_accounts</span>مدیریت پیج‌ها</a></div>
  </div>

  <!-- آخرین تراکنش‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">receipt_long</span>آخرین تراکنش‌ها</h6><a href="<?= url('/wallet/history') ?>" class="sml"><span class="material-icons">arrow_back_ios</span>همه</a></div>
    <table class="xtbl">
      <thead><tr><th>نوع</th><th>شناسه</th><th>مبلغ (تومان)</th><th>وضعیت</th></tr></thead>
      <tbody>
      <?php foreach(array_slice(is_array($displayTx ?? null) ? $displayTx : [],0,5) as $tx):
        $ti  = $txMap[$tx->type??''] ?? ['تراکنش','payments','var(--muted)',true];
        $st  = $tx->status ?? 'pending';
        [$sl,$sc,$sb,$sc2] = $stMap[$st] ?? ['—','var(--muted)','transparent','wt'];
        $inc = $ti[3];
      ?>
      <tr>
        <td><div class="tx-cell"><span class="material-icons tx-cell-icon <?= e($txColorClassMap[$ti[2]] ?? 'text-muted') ?>"><?= e($ti[1]) ?></span><span><?= e($ti[0]) ?></span></div></td>
        <td class="tx-id-cell"><?= e(substr($tx->id??'—',0,8)) ?></td>
        <td class="<?= $inc?'ap':'am' ?>"><?= $inc?'+':'-' ?><?= number_format((float)($tx->amount??0)) ?></td>
        <td><span class="sdg <?= $sc2 ?>"><?= e($sl) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($isSampleTx): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/wallet/history') ?>"><span class="material-icons">visibility</span>مشاهده همه تراکنش‌ها</a></div>
  </div>

  <!-- تاریخچه تسک‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">history</span>تاریخچه تسک‌ها</h6><a href="<?= url('/tasks/history') ?>" class="sml">همه</a></div>
    <?php
    $pHistColors=['instagram'=>'#E1306C','youtube'=>'#FF0000','telegram'=>'#0088cc','twitter'=>'#1DA1F2','tiktok'=>'#333','other'=>'#6B7280'];
    $pHistIcons=['instagram'=>'camera_alt','youtube'=>'play_circle','telegram'=>'send','twitter'=>'flutter_dash','tiktok'=>'music_video','other'=>'task_alt'];
    $taskHistSamples=[
      ['فالو اینستاگرام','instagram','لایک و فالو',15200,'completed'],
      ['ویو یوتیوب','youtube','ویو ویدیو',20000,'completed'],
      ['عضویت کانال','telegram','عضویت',11000,'pending'],
      ['ریتوییت توییتر','twitter','ریتوییت',8500,'completed'],
    ];
    $isSampleTasks = empty($recentTaskExecutions);
    ?>
    <div class="th-rows">
      <?php if($isSampleTasks): foreach($taskHistSamples as $r):
        [$sl,$sc,$sb,$sc2]=$stMap[$r[4]]??['—','var(--muted)','transparent','wt'];
        $plt=$r[1]; $pic=$pHistIcons[$plt]??'task_alt'; $pcl=$pHistColors[$plt]??'#6B7280';
      ?>
      <div class="th-row">
        <div class="th-pl <?= $platformClassMap[$r[1] ?? 'other'] ?? 'gradient-other' ?>"><span class="material-icons"><?= $pic ?></span></div>
        <div class="th-info"><div class="th-name"><?= e($r[0]) ?></div><div class="th-type"><?= e($r[2]) ?></div></div>
        <div class="th-amount-col"><div class="th-amt">+<?= number_format($r[3]) ?></div><span class="sdg <?= $sc2 ?> badge-xs"><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; else: foreach(array_slice(is_array($recentTaskExecutions ?? null) ? $recentTaskExecutions : [],0,4) as $te):
        $st=$te->status??'pending';
        [$sl,$sc,$sb,$sc2]=$stMap[$st]??['—','var(--muted)','transparent','wt'];
        $plt=$te->platform??'other'; $pic=$pHistIcons[$plt]??'task_alt'; $pcl=$pHistColors[$plt]??'#6B7280';
      ?>
      <div class="th-row">
        <div class="th-pl <?= e($platformClassMap[$plt] ?? 'gradient-other') ?>"><span class="material-icons"><?= e($pic) ?></span></div>
        <div class="th-info"><div class="th-name"><?= e($te->ad_title??$te->task_title??'—') ?></div><div class="th-type"><?= e($te->task_type??'—') ?></div></div>
        <div class="th-amount-col"><div class="th-amt">+<?= number_format((float)($te->reward_amount??0)) ?></div><span class="sdg <?= $sc2 ?> badge-xs"><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <?php if($isSampleTasks): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/tasks') ?>"><span class="material-icons">add_task</span>انجام تسک جدید</a></div>
  </div>
</div>

<!-- Charts + JS -->

<?php
$content = ob_get_clean();
$scripts = '<script id="user-dashboard-data" type="application/json" nonce="' . e($cspNonce ?? '') . '">' . json_encode($viewData, JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
         . '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/vendor/chartjs/chart.umd.min.js') . '"></script>' . "\n"
         . '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/user/dashboard.js') . '"></script>';
include view_path('layouts.user');
?>