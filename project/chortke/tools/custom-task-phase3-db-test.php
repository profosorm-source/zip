<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\Shared\DisputeService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$executor = $c->make(CustomTaskExecutorService::class);
$moderation = $c->make(CustomTaskModerationService::class);
$adminCustom = $c->make(AdminCustomTaskService::class);
$disputes = $c->make(DisputeService::class);

function createUser(Database $db, string $username, string $email, string $role = 'user'): int {
    return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active', ?, 'verified', NOW(), NOW())", [$username, $email, $username, $role]);
}
function createWallet(Database $db, int $userId, float $balance = 0): void {
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?, 0, 0, 0, NOW(), NOW())", [$userId, $balance]);
}
function createTask(Database $db, int $adv, string $title): int {
    return (int)$db->insert("INSERT INTO ads (user_id,title,description,type,task_type,proof_type,proof_description,price_per_task,currency,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,deadline_hours,created_at,updated_at) VALUES (?, ?, 'تسک تست فاز سه برای اختلاف و کران', 'custom_task', 'signup', 'code', 'کد ثبت نام را ارسال کنید', 10000, 'irt', 50000, 50000, 5, 5, 0, 0, 'active', 24, NOW(), NOW())", [$adv, $title]);
}
function arr($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM disputes WHERE ref_type='custom_task_submission' AND ref_id IN (SELECT id FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTPHASE3:%'))");
    $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTPHASE3:%')");
    $db->query("DELETE FROM ads WHERE title LIKE 'CTPHASE3:%'");
    $db->query("DELETE FROM users WHERE email LIKE 'ctphase3_%@example.test'");

    $admin = createUser($db, 'ctphase3_admin', 'ctphase3_admin@example.test', 'admin');
    $adv = createUser($db, 'ctphase3_adv', 'ctphase3_adv@example.test');
    $worker = createUser($db, 'ctphase3_worker', 'ctphase3_worker@example.test');
    $worker2 = createUser($db, 'ctphase3_worker2', 'ctphase3_worker2@example.test');
    createWallet($db, $adv, 0); createWallet($db, $worker, 0); createWallet($db, $worker2, 0); createWallet($db, $admin, 0);

    // Dispute flow: rejected -> disputed -> admin resolves for executor -> approved/rewarded.
    $task1 = createTask($db, $adv, 'CTPHASE3: dispute executor wins');
    $st1 = $executor->startTask($task1, $worker);
    $sub1 = (int)$st1['submission_id'];
    $executor->submitProof($sub1, $worker, ['task_execution_id'=>$sub1, 'proof_code'=>'D1', 'proof_text'=>'کد D1 ثبت شد.', 'idempotency_key'=>'P3_D1_'.bin2hex(random_bytes(3))]);
    $submission1 = $db->fetch("SELECT * FROM custom_task_submissions WHERE id=?", [$sub1]);
    $reject = $moderation->reviewSubmission($sub1, $adv, 'reject', 'کد با اطلاعات ثبت‌شده تطابق ندارد');
    $db->beginTransaction();
    $disputeId = (int)$db->insert("INSERT INTO disputes (ref_type,ref_id,user_id,target_user_id,status,reason,role,created_at,updated_at) VALUES ('custom_task_submission', ?, ?, ?, 'open', 'من کد را درست ثبت کرده‌ام و درخواست داوری دارم.', 'worker', NOW(), NOW())", [$sub1, $worker, $adv]);
    $db->query("UPDATE custom_task_submissions SET status='disputed', dispute_id=? WHERE id=?", [$disputeId, $sub1]);
    $db->commit();
    $resolve = $disputes->resolveByAdmin($admin, $disputeId, 'executor', 'مدرک پذیرفته شد.');
    $sub1After = $db->fetch("SELECT id,status,reward_paid,reward_transaction_id,dispute_id FROM custom_task_submissions WHERE id=?", [$sub1]);
    $disputeAfter = $db->fetch("SELECT id,status,admin_decision,resolved_by FROM disputes WHERE id=?", [$disputeId]);

    // Auto approve old submitted.
    $task2 = createTask($db, $adv, 'CTPHASE3: auto approve');
    $st2 = $executor->startTask($task2, $worker2);
    $sub2 = (int)$st2['submission_id'];
    $executor->submitProof($sub2, $worker2, ['task_execution_id'=>$sub2, 'proof_code'=>'AUTO1', 'proof_text'=>'کد AUTO1 ثبت شد.', 'idempotency_key'=>'P3_AUTO_'.bin2hex(random_bytes(3))]);
    $db->query("UPDATE custom_task_submissions SET submitted_at = DATE_SUB(NOW(), INTERVAL 72 HOUR) WHERE id=?", [$sub2]);
    $autoCount = $adminCustom->autoApproveOldSubmissions();
    $sub2After = $db->fetch("SELECT id,status,reward_paid,auto_approved_at FROM custom_task_submissions WHERE id=?", [$sub2]);

    // Expire old in_progress.
    $task3 = createTask($db, $adv, 'CTPHASE3: expire in progress');
    $st3 = $executor->startTask($task3, $worker2);
    $sub3 = (int)$st3['submission_id'];
    $db->query("UPDATE custom_task_submissions SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id=?", [$sub3]);
    $expiredCount = $adminCustom->expireOldSubmissions();
    $sub3After = $db->fetch("SELECT id,status FROM custom_task_submissions WHERE id=?", [$sub3]);
    $task3After = $db->fetch("SELECT id,remaining_count,pending_count FROM ads WHERE id=?", [$task3]);

    echo json_encode([
        'ok' => !empty($resolve['ok']) && ($sub1After->status ?? '') === 'approved' && (int)($sub1After->reward_paid ?? 0) === 1 && $autoCount >= 1 && ($sub2After->status ?? '') === 'approved' && $expiredCount >= 1 && ($sub3After->status ?? '') === 'expired',
        'reject' => $reject,
        'resolve' => $resolve,
        'sub1_after' => arr($sub1After),
        'dispute_after' => arr($disputeAfter),
        'auto_count' => $autoCount,
        'sub2_after' => arr($sub2After),
        'expired_count' => $expiredCount,
        'sub3_after' => arr($sub3After),
        'task3_after' => arr($task3After),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
