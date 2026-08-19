<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\AdSystemManager;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\CustomTask\CustomTaskModerationService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$manager = $c->make(AdSystemManager::class);
$executor = $c->make(CustomTaskExecutorService::class);
$moderation = $c->make(CustomTaskModerationService::class);

try {
    $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTMANAGER:%')");
    $db->query("DELETE FROM escrow_transactions WHERE order_type IN ('custom_task_budget','ad_creation_custom_task') AND (order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'CTMANAGER:%') OR order_id IN (SELECT id FROM saga_executions WHERE saga_name='ad_creation_custom_task'))");
    $db->query("DELETE FROM ads WHERE title LIKE 'CTMANAGER:%'");
    $db->query("DELETE FROM users WHERE email IN ('ct_manager@example.test','ct_manager_worker@example.test')");

    $userId = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ct_manager','ct_manager@example.test','CT Manager','active','user','verified',NOW(),NOW())");
    $workerId = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ct_manager_worker','ct_manager_worker@example.test','CT Manager Worker','active','user','verified',NOW(),NOW())");
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, 1000000, 0, 0, 0, NOW(), NOW())", [$userId]);
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, 0, 0, 0, 0, NOW(), NOW())", [$workerId]);

    $result = $manager->create('custom_task', $userId, [
        'title' => 'CTMANAGER: signup task',
        'description' => 'ثبت نام در سایت نمونه و ارسال کد کاربری برای بررسی کارفرما',
        'link' => 'https://example.com/signup',
        'task_type' => 'signup',
        'proof_type' => 'code',
        'proof_description' => 'کد کاربری یا ایمیل ثبت نام را ارسال کنید',
        'price_per_task' => 10000,
        'total_count' => 3,
        'currency' => 'irt',
        'deadline_hours' => 24,
    ]);

    $adId = (int)($result['ad_id'] ?? 0);
    $ad = $adId ? $db->fetch("SELECT id,type,title,price_per_task,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,proof_type,proof_description,proof_schema,status FROM ads WHERE id=?", [$adId]) : null;
    $escrow = $adId ? $db->fetch("SELECT id,order_id,order_type,buyer_id,seller_id,amount,currency,status FROM escrow_transactions WHERE order_id=? AND order_type='custom_task_budget' ORDER BY id DESC LIMIT 1", [(string)$adId]) : null;

    $start = $executor->startTask($adId, $workerId);
    $submissionId = (int)($start['submission_id'] ?? 0);
    $proof = $submissionId ? $executor->submitProof($submissionId, $workerId, [
        'task_execution_id' => $submissionId,
        'proof_code' => 'ABC123',
        'proof_text' => 'کد ثبت نام من ABC123 است.',
        'idempotency_key' => 'CT_MANAGER_PROOF_' . $submissionId . '_' . bin2hex(random_bytes(4)),
    ]) : ['success' => false, 'message' => 'start failed'];
    $review = $submissionId ? $moderation->reviewSubmission($submissionId, $userId, 'approve') : ['success' => false, 'message' => 'no submission'];

    $adAfter = $adId ? $db->fetch("SELECT id,remaining_count,pending_count,completed_count,remaining_budget,status FROM ads WHERE id=?", [$adId]) : null;
    $submissionAfter = $submissionId ? $db->fetch("SELECT id,status,reward_paid,reward_transaction_id,proof_code,proof_text FROM custom_task_submissions WHERE id=?", [$submissionId]) : null;
    $escrowAfter = $escrow ? $db->fetch("SELECT id,amount,partial_released,status FROM escrow_transactions WHERE id=?", [(int)$escrow->id]) : null;
    $advertiserWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$userId]);
    $workerWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$workerId]);

    echo json_encode([
        'ok' => !empty($result['ad_id']) && $ad && $escrow && !empty($start['success']) && !empty($proof['success']) && !empty($review['success']) && (int)($submissionAfter->reward_paid ?? 0) === 1 && (int)($adAfter->completed_count ?? 0) === 1,
        'result' => $result,
        'ad' => $ad,
        'escrow' => $escrow,
        'start' => $start,
        'proof' => $proof,
        'review' => $review,
        'submission_after' => $submissionAfter,
        'ad_after' => $adAfter,
        'escrow_after' => $escrowAfter,
        'advertiser_wallet' => $advertiserWallet,
        'worker_wallet' => $workerWallet,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
