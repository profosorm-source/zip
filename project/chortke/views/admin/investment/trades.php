<?php
$title  = 'تاریخچه تریدها';

ob_start();
?>
<div id="investmentRoot" data-base="<?= url('/admin/investment') ?>" data-withdrawals-base="<?= url('/admin/investment/withdrawals') ?>" data-apply-url="<?= url('/admin/investment/apply-profit') ?>" data-trades-base="<?= url('/admin/investment/trades') ?>" data-trades-store="<?= url('/admin/investment/trades/store') ?>" data-trades-list="<?= url('/admin/investment/trades') ?>"></div>
<?php
$trades      = $trades      ?? [];
$stats       = $stats       ?? null;
$total       = $total       ?? 0;
$totalPages  = $totalPages  ?? 1;
$currentPage = $currentPage ?? 1;
$filters     = $filters     ?? [];

$statusQ    = $filters['status']    ?? '';
$directionQ = $filters['direction'] ?? '';
?>


<?php
// محاسبه نسبت‌ها برای ticker
$winRate = ($stats && ($stats->total ?? 0) > 0)
    ? round(($stats->profit_count / $stats->total) * 100, 1)
    : 0;
$lossRate = 100 - $winRate;
?>

<!-- ══ TICKER ══ -->
<div class="tr-ticker">
    <div class="tr-ticker-track" id="tickerTrack">
        <?php
        $tickerItems = [
            ['BTC/USDT','43,250.00','+2.4','up'],
            ['ETH/USDT','2,280.50','+1.8','up'],
            ['BNB/USDT','312.40','-0.6','dn'],
            ['SOL/USDT','98.75','+4.2','up'],
            ['XRP/USDT','0.5820','-1.1','dn'],
            ['ADA/USDT','0.4420','+0.9','up'],
            ['DOGE/USDT','0.0820','+3.1','up'],
            ['TRX/USDT','0.1050','-0.3','dn'],
        ];
         $tickerAll = array_merge($tickerItems, $tickerItems); foreach($tickerAll as $ti): ?>
        <span class="tr-ticker-item">
            <span class="sym"><?= $ti[0] ?></span>
            <span class="prc"><?= $ti[1] ?></span>
            <span class="chg <?= $ti[3] ?>"><?= $ti[3]==='up' ? '▲' : '▼' ?> <?= $ti[2] ?>%</span>
        </span>
        <span class="tr-ticker-sep">|</span>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ HERO ══ -->
<div class="tr-hero">
    <div class="tr-hero-left">
        <div class="tr-hero-icon">
            <span class="material-icons">candlestick_chart</span>
        </div>
        <div>
            <h1 class="tr-hero-title">تاریخچه تریدها</h1>
            <p class="tr-hero-sub">مدیریت و نظارت بر معاملات — <?= number_format($total) ?> ترید ثبت‌شده</p>
        </div>
    </div>
    <div class="tr-hero-right">
        <a href="<?= url('/admin/investment/trades/create') ?>" class="tr-btn tr-btn-primary">
            <span class="material-icons">add_chart</span>
            ثبت ترید جدید
        </a>
        <a href="<?= url('/admin/investment') ?>" class="tr-btn tr-btn-secondary">
            <span class="material-icons">arrow_forward</span>
            بازگشت
        </a>
    </div>
</div>

<!-- ══ STATS ══ -->
<?php if ($stats): ?>
<div class="tr-stats">
    <!-- مجموع -->
    <div class="tr-stat">
        <div class="tr-stat-glow"></div>
        <div class="tr-stat-icon"><span class="material-icons">bar_chart</span></div>
        <div class="tr-stat-label">مجموع تریدها</div>
        <div class="tr-stat-val gold"><?= number_format((int)($stats->total ?? 0)) ?></div>
    </div>
    <!-- باز -->
    <div class="tr-stat">
        <div class="tr-stat-glow"></div>
        <div class="tr-stat-icon"><span class="material-icons">pending</span></div>
        <div class="tr-stat-label">باز</div>
        <div class="tr-stat-val"><?= number_format((int)($stats->open_count ?? 0)) ?></div>
    </div>
    <!-- سودده -->
    <div class="tr-stat">
        <div class="tr-stat-glow"></div>
        <div class="tr-stat-icon"><span class="material-icons">trending_up</span></div>
        <div class="tr-stat-label">سودده</div>
        <div class="tr-stat-val up"><?= number_format((int)($stats->profit_count ?? 0)) ?></div>
    </div>
    <!-- ضررده -->
    <div class="tr-stat">
        <div class="tr-stat-glow"></div>
        <div class="tr-stat-icon"><span class="material-icons">trending_down</span></div>
        <div class="tr-stat-label">ضررده</div>
        <div class="tr-stat-val dn"><?= number_format((int)($stats->loss_count ?? 0)) ?></div>
    </div>
    <!-- win rate -->
    <div class="tr-stat">
        <div class="tr-stat-glow"></div>
        <div class="tr-stat-icon"><span class="material-icons">percent</span></div>
        <div class="tr-stat-label">Win Rate</div>
        <div class="tr-stat-val gold"><?= $winRate ?>%</div>
    </div>
</div>
<?php endif; ?>

<!-- ══ FILTER ══ -->
<div class="tr-filter">
    <span class="tr-filter-label">فیلتر:</span>

    <!-- وضعیت -->
    <div class="tr-seg">
        <?php
        $sBase = url('/admin/investment/trades') . ($directionQ ? '?direction='.$directionQ : '');
        ?>
        <a href="<?= url('/admin/investment/trades') ?><?= $directionQ ? '?direction='.$directionQ : '' ?>"
           class="tr-seg-btn <?= !$statusQ ? 'active' : '' ?>">همه</a>
        <a href="<?= $sBase . ($directionQ ? '&' : '?') ?>status=open"
           class="tr-seg-btn <?= $statusQ==='open' ? 'active' : '' ?>">
            <span class="material-icons">pending</span>باز
        </a>
        <a href="<?= $sBase . ($directionQ ? '&' : '?') ?>status=closed"
           class="tr-seg-btn <?= $statusQ==='closed' ? 'active' : '' ?>">
            <span class="material-icons">check_circle</span>بسته
        </a>
    </div>

    <span >|</span>

    <!-- جهت -->
    <div class="tr-seg">
        <?php
        $dBase = url('/admin/investment/trades') . ($statusQ ? '?status='.$statusQ : '');
        ?>
        <a href="<?= url('/admin/investment/trades') ?><?= $statusQ ? '?status='.$statusQ : '' ?>"
           class="tr-seg-btn <?= !$directionQ ? 'active' : '' ?>">همه جهت‌ها</a>
        <a href="<?= $dBase . ($statusQ ? '&' : '?') ?>direction=buy"
           class="tr-seg-btn <?= $directionQ==='buy' ? 'active-buy' : '' ?>">
            <span class="material-icons">arrow_upward</span>خرید
        </a>
        <a href="<?= $dBase . ($statusQ ? '&' : '?') ?>direction=sell"
           class="tr-seg-btn <?= $directionQ==='sell' ? 'active-sell' : '' ?>">
            <span class="material-icons">arrow_downward</span>فروش
        </a>
    </div>
</div>

<!-- ══ TABLE ══ -->
<div class="tr-table-wrap">
    <div class="tr-table-head">
        <h3>
            <span class="material-icons">format_list_bulleted</span>
            لیست تریدها
        </h3>
        <span class="tr-count-badge"><?= number_format($total) ?> ترید</span>
    </div>

    <?php if (empty($trades)): ?>
    <div class="tr-empty">
        <div class="tr-empty-icon"><span class="material-icons">candlestick_chart</span></div>
        <h3>هیچ ترید‌ای یافت نشد</h3>
        <p>با فیلتر انتخابی نتیجه‌ای وجود ندارد</p>
    </div>
    <?php else: ?>
    <div >
        <table class="tr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>جفت ارز</th>
                    <th>جهت</th>
                    <th>وضعیت</th>
                    <th>قیمت باز</th>
                    <th>قیمت بسته</th>
                    <th>سود/ضرر (USDT)</th>
                    <th>تاریخ باز</th>
                    <th>توضیح</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trades as $t):
                    $pnl    = (float)($t->profit_loss_amount ?? 0);
                    $isUp   = $pnl > 0;
                    $isZero = $pnl == 0;
                    $dir    = strtolower($t->direction ?? 'buy');
                    $status = strtolower($t->status ?? 'open');
                    $pair   = $t->trading_pair ?? ($t->symbol ?? 'BTC/USDT');
                    $sym    = explode('/', $pair)[0] ?? 'BTC';
                ?>
                <tr>
                    <td><span class="tr-id">#<?= (int)$t->id ?></span></td>
                    <td>
                        <div class="tr-pair">
                            <div class="tr-pair-icon"><?= e(mb_substr($sym,0,1)) ?></div>
                            <?= e($pair) ?>
                        </div>
                    </td>
                    <td>
                        <span class="tr-dir <?= $dir ?>">
                            <span class="material-icons"><?= $dir==='buy' ? 'arrow_upward' : 'arrow_downward' ?></span>
                            <?= $dir==='buy' ? 'خرید' : 'فروش' ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $stIco = ['open'=>'pending','closed'=>'check_circle','stopped'=>'cancel'];
                        $stLbl = ['open'=>'باز','closed'=>'بسته','stopped'=>'متوقف'];
                        ?>
                        <span class="tr-status <?= $status ?>">
                            <span class="material-icons"><?= $stIco[$status] ?? 'help' ?></span>
                            <?= $stLbl[$status] ?? $status ?>
                        </span>
                    </td>
                    <td><span class="tr-price"><?= number_format((float)($t->open_price ?? 0), 4) ?></span></td>
                    <td><span class="tr-price"><?= $t->close_price ? number_format((float)$t->close_price, 4) : '—' ?></span></td>
                    <td>
                        <span class="tr-pnl <?= $isZero ? 'zero' : ($isUp ? 'up' : 'dn') ?>">
                            <span class="material-icons"><?= $isZero ? 'remove' : ($isUp ? 'arrow_drop_up' : 'arrow_drop_down') ?></span>
                            <?= $isUp ? '+' : '' ?><?= number_format($pnl, 4) ?>
                        </span>
                    </td>
                    <td><span class="tr-date"><?= to_jalali($t->open_time ?? $t->created_at) ?></span></td>
                    <td><span class="tr-note" title="<?= e($t->note ?? $t->description ?? '') ?>"><?= e($t->note ?? $t->description ?? '—') ?></span></td>
                    <td>
                        <?php if ($status === 'open'): ?>
                        <button class="tr-close-btn" data-click="openCloseModal" data-args="<?= (int)$t->id ?>|<?= e(addslashes($pair)) ?>">
                            <span class="material-icons">close</span>
                            بستن
                        </button>
                        <?php else: ?>
                        <span >—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="tr-pagination">
        <?php if ($currentPage > 1): ?>
        <a href="?page=<?= $currentPage-1 ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>" class="tr-page-btn">
            <span class="material-icons">chevron_right</span>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1,$currentPage-2); $i <= min($totalPages,$currentPage+2); $i++): ?>
        <a href="?page=<?= $i ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>"
           class="tr-page-btn <?= $i===$currentPage ? 'active' : '' ?>">
            <?= fa_number($i) ?>
        </a>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?>
        <a href="?page=<?= $currentPage+1 ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>" class="tr-page-btn">
            <span class="material-icons">chevron_left</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ══ MODAL بستن ترید ══ -->
<div class="tr-modal-overlay" id="closeOverlay">
    <div class="tr-modal">
        <div class="tr-modal-head">
            <div class="tr-modal-title">
                <span class="material-icons">close</span>
                بستن ترید
            </div>
            <button class="tr-modal-x" data-click="closeModal">
                <span class="material-icons">close</span>
            </button>
        </div>
        <div class="tr-modal-body">
            <p id="closeModalDesc">در حال بستن ترید...</p>
            <div class="tr-input-wrap">
                <label>قیمت بستن (اختیاری)</label>
                <input type="number" id="closePrice" class="tr-input" step="0.0001" placeholder="مثال: 43250.0000">
            </div>
            <div class="tr-input-wrap">
                <label>مقدار سود/ضرر (USDT)</label>
                <input type="number" id="closePnl" class="tr-input" step="0.0001" placeholder="مثبت=سود، منفی=ضرر">
            </div>
            <div class="tr-input-wrap">
                <label>توضیح</label>
                <input type="text" id="closeNote" class="tr-input" placeholder="دلیل بستن...">
            </div>
        </div>
        <div class="tr-modal-foot">
            <button class="tr-btn tr-btn-cancel-sm" data-click="closeModal">
                <span class="material-icons">close</span> انصراف
            </button>
            <button class="tr-btn tr-btn-danger" id="closeConfirmBtn" data-click="doClose">
                <span class="material-icons">check</span> بستن ترید
            </button>
        </div>
    </div>
</div>

<!-- Toasts -->
<div class="tr-toasts" id="trToasts"></div>




$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/admininvestmenttrades.css') . '">';
$content = ob_get_clean(); include view_path('layouts.admin'); ?>
