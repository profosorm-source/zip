<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$influencer = $c->make(InfluencerService::class);
$disputes = $c->make(DisputeService::class);

function rowx($o){ return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }
function u(Database $db,string $s,string $role='user'): int { return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_dispute_$s", "inf_dispute_$s@example.test", "Inf Dispute $s", 'active', $role, 'verified']); }
function w(Database $db,int $u,float $b): void { $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?, 0, 0, 0, NOW(), NOW())", [$u,$b]); }
function p(Database $db,int $u,string $name,float $price): int { return (int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'verified',1,?,'irt',NOW(),NOW())", [$u,$name,'instagram',10000,10000,$price]); }

try {
    $db->query("DELETE FROM escrow_audit WHERE escrow_id IN (SELECT id FROM escrow_transactions WHERE order_type='influencer_order')");
    $db->query("DELETE FROM escrow_transactions WHERE order_type='influencer_order'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%inf_dispute%' OR metadata LIKE '%story_payout_%' OR metadata LIKE '%influencer_escrow%' OR ref_type='influencer_order'");
    $db->query("DELETE FROM disputes WHERE ref_type IN ('influencer_order','story_order','order')");
    $db->query("DELETE FROM story_orders WHERE caption LIKE 'INF-DISPUTE:%'");
    $db->query("DELETE FROM influencer_profiles WHERE username LIKE 'inf_dispute_%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'inf_dispute_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'inf_dispute_%@example.test'");

    $buyer = u($db, 'buyer_' . time());
    $creator = u($db, 'creator_' . time());
    $admin = u($db, 'admin_' . time(), 'admin');
    w($db, $buyer, 100000); w($db, $creator, 0); w($db, $admin, 0);
    $profile = p($db, $creator, 'inf_dispute_page_' . time(), 60000);

    $create = $influencer->createOrder($buyer, $profile, ['order_type'=>'story','duration_hours'=>24,'caption'=>'INF-DISPUTE: order']);
    if (empty($create['success'])) throw new RuntimeException($create['message'] ?? 'create failed');
    $orderId = (int)$create['order']->id;
    $influencer->respondToOrder($orderId, $creator, 'accept');
    $influencer->submitProof($orderId, $creator, ['proof_link'=>'https://instagram.example/proof']);
    $dispute = $influencer->buyerDispute($orderId, $buyer, 'مدرک مورد قبول نیست');
    if (empty($dispute['success'])) throw new RuntimeException($dispute['message'] ?? 'dispute failed');
    $disputeRow = $db->fetch("SELECT * FROM disputes WHERE ref_type='influencer_order' AND ref_id=? ORDER BY id DESC LIMIT 1", [$orderId]);
    if (!$disputeRow) throw new RuntimeException('dispute row missing');

    $resolve = $disputes->adminResolve((int)$disputeRow->id, $admin, 'favor_customer', 'مدرک سفارش کافی نبود و مبلغ باید بازگردد.', 100);
    $order = $db->fetch("SELECT * FROM story_orders WHERE id=?", [$orderId]);
    $escrow = $db->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='influencer_order'", [$orderId]);
    $buyerWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$buyer]);
    $creatorWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$creator]);

    $ok = !empty($resolve['success'])
        && ($order->status ?? '') === 'refunded'
        && ($escrow->status ?? '') === 'refunded'
        && abs((float)$buyerWallet->balance_irt - 100000.0) < 0.0001
        && abs((float)$buyerWallet->locked_irt) < 0.0001
        && abs((float)$creatorWallet->balance_irt) < 0.0001;

    echo json_encode(['ok'=>$ok,'resolve'=>$resolve,'order'=>rowx($order),'escrow'=>rowx($escrow),'buyer_wallet'=>rowx($buyerWallet),'creator_wallet'=>rowx($creatorWallet)], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
