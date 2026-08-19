<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\CustomTask\CustomTaskExecutorService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$executor = $c->make(CustomTaskExecutorService::class);

try {
    $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTSCHEMA:%')");
    $db->query("DELETE FROM ads WHERE title LIKE 'CTSCHEMA:%'");
    $db->query("DELETE FROM users WHERE email IN ('ctschema_adv@example.test','ctschema_w1@example.test','ctschema_w2@example.test')");

    $adv = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctschema_adv','ctschema_adv@example.test','Schema Adv','active','user','verified',NOW(),NOW())");
    $w1 = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctschema_w1','ctschema_w1@example.test','Schema W1','active','user','verified',NOW(),NOW())");
    $w2 = (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctschema_w2','ctschema_w2@example.test','Schema W2','active','user','verified',NOW(),NOW())");
    $task = (int)$db->insert("INSERT INTO ads (user_id,title,description,type,task_type,proof_type,proof_description,proof_schema,price_per_task,currency,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,deadline_hours,created_at,updated_at) VALUES (?, 'CTSCHEMA: code task', 'ثبت نام و ارسال کد یکتا', 'custom_task', 'signup', 'code', 'کد یکتای ثبت نام را ارسال کنید', ?, 10000, 'irt', 30000, 30000, 3, 3, 0, 0, 'active', 24, NOW(), NOW())", [$adv, json_encode(['type'=>'code','required'=>true], JSON_UNESCAPED_UNICODE)]);

    $start1 = $executor->startTask($task, $w1);
    $sub1 = (int)($start1['submission_id'] ?? 0);
    $missingCode = $executor->submitProof($sub1, $w1, [
        'task_execution_id' => $sub1,
        'proof_text' => 'متن کافی ولی بدون کد ارسال شده است.',
        'idempotency_key' => 'SCHEMA_MISSING_' . bin2hex(random_bytes(4)),
    ]);
    $firstCode = $executor->submitProof($sub1, $w1, [
        'task_execution_id' => $sub1,
        'proof_code' => 'UNIQUE-CODE-1',
        'proof_text' => 'کد ثبت نام من UNIQUE-CODE-1 است.',
        'idempotency_key' => 'SCHEMA_CODE1_' . bin2hex(random_bytes(4)),
    ]);

    $start2 = $executor->startTask($task, $w2);
    $sub2 = (int)($start2['submission_id'] ?? 0);
    $duplicateCode = $executor->submitProof($sub2, $w2, [
        'task_execution_id' => $sub2,
        'proof_code' => 'UNIQUE-CODE-1',
        'proof_text' => 'همان کد قبلی را ارسال می‌کنم.',
        'idempotency_key' => 'SCHEMA_DUP_' . bin2hex(random_bytes(4)),
    ]);
    $secondCode = $executor->submitProof($sub2, $w2, [
        'task_execution_id' => $sub2,
        'proof_code' => 'UNIQUE-CODE-2',
        'proof_text' => 'کد متفاوت UNIQUE-CODE-2 است.',
        'idempotency_key' => 'SCHEMA_CODE2_' . bin2hex(random_bytes(4)),
    ]);

    $row1 = $db->fetch("SELECT id,status,proof_code,proof_text FROM custom_task_submissions WHERE id=?", [$sub1]);
    $row2 = $db->fetch("SELECT id,status,proof_code,proof_text FROM custom_task_submissions WHERE id=?", [$sub2]);

    echo json_encode([
        'ok' => empty($missingCode['success']) && !empty($firstCode['success']) && empty($duplicateCode['success']) && !empty($secondCode['success']),
        'start1' => $start1,
        'missing_code' => $missingCode,
        'first_code' => $firstCode,
        'start2' => $start2,
        'duplicate_code' => $duplicateCode,
        'second_code' => $secondCode,
        'row1' => $row1,
        'row2' => $row2,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
