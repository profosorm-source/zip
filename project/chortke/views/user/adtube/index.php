<?php
$title = 'AdTube — کسب درآمد از یوتیوب و تبلیغات جایزه‌دار';
$hideSidebar = true;
$rewardAds = $rewardAds ?? [];
$tasks = $tasks ?? [];
$stats = $stats ?? [];
ob_start();
?>
<style>
/* ── استایل‌های هاب یکپارچه AdTube (Dual Tabbed Hub) ── */
.adtube-btn-hist { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; padding: 10px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; }
.adtube-btn-hist:hover { background: #fff; color: #0f172a; transform: translateY(-2px); }
/* ناو تب‌ها */
.adtube-nav-pills { display: flex; gap: 12px; margin-bottom: 30px; border-bottom: 2px solid var(--fin-border-soft, #181F2A); padding-bottom: 16px; flex-wrap: wrap; }
.adtube-nav-pills .nav-link { padding: 14px 24px; border-radius: 14px; font-weight: 700; font-size: 1rem; color: #64748b; background: var(--fin-surface-2, #161C27); border: 1px solid var(--fin-border-soft, #181F2A); transition: all 0.2s ease; display: flex; align-items: center; gap: 10px; cursor: pointer; }
.adtube-nav-pills .nav-link:hover { background: var(--fin-border-soft, #181F2A); color: #fff; }
.adtube-nav-pills .nav-link.active { background: #ef4444; color: #fff; border-color: #ef4444; box-shadow: 0 8px 20px -4px rgba(239,68,68,0.4); }
.adtube-nav-pills .nav-link.active#tab-rewards { background: #2563eb; border-color: #2563eb; box-shadow: 0 8px 20px -4px rgba(37,99,235,0.4); }
/* کارت‌های تبلیغات جایزه‌دار */
.reward-ad-card { border-radius: 20px; padding: 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 16px 32px -10px rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s ease; height: 100%; box-sizing: border-box; }
.reward-ad-card:hover { transform: translateY(-4px); }
.reward-ad-card--tapsell { background: linear-gradient(135deg, #0284c7 0%, #1d4ed8 100%); }
.reward-ad-card--admob { background: linear-gradient(135deg, #1e293b 0%, #090d16 100%); border-color: #d4af37; }
.reward-ad-card--unity { background: linear-gradient(135deg, #374151 0%, #111827 100%); border-color: #10b981; }
.reward-ad-card--applovin { background: linear-gradient(135deg, #4c1d95 0%, #1e1b4b 100%); border-color: #a855f7; }
.reward-ad__top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.reward-ad__curr { font-size: 1.2rem; font-weight: 700; background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 10px; backdrop-filter: blur(5px); }
.reward-ad__title { font-size: 1.35rem; font-weight: 700; margin-bottom: 10px; }
.reward-ad__desc { font-size: 0.95rem; opacity: 0.9; line-height: 1.7; margin-bottom: 24px; flex-grow: 1; }
.reward-ad__payout { font-size: 1.8rem; font-weight: 700; margin-bottom: 22px; display: flex; align-items: center; gap: 8px; }
.reward-ad__payout span { font-size: 0.95rem; font-weight: 600; opacity: 0.8; }
.reward-ad__btn { width: 100%; padding: 14px; border-radius: 14px; border: none; font-size: 1.05rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease; }
.reward-ad-card--tapsell .reward-ad__btn { background: #10b981; color: #fff; box-shadow: 0 8px 16px rgba(16,185,129,0.4); }
.reward-ad-card--tapsell .reward-ad__btn:hover { background: #059669; transform: translateY(-2px); }
.reward-ad-card--admob .reward-ad__btn { background: #d4af37; color: #090d16; box-shadow: 0 8px 16px rgba(212,175,55,0.3); }
.reward-ad-card--admob .reward-ad__btn:hover { background: #bf9b30; transform: translateY(-2px); }
.reward-ad-card--unity .reward-ad__btn { background: #10b981; color: #fff; box-shadow: 0 8px 16px rgba(16,185,129,0.3); }
.reward-ad-card--applovin .reward-ad__btn { background: #a855f7; color: #fff; box-shadow: 0 8px 16px rgba(168,85,247,0.3); }
/* استایل مودال تماشای ویدیو */
.reward-modal-wrap { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: all 0.3s ease; }
.reward-modal-wrap.show { opacity: 1; pointer-events: auto; }
.reward-modal-box { width: 100%; max-width: 540px; background: #1e293b; border-radius: 24px; padding: 40px; text-align: center; color: #fff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); transform: scale(0.9); transition: all 0.3s ease; }
.reward-modal-wrap.show .reward-modal-box { transform: scale(1); }
.reward-modal__icon { width: 72px; height: 72px; background: rgba(16,185,129,0.2); border: 2px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; margin: 0 auto 20px; }
.reward-modal__icon .material-icons { font-size: 2.8rem; }
.reward-modal__title { font-size: 1.45rem; font-weight: 700; margin-bottom: 12px; }
.reward-modal__body { font-size: 1.05rem; color: #94a3b8; line-height: 1.8; margin-bottom: 30px; }
</style>

<div class="earn-wrap task-market-wrap">
    <!-- Hero Banner -->
    <section class="earn-hero">
        <div class="earn-hero__main">
            <div class="earn-hero__icon" style="background:rgba(239,68,68,0.15); color:#ef4444; border:1px solid #ef4444;"><i class="material-icons">play_circle_filled</i></div>
            <div>
                <div class="earn-hero__eyebrow" style="color:#ef4444;">Earn Hub / AdTube</div>
                <h1 class="earn-hero__title">AdTube — هاب ویدیویی کسب درآمد</h1>
                <p class="earn-hero__sub">ویدیوهای یوتیوب کارفرمایان یا تبلیغات ثانیه‌ای شبکه‌های تبلیغاتی را تماشا کنید و به صورت آنی درآمد بگیرید.</p>
            </div>
        </div>
        <div class="earn-hero__side">
            <a href="<?= url('/adtube/history') ?>" class="adtube-btn-hist"><span class="material-icons">history</span> تاریخچه کسب درآمد</a>
            <a href="<?= url('/tasks') ?>" class="earn-btn earn-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به بازار تسک‌ها</a>
            <a href="<?= url('/dashboard') ?>" class="earn-btn earn-btn-ghost"><i class="material-icons">dashboard</i> پنل کاربری</a>
        </div>
    </section>

    <!-- Hub Layout with Spoke Nav -->
    <div class="earn-hub-layout">
        <?php $activeSpoke = 'adtube'; include view_path('user.tasks._earn-nav'); ?>

        <main class="earn-hub-main">
            <!-- آمار بالای صفحه -->
            <?php if(!empty($stats)): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:28px;">
              <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:16px; padding:20px; text-align:center;">
                <div style="font-weight:800; color:#3b82f6; font-size:1.6rem;"><?= number_format($stats['total_completed_today'] ?? 0) ?></div>
                <div style="color:var(--text-muted, #94a3b8); font-size:0.85rem; margin-top:4px;">ویدیوهای امروز شما</div>
              </div>
              <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:16px; padding:20px; text-align:center;">
                <div style="font-weight:800; color:#10b981; font-size:1.6rem;"><?= number_format($stats['active_watching'] ?? 0) ?></div>
                <div style="color:var(--text-muted, #94a3b8); font-size:0.85rem; margin-top:4px;">در حال تماشا</div>
              </div>
              <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:16px; padding:20px; text-align:center;">
                <div style="font-weight:800; color:#f59e0b; font-size:1.6rem;"><?= number_format($stats['total_earned_today'] ?? 0) ?></div>
                <div style="color:var(--text-muted, #94a3b8); font-size:0.85rem; margin-top:4px;">درآمد امروز (تومان)</div>
              </div>
            </div>
            <?php endif; ?>

            <!-- ناو تب‌ها (Dual Tabbed Hub) -->
            <ul class="nav adtube-nav-pills" id="adtubeTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-youtube" data-bs-toggle="pill" data-bs-target="#content-youtube" type="button" role="tab">
                        <span class="material-icons">video_library</span> ۱. ویدیوهای یوتیوبی کارفرما (YouTube AdTube)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-rewards" data-bs-toggle="pill" data-bs-target="#content-rewards" type="button" role="tab">
                        <span class="material-icons">card_giftcard</span> ۲. تبلیغات ویدیویی جایزه‌دار (Ad Network Rewards)
                    </button>
                </li>
            </ul>

            <!-- بدنه تب‌ها -->
            <div class="tab-content" id="adtubeTabContent">
                
                <!-- تب اول: ویدیوهای یوتیوب -->
                <div class="tab-pane fade show active" id="content-youtube" role="tabpanel" tabindex="0">
                    <?php if(empty($tasks)): ?>
                    <div style="background:var(--fin-surface, #11161F); border:1px dashed var(--fin-border-soft, #181F2A); border-radius:20px; padding:50px 20px; text-align:center;">
                        <span class="material-icons text-muted" style="font-size:4rem; opacity:0.5;">play_circle_outline</span>
                        <h5 style="margin-top:16px; font-weight:700;">در حال حاضر ویدیوی یوتیوبی موجود نیست</h5>
                        <p style="color:var(--text-muted, #94a3b8); font-size:0.9rem; margin:0;">بعداً مراجعه کنید. ویدیوهای کارفرمایان به صورت دوره‌ای اضافه می‌شوند.</p>
                    </div>
                    <?php else: ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
                    <?php foreach($tasks as $task): ?>
                        <div style="background:var(--fin-surface, #11161F); border:1px solid var(--fin-border-soft, #181F2A); border-radius:20px; overflow:hidden; display:flex; flex-direction:column; padding:20px; box-shadow:0 8px 24px rgba(0,0,0,0.05);">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                                <span style="background:#ef4444; color:#fff; padding:4px 12px; border-radius:10px; font-weight:700; font-size:0.8rem;">YouTube</span>
                                <span style="font-weight:800; color:#10b981; font-size:1.15rem;"><?= number_format($task->reward_amount ?? $task->reward ?? 0) ?> تومان</span>
                            </div>
                            <?php if(!empty($task->youtube_url ?? $task->target_url ?? '')): ?>
                            <div style="margin-bottom:14px; text-align:center;">
                                <?php
                                $ytUrl = $task->youtube_url ?? $task->target_url ?? '';
                                preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m);
                                $videoId = $m[1] ?? null;
                                ?>
                                <?php if($videoId): ?>
                                <img src="https://img.youtube.com/vi/<?= e($videoId) ?>/mqdefault.jpg" style="width:100%; height:160px; object-fit:cover; border-radius:14px;" alt="thumbnail">
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <h6 style="font-weight:700; font-size:1.05rem; margin-bottom:8px;"><?= e(mb_substr($task->title ?? '', 0, 60)) ?></h6>
                            <p style="color:var(--text-muted, #94a3b8); font-size:0.85rem; flex-grow:1; margin-bottom:18px; line-height:1.6;"><?= e(mb_substr($task->description ?? '', 0, 80)) ?></p>
                            <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted, #94a3b8); margin-bottom:18px; border-top:1px solid var(--fin-border-soft, #181F2A); padding-top:12px;">
                                <span style="display:flex; align-items:center; gap:4px;"><span class="material-icons" style="font-size:16px;">timer</span> <?= $task->watch_duration_seconds ?? 30 ?> ثانیه</span>
                                <span style="display:flex; align-items:center; gap:4px;"><span class="material-icons" style="font-size:16px;">groups</span> <?= number_format($task->remaining_slots ?? $task->slots_remaining ?? 0) ?> جای خالی</span>
                            </div>
                            <button class="btn-start-adtube" style="background:#ef4444; color:#fff; border:none; padding:12px; border-radius:12px; font-weight:700; font-size:1rem; cursor:pointer; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; transition:all 0.2s ease;" data-id="<?= e($task->id) ?>">
                                <span class="material-icons">play_arrow</span> شروع تماشای ویدیو
                            </button>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- تب دوم: تبلیغات جایزه‌دار -->
                <div class="tab-pane fade" id="content-rewards" role="tabpanel" tabindex="0">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:22px;">
                        <?php foreach($rewardAds as $network => $ad): ?>
                        <div>
                            <div class="reward-ad-card reward-ad-card--<?= e($network) ?>">
                                <div>
                                    <div class="reward-ad__top">
                                        <span class="reward-ad__curr"><?= e($ad['currency'] === 'IRT' ? '💳 تومان (IRT)' : '₮ تتر (USDT)') ?></span>
                                        <span style="background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:10px; font-size:0.8rem; font-weight:700;">فوری • <?= $ad['duration_seconds'] ?> ثانیه</span>
                                    </div>
                                    <h3 class="reward-ad__title"><?= e($ad['title']) ?></h3>
                                    <p class="reward-ad__desc"><?= e($ad['description']) ?></p>
                                    <div class="reward-ad__payout">
                                        <?= e($ad['payout_amount']) ?> <span><?= e($ad['currency'] === 'IRT' ? 'تومان پاداش آنی' : 'USDT پاداش ارزی') ?></span>
                                    </div>
                                </div>
                                <button type="button" class="reward-ad__btn" onclick="startRewardedVideo('<?= e($network) ?>', <?= $ad['duration_seconds'] ?>)">
                                    <span class="material-icons">play_circle_outline</span> شروع تماشای ویدیوی تبلیغاتی
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- 🛡️ مودال امنیتی و شبیه‌ساز S2S -->
<div class="reward-modal-wrap" id="rewardModalWrap">
    <div class="reward-modal-box">
        <div class="reward-modal__icon" id="rewardModalIcon"><span class="material-icons" id="rewardModalIconTxt">hourglass_empty</span></div>
        <h3 class="reward-modal__title" id="rewardModalTitle">در حال پخش ویدیوی تبلیغاتی...</h3>
        <p class="reward-modal__body" id="rewardModalBody">لطفاً تا پایان شمارش معکوس صفحه را ترک نکنید. <br><strong style="font-size:1.4rem; color:#3b82f6;" id="rewardCountdown">15</strong> ثانیه باقی‌مانده</p>
        <button type="button" style="background:#3b82f6; color:#fff; border:none; padding:14px 24px; border-radius:14px; font-weight:700; font-size:1rem; width:100%; cursor:pointer; display:none;" id="rewardCloseBtn" onclick="closeRewardModal()">بستن و ادامه کسب درآمد</button>
    </div>
</div>

<script nonce="<?= e($cspNonce ?? '') ?>">
function startRewardedVideo(network, duration) {
    const modal = document.getElementById('rewardModalWrap');
    const title = document.getElementById('rewardModalTitle');
    const body = document.getElementById('rewardModalBody');
    const icon = document.getElementById('rewardModalIconTxt');
    const iconBox = document.getElementById('rewardModalIcon');
    const countTxt = document.getElementById('rewardCountdown');
    const closeBtn = document.getElementById('rewardCloseBtn');

    modal.style.setProperty('display', 'flex', 'important');
    modal.classList.add('show');
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
            body.innerHTML = 'نمایش ویدیو کامل شد. نتیجه در حال بررسی سرور به سرور (S2S) می‌باشد؛ به محض تایید نهایی، مبلغ به صورت خودکار به کیف‌پول شما واریز خواهد شد.';
            closeBtn.style.display = 'block';
        }
    }, 1000);
}
function closeRewardModal() {
    const m = document.getElementById('rewardModalWrap');
    if (m) {
        m.style.setProperty('display', 'none', 'important');
        m.classList.remove('show');
    }
}
</script>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usertasksfeed.css') . '"><link rel="stylesheet" href="' . asset('assets/css/views/useradtasks.css') . '">';
$scripts = '<script nonce="' . e($cspNonce ?? '') . '" src="' . asset('assets/js/views/useradtubeindex.js') . '"></script>';
include view_path('layouts.user');
?>
