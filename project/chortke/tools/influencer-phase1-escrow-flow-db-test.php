<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\InfluencerService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$service = $c->make(InfluencerService::class);

function inf1_row($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }
function inf1_user(Database $db, string $suffix): int {
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())",
        ["inf_phase1_{$suffix}", "inf_phase1_{$suffix}@example.test", "Influencer Phase1 {$suffix}", 'active', 'user', 'verified']
    );
}
function inf1_wallet(Database $db, int $userId, float $irt): void {
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,?,?,?,0,NOW(),NOW())", [$userId, $irt, 0, 0]);
}
function inf1_profile(Database $db, int $userId, string $username, float $storyPrice = 100000): int {
    return (int)$db->insert(
        "INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,post_price_24h,post_price_48h,post_price_72h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,?,?,?,?, 'irt', NOW(), NOW())",
        [$userId, $username, 'instagram', 25000, 25000, $storyPrice, $storyPrice, $storyPrice * 1.5, $storyPrice * 2]
    );
}

try {
    $db->query("DELETE FROM outbox_events WHERE aggregate_type IN ('influencer_order','escrow','general') AND (payload LIKE '%INF-PHASE1%' OR event_type LIKE 'influencer.%')");
    $db->query("DELETE FROM escrow_audit WHERE escrow_id IN (SELECT id FROM escrow_transactions WHERE order_type='influencer_order')");
    $db->query("DELETE FROM escrow_transactions WHERE order_type='influencer_order'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%INF-PHASE1%' OR metadata LIKE '%story_payout_%' OR metadata LIKE '%story_refund_%' OR metadata LIKE '%influencer_escrow%' OR ref_type='influencer_order'");
    $db->query("DELETE FROM story_orders WHERE caption LIKE 'INF-PHASE1:%'");
    $db->query("DELETE FROM influencer_profiles WHERE username LIKE 'inf_phase1_%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'inf_phase1_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'inf_phase1_%@example.test'");

    // Successful flow: create order -> accept -> submit proof -> buyer confirm -> payout once.
    $buyerId = inf1_user($db, 'buyer_' . time());
    $influencerUserId = inf1_user($db, 'creator_' . time());
    inf1_wallet($db, $buyerId, 200000);
    inf1_wallet($db, $influencerUserId, 0);
    $profileId = inf1_profile($db, $influencerUserId, 'inf_phase1_creator_' . time(), 100000);

    $create = $service->createOrder($buyerId, $profileId, [
        'order_type' => 'story',
        'duration_hours' => 24,
        'caption' => 'INF-PHASE1: successful escrow order',
        'link' => 'https://example.test/campaign',
    ]);
    if (empty($create['success'])) throw new RuntimeException('create failed: ' . ($create['message'] ?? ''));
    $orderId = (int)$create['order']->id;
    $orderAfterCreate = $db->fetch("SELECT * FROM story_orders WHERE id=?", [$orderId]);
    $escrowAfterCreate = $db->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [$orderId]);
    $buyerWalletAfterCreate = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$buyerId]);

    $accept = $service->respondToOrder($orderId, $influencerUserId, 'accept');
    $proof = $service->submitProof($orderId, $influencerUserId, ['proof_link' => 'https://instagram.example/story-proof', 'proof_notes' => 'INF-PHASE1 proof']);
    $confirm = $service->buyerConfirm($orderId, $buyerId);
    $confirmAgain = $service->buyerConfirm($orderId, $buyerId);

    $orderAfterComplete = $db->fetch("SELECT * FROM story_orders WHERE id=?", [$orderId]);
    $escrowAfterComplete = $db->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [$orderId]);
    $buyerWalletAfterComplete = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$buyerId]);
    $sellerWalletAfterComplete = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$influencerUserId]);
    $payoutTxCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM transactions WHERE user_id=? AND metadata LIKE ?", [$influencerUserId, '%story_payout_' . $orderId . '%']);

    // Reject/refund flow: create order -> influencer reject -> buyer hold cancelled exactly once.
    $buyer2 = inf1_user($db, 'buyer_reject_' . time());
    $creator2 = inf1_user($db, 'creator_reject_' . time());
    inf1_wallet($db, $buyer2, 150000);
    inf1_wallet($db, $creator2, 0);
    $profile2 = inf1_profile($db, $creator2, 'inf_phase1_reject_' . time(), 80000);
    $createReject = $service->createOrder($buyer2, $profile2, [
        'order_type' => 'story',
        'duration_hours' => 24,
        'caption' => 'INF-PHASE1: rejected escrow order',
    ]);
    if (empty($createReject['success'])) throw new RuntimeException('create reject failed: ' . ($createReject['message'] ?? ''));
    $rejectOrderId = (int)$createReject['order']->id;
    $reject = $service->respondToOrder($rejectOrderId, $creator2, 'reject', 'نمیتوانم در زمان موردنظر منتشر کنم');
    $rejectOrder = $db->fetch("SELECT * FROM story_orders WHERE id=?", [$rejectOrderId]);
    $rejectEscrow = $db->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [$rejectOrderId]);
    $buyer2Wallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$buyer2]);

    $ok = !empty($create['success'])
        && ($orderAfterCreate->status ?? '') === 'pending_acceptance'
        && ($escrowAfterCreate->status ?? '') === 'in_escrow'
        && abs((float)$buyerWalletAfterCreate->balance_irt - 100000.0) < 0.0001
        && abs((float)$buyerWalletAfterCreate->locked_irt - 100000.0) < 0.0001
        && !empty($accept['success'])
        && !empty($proof['success'])
        && !empty($confirm['success'])
        && !empty($confirmAgain['success'])
        && ($confirmAgain['data']['already_completed'] ?? false) === true
        && ($orderAfterComplete->status ?? '') === 'completed'
        && ($escrowAfterComplete->status ?? '') === 'released'
        && abs((float)$buyerWalletAfterComplete->balance_irt - 100000.0) < 0.0001
        && abs((float)$buyerWalletAfterComplete->locked_irt) < 0.0001
        && abs((float)$sellerWalletAfterComplete->balance_irt - 85000.0) < 0.0001
        && $payoutTxCount === 1
        && !empty($reject['success'])
        && ($rejectOrder->status ?? '') === 'refunded'
        && ($rejectEscrow->status ?? '') === 'refunded'
        && abs((float)$buyer2Wallet->balance_irt - 150000.0) < 0.0001
        && abs((float)$buyer2Wallet->locked_irt) < 0.0001;

    echo json_encode([
        'ok' => $ok,
        'create' => $create,
        'order_after_create' => inf1_row($orderAfterCreate),
        'escrow_after_create' => inf1_row($escrowAfterCreate),
        'buyer_wallet_after_create' => inf1_row($buyerWalletAfterCreate),
        'accept' => $accept,
        'proof' => $proof,
        'confirm' => $confirm,
        'confirm_again' => $confirmAgain,
        'order_after_complete' => inf1_row($orderAfterComplete),
        'escrow_after_complete' => inf1_row($escrowAfterComplete),
        'buyer_wallet_after_complete' => inf1_row($buyerWalletAfterComplete),
        'seller_wallet_after_complete' => inf1_row($sellerWalletAfterComplete),
        'payout_tx_count' => $payoutTxCount,
        'reject' => $reject,
        'reject_order' => inf1_row($rejectOrder),
        'reject_escrow' => inf1_row($rejectEscrow),
        'buyer2_wallet' => inf1_row($buyer2Wallet),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
