<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\AdSystemManager;
use App\Services\SocialTask\SocialTaskService;
use App\Services\Ads\AdsBudgetSettlementService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$manager = $c->make(AdSystemManager::class);
$social = $c->make(SocialTaskService::class);
$settlement = $c->make(AdsBudgetSettlementService::class);

function a7user(Database $db, string $name): int {
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",
        [$name, $name . '@example.test', $name]
    );
}
function a7wallet(Database $db, int $userId, float $balance, float $locked = 0): void {
    $db->insert(
        "INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,?,0,NOW(),NOW())",
        [$userId, $balance, $locked]
    );
}
function a7arr($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }
function socialCreate(AdSystemManager $manager, int $adv, string $title): int {
    $res = $manager->create('social_task', $adv, [
        'title' => $title,
        'platform' => 'instagram',
        'task_type' => 'follow',
        'target_link' => 'https://instagram.com/example',
        'price_per_task' => 1000,
        'total_count' => 3,
        'currency' => 'irt',
    ]);
    $adId = (int)$res['ad_id'];
    \Core\Container::getInstance()->make(\Core\Database::class)->query("UPDATE ads SET status='active', is_active=1 WHERE id=?", [$adId]);
    return $adId;
}

try {
    $db->query("DELETE FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS7:%')");
    $db->query("DELETE FROM ad_delivery_events WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS7:%')");
    $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADS7:%')");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%ADS7%' OR description LIKE '%ADS7%'");
    $db->query("DELETE FROM ads WHERE title LIKE 'ADS7:%'");
    $db->query("DELETE FROM users WHERE email LIKE 'ads7_%@example.test'");

    $adv = a7user($db, 'ads7_adv');
    a7wallet($db, $adv, 300000, 0);

    // 1) Social admin reject delegates to unified settlement and refunds escrow.
    $rejectAd = socialCreate($manager, $adv, 'ADS7: social reject');
    $rejectRes = $social->adminRejectAd(1, $rejectAd, 'phase7 social reject');
    $rejectRows = [
        'ad' => $db->fetch("SELECT id,status,is_active,remaining_budget FROM ads WHERE id=?", [$rejectAd]),
        'escrow' => $db->fetch("SELECT amount,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='social_task_budget'", [(string)$rejectAd]),
        'wallet' => $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$adv]),
    ];

    // 2) Social admin pause/resume delegates to unified action.
    $toggleAd = socialCreate($manager, $adv, 'ADS7: social toggle');
    $pause = $social->adminChangeAdStatus(1, $toggleAd, 'paused');
    $pausedAd = $db->fetch("SELECT status,is_active FROM ads WHERE id=?", [$toggleAd]);
    $resume = $social->adminChangeAdStatus(1, $toggleAd, 'active');
    $resumedAd = $db->fetch("SELECT status,is_active FROM ads WHERE id=?", [$toggleAd]);

    // 3) Social admin cancel delegates to unified refund.
    $cancelAd = socialCreate($manager, $adv, 'ADS7: social cancel');
    $cancelRes = $social->adminCancelAd(1, $cancelAd);
    $cancelRows = [
        'ad' => $db->fetch("SELECT id,status,is_active,remaining_budget FROM ads WHERE id=?", [$cancelAd]),
        'escrow' => $db->fetch("SELECT amount,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='social_task_budget'", [(string)$cancelAd]),
        'wallet' => $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$adv]),
    ];

    // 4) SEO lifecycle: remaining budget below min_payout must be reconciled and refunded.
    $seoUser = a7user($db, 'ads7_seo');
    a7wallet($db, $seoUser, 0, 575);
    $seoAd = (int)$db->insert(
        "INSERT INTO ads (user_id,title,description,type,site_url,target_url,keyword,budget,total_budget,remaining_budget,min_payout,max_payout,status,is_active,currency,created_at,updated_at)
         VALUES (?, 'ADS7: seo min payout remainder', 'phase7 seo', 'seo', 'https://example.com', 'https://example.com', 'ads7', 5000, 5000, 500, 1000, 3000, 'active', 1, 'irt', NOW(), NOW())",
        [$seoUser]
    );
    $db->insert(
        "INSERT INTO escrow_transactions (order_id,order_type,buyer_id,seller_id,amount,currency,status,held_at,partial_released,created_at,updated_at)
         VALUES (?, 'seo_ad_budget', ?, ?, 575, 'irt', 'partial', NOW(), 4500, NOW(), NOW())",
        [(string)$seoAd, $seoUser, $seoUser]
    );
    $reconcile = $settlement->reconcileLifecycle(50);
    $seoRows = [
        'ad' => $db->fetch("SELECT id,status,is_active,remaining_budget FROM ads WHERE id=?", [$seoAd]),
        'escrow' => $db->fetch("SELECT amount,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='seo_ad_budget'", [(string)$seoAd]),
        'wallet' => $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$seoUser]),
    ];

    $ok = !empty($rejectRes['success'])
        && (string)$rejectRows['ad']->status === 'rejected'
        && (float)$rejectRows['ad']->remaining_budget === 0.0
        && (string)$rejectRows['escrow']->status === 'refunded'
        && (float)$rejectRows['wallet']->locked_irt === 0.0
        && !empty($pause['success']) && (string)$pausedAd->status === 'paused' && (int)$pausedAd->is_active === 0
        && !empty($resume['success']) && (string)$resumedAd->status === 'active' && (int)$resumedAd->is_active === 1
        && !empty($cancelRes['success'])
        && (string)$cancelRows['ad']->status === 'cancelled'
        && (float)$cancelRows['ad']->remaining_budget === 0.0
        && (string)$cancelRows['escrow']->status === 'refunded'
        && (float)$cancelRows['wallet']->locked_irt === 0.0
        && (string)$seoRows['ad']->status === 'completed'
        && (float)$seoRows['ad']->remaining_budget === 0.0
        && (string)$seoRows['escrow']->status === 'refunded'
        && abs((float)$seoRows['wallet']->balance_irt - 575.0) < 0.0001
        && abs((float)$seoRows['wallet']->locked_irt) < 0.0001;

    echo json_encode([
        'ok' => $ok,
        'social_reject' => ['result' => $rejectRes, 'rows' => array_map('a7arr', $rejectRows)],
        'social_toggle' => ['pause' => $pause, 'paused_ad' => a7arr($pausedAd), 'resume' => $resume, 'resumed_ad' => a7arr($resumedAd)],
        'social_cancel' => ['result' => $cancelRes, 'rows' => array_map('a7arr', $cancelRows)],
        'seo_reconcile' => ['result' => $reconcile, 'rows' => array_map('a7arr', $seoRows)],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
