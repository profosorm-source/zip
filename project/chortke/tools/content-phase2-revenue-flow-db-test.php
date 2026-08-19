<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\ContentService;
use App\Models\ContentRevenue;

$c = Container::getInstance();
$db = $c->make(Database::class);
$service = $c->make(ContentService::class);
$revenueModel = $c->make(ContentRevenue::class);

function cp2arr($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM outbox_events WHERE aggregate_type IN ('content','content_revenue') AND (payload LIKE '%CP2:%' OR aggregate_id IN (SELECT CAST(id AS CHAR) FROM content_revenues WHERE period='2026-06'))");
    $db->query("DELETE FROM content_revenues WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase2_%@example.test') OR period='2026-06'");
    $db->query("DELETE FROM content_agreements WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase2_%@example.test')");
    $db->query("DELETE FROM content_submissions WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase2_%@example.test') OR title LIKE 'CP2:%'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%content_revenue_payment_%' OR description LIKE '%درآمد محتوا - دوره 2026-06%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase2_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'content_phase2_%@example.test'");

    $userId = (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('content_phase2_user','content_phase2_user@example.test','Content Phase2 User','active','user','verified',NOW(),NOW())"
    );
    $adminId = (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('content_phase2_admin','content_phase2_admin@example.test','Content Phase2 Admin','active','super_admin','verified',NOW(),NOW())"
    );
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$userId]);

    $submit = $service->submitContent($userId, [
        'platform' => 'youtube',
        'video_url' => 'https://www.youtube.com/watch?v=CP2' . time(),
        'title' => 'CP2: محتوای تست درآمد',
        'description' => 'تست چرخه درآمد محتوا',
        'category' => 'education',
        'agreement_accepted' => 1,
    ]);
    $submissionId = (int)($submit['data']['submission_id'] ?? 0);
    if ($submissionId <= 0) throw new RuntimeException('submit failed');

    $approve = $service->approveSubmission($submissionId, $adminId);
    // Simulate content old enough for revenue eligibility.
    $db->query("UPDATE content_submissions SET approved_at = DATE_SUB(NOW(), INTERVAL 3 MONTH) WHERE id = ?", [$submissionId]);
    $publish = $service->publishSubmission($submissionId, $adminId, 'https://www.youtube.com/watch?v=CP2published');

    $createRevenue = $service->createRevenue([
        'submission_id' => $submissionId,
        'period' => '2026-06',
        'total_revenue' => 100000,
        'views' => 12000,
    ], $adminId, 'CP2_REVENUE_' . $submissionId);
    $revenueId = (int)($createRevenue['data']['revenue_id'] ?? 0);
    if ($revenueId <= 0) throw new RuntimeException('create revenue failed');

    $revenueBefore = $revenueModel->find($revenueId);
    $approved = $revenueModel->update($revenueId, [
        'status' => ContentRevenue::STATUS_APPROVED,
        'reviewed_by' => $adminId,
        'reviewed_at' => date('Y-m-d H:i:s'),
    ]);
    $pay = $service->payRevenue($revenueId, $adminId);
    $walletAfterPay = $db->fetch("SELECT balance_irt,balance_usdt,locked_irt FROM wallets WHERE user_id=?", [$userId]);
    $revenueAfterPay = $revenueModel->find($revenueId);
    $payAgain = $service->payRevenue($revenueId, $adminId);
    $walletAfterSecondPay = $db->fetch("SELECT balance_irt,balance_usdt,locked_irt FROM wallets WHERE user_id=?", [$userId]);
    $txCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM transactions WHERE user_id=? AND metadata LIKE ?", [$userId, '%content_revenue_payment_' . $revenueId . '%']);

    $expectedNet = 54600.0; // 100000 * 60% - 9% tax on user share
    $ok = !empty($submit['success'])
        && !empty($approve['success'])
        && !empty($publish['success'])
        && !empty($createRevenue['success'])
        && $approved
        && !empty($pay['success'])
        && !empty($payAgain['success'])
        && !empty($payAgain['data']['already_paid'])
        && abs((float)$revenueBefore->total_revenue - 100000.0) < 0.0001
        && abs((float)$revenueBefore->site_share_amount - 40000.0) < 0.0001
        && abs((float)$revenueBefore->user_share_amount - 60000.0) < 0.0001
        && abs((float)$revenueBefore->tax_amount - 5400.0) < 0.0001
        && abs((float)$revenueBefore->net_user_amount - $expectedNet) < 0.0001
        && (string)$revenueAfterPay->status === ContentRevenue::STATUS_PAID
        && abs((float)$walletAfterPay->balance_irt - $expectedNet) < 0.0001
        && abs((float)$walletAfterSecondPay->balance_irt - $expectedNet) < 0.0001
        && $txCount <= 1;

    echo json_encode([
        'ok' => $ok,
        'submit' => $submit,
        'approve' => $approve,
        'publish' => $publish,
        'create_revenue' => $createRevenue,
        'revenue_before' => cp2arr($revenueBefore),
        'pay' => $pay,
        'pay_again' => $payAgain,
        'revenue_after_pay' => cp2arr($revenueAfterPay),
        'wallet_after_pay' => cp2arr($walletAfterPay),
        'wallet_after_second_pay' => cp2arr($walletAfterSecondPay),
        'transaction_count' => $txCount,
        'expected_net' => $expectedNet,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
