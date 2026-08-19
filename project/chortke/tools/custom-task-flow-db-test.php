<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\CustomTask\CustomTaskModerationService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$executor = $c->make(CustomTaskExecutorService::class);
$moderation = $c->make(CustomTaskModerationService::class);

function arr($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTDBTEST:%')");
    $db->query("DELETE FROM ads WHERE title LIKE 'CTDBTEST:%'");
    $db->query("DELETE FROM users WHERE email IN ('ct_advertiser@example.test','ct_worker@example.test')");

    $advertiserId = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ct_adv','ct_advertiser@example.test','CT Advertiser','active','user','verified',NOW(),NOW())");
    $workerId = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ct_worker','ct_worker@example.test','CT Worker','active','user','verified',NOW(),NOW())");
    $taskId = (int)$db->insert("INSERT INTO ads (user_id,title,description,type,task_type,proof_type,proof_description,price_per_task,currency,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,deadline_hours,created_at,updated_at) VALUES (?, 'CTDBTEST: signup task', 'ثبت نام در سایت نمونه و ارسال کد کاربری', 'custom_task', 'signup', 'text', 'کد کاربری یا ایمیل ثبت نام را ارسال کنید', 10000, 'irt', 100000, 100000, 10, 10, 0, 0, 'active', 24, NOW(), NOW())", [$advertiserId]);

    $start = $executor->startTask($taskId, $workerId);
    $submissionId = (int)($start['submission_id'] ?? 0);
    $proof = $submissionId ? $executor->submitProof($submissionId, $workerId, [
        'task_execution_id' => $submissionId,
        'proof_text' => 'با ایمیل ct_worker@example.test ثبت نام کردم و کد کاربری ABC123 است.',
        'idempotency_key' => 'CT_PROOF_' . $submissionId . '_' . bin2hex(random_bytes(4)),
    ]) : ['success' => false, 'message' => 'start failed'];

    $submission = $submissionId ? $db->fetch("SELECT * FROM custom_task_submissions WHERE id=?", [$submissionId]) : null;
    $review = $submissionId ? $moderation->reviewSubmission($submissionId, $advertiserId, 'approve') : ['success' => false, 'message' => 'no submission'];
    $submissionAfter = $submissionId ? $db->fetch("SELECT * FROM custom_task_submissions WHERE id=?", [$submissionId]) : null;
    $taskAfter = $db->fetch("SELECT id, remaining_count, pending_count, completed_count, remaining_budget, status FROM ads WHERE id=?", [$taskId]);

    echo json_encode([
        'ok' => !empty($start['success']) && !empty($proof['success']),
        'advertiser_id' => $advertiserId,
        'worker_id' => $workerId,
        'task_id' => $taskId,
        'start' => $start,
        'proof' => $proof,
        'submission_before_review' => arr($submission),
        'review' => $review,
        'submission_after_review' => arr($submissionAfter),
        'task_after' => arr($taskAfter),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
