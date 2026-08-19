<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\AdSystemManager;
use App\Services\SocialTask\SocialTaskService;
use App\Jobs\SocialTask\ApproveSocialTaskExecutionJob;

$c = Container::getInstance();
$db = $c->make(Database::class);
$manager = $c->make(AdSystemManager::class);
$social = $c->make(SocialTaskService::class);
$approveJob = $c->make(ApproveSocialTaskExecutionJob::class);

function s6user(Database $db, string $name): int {
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",
        [$name, $name . '@example.test', $name]
    );
}
function s6wallet(Database $db, int $userId, float $balance): void {
    $db->insert(
        "INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",
        [$userId, $balance]
    );
}
function s6arr($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM social_camera_requests WHERE execution_id IN (SELECT id FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS6SOC:%'))");
    $db->query("DELETE FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS6SOC:%')");
    $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADS6SOC:%')");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%ADS6SOC%' OR description LIKE '%ADS6SOC%'");
    $db->query("DELETE FROM ads WHERE title LIKE 'ADS6SOC:%'");
    $db->query("DELETE FROM users WHERE email LIKE 'ads6soc_%@example.test'");

    $adv = s6user($db, 'ads6soc_adv');
    $worker = s6user($db, 'ads6soc_worker');
    s6wallet($db, $adv, 100000);
    s6wallet($db, $worker, 0);

    $create = $manager->create('social_task', $adv, [
        'title' => 'ADS6SOC: escrow social',
        'platform' => 'instagram',
        'task_type' => 'follow',
        'target_link' => 'https://instagram.com/example',
        'price_per_task' => 1000,
        'total_count' => 3,
        'currency' => 'irt',
    ]);
    $adId = (int)$create['ad_id'];
    $db->query("UPDATE ads SET status='active', is_active=1 WHERE id=?", [$adId]);
    $escrowBefore = $db->fetch("SELECT id,amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='social_task_budget'", [(string)$adId]);
    $advBefore = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$adv]);

    $start = $social->startExecution($worker, $adId, ['ip' => '127.0.0.1', 'user_agent' => 'ADS6SOC']);
    if (empty($start['success'])) {
        throw new RuntimeException('social start failed: ' . json_encode($start, JSON_UNESCAPED_UNICODE));
    }
    $execId = (int)$start['execution_id'];
    $db->query("UPDATE social_task_executions SET status='submitted', submitted_at=NOW(), updated_at=NOW() WHERE id=?", [$execId]);
    $approve = $approveJob->handle($adv, $execId);

    $exec = $db->fetch("SELECT id,status,reward_paid,reward_amount FROM social_task_executions WHERE id=?", [$execId]);
    $ad = $db->fetch("SELECT id,status,remaining_budget,remaining_count,pending_count,completed_count FROM ads WHERE id=?", [$adId]);
    $escrowAfter = $db->fetch("SELECT id,amount,partial_released,status FROM escrow_transactions WHERE id=?", [(int)$escrowBefore->id]);
    $wallets = $db->fetch("SELECT
        (SELECT balance_irt FROM wallets WHERE user_id=?) AS worker_balance,
        (SELECT locked_irt FROM wallets WHERE user_id=?) AS adv_locked,
        (SELECT balance_irt FROM wallets WHERE user_id=?) AS adv_balance",
        [$worker, $adv, $adv]
    );

    $ok = !empty($approve['success'])
        && (string)$exec->status === 'approved'
        && (int)$exec->reward_paid === 1
        && abs((float)$wallets->worker_balance - 1000.0) < 0.0001
        && abs((float)$escrowAfter->partial_released - 1000.0) < 0.0001
        && abs((float)$escrowAfter->amount - ((float)$escrowBefore->amount - 1000.0)) < 0.0001
        && abs((float)$wallets->adv_locked - ((float)$advBefore->locked_irt - 1000.0)) < 0.0001
        && (int)$ad->completed_count === 1
        && (int)$ad->pending_count === 0;

    echo json_encode([
        'ok' => $ok,
        'create' => $create,
        'start' => $start,
        'approve' => $approve,
        'execution' => s6arr($exec),
        'ad' => s6arr($ad),
        'escrow_before' => s6arr($escrowBefore),
        'escrow_after' => s6arr($escrowAfter),
        'wallets_before' => s6arr($advBefore),
        'wallets_after' => s6arr($wallets),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
