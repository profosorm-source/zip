<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\BehaviorAnalysisService;
use App\Services\SocialTask\CameraVerificationService;

$container = Container::getInstance();
$db = $container->make(Database::class);
$service = $container->make(SocialTaskService::class);
$behavior = $container->make(BehaviorAnalysisService::class);
$camera = $container->make(CameraVerificationService::class);

function row_to_array($row): array { return $row ? json_decode(json_encode($row, JSON_UNESCAPED_UNICODE), true) : []; }

try {
    $db->query("DELETE FROM social_camera_requests WHERE execution_id IN (SELECT id FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'DBTEST:%'))");
    $db->query("DELETE FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'DBTEST:%')");
    $db->query("DELETE FROM ads WHERE title LIKE 'DBTEST:%'");
    $db->query("DELETE FROM users WHERE email IN ('dbtest_advertiser@example.test','dbtest_executor@example.test')");

    $advertiserId = (int)$db->insert(
        "INSERT INTO users (username, email, full_name, status, role, created_at, updated_at) VALUES (?, ?, ?, 'active', 'user', NOW(), NOW())",
        ['dbtest_advertiser', 'dbtest_advertiser@example.test', 'DB Test Advertiser']
    );
    $executorId = (int)$db->insert(
        "INSERT INTO users (username, email, full_name, status, role, created_at, updated_at) VALUES (?, ?, ?, 'active', 'user', NOW(), NOW())",
        ['dbtest_executor', 'dbtest_executor@example.test', 'DB Test Executor']
    );

    $adId = (int)$db->insert(
        "INSERT INTO ads
            (user_id, title, description, type, platform, task_type, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, target_url, currency, created_at, updated_at)
         VALUES
            (?, 'DBTEST: social camera suspicious mobile', 'follow account and submit text proof', 'social_task', 'instagram', 'follow', 12000, 120000, 120000, 10, 10, 'active', 'https://instagram.example/test', 'irt', NOW(), NOW())",
        [$advertiserId]
    );

    $start = $service->startExecution($executorId, $adId, [
        'ip' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148',
    ]);
    if (empty($start['success']) || empty($start['execution_id'])) {
        throw new RuntimeException('startExecution failed: ' . json_encode($start, JSON_UNESCAPED_UNICODE));
    }
    $executionId = (int)$start['execution_id'];

    // رفتار نه آنقدر انسانی است که عبور کند، نه آنقدر bot/farm قطعی که مستقیم رد شود؛
    // در بازه خاکستری 25..49 باید Camera Verification درخواست شود.
    $signals = [
        'tap_count' => 3,
        'swipe_count' => 0,
        'scroll_count' => 0,
        'touch_pauses' => 0,
        'touch_timing_variance' => 5,
        'scroll_speed_variance' => 999,
        'session_duration' => 15,
        'active_time' => 15,
        'expected_time' => 45,
        'avg_action_delay_ms' => 500,
        'is_mobile' => 1,
    ];
    $recorded = $service->recordBehaviorSignals($executionId, $executorId, $signals);
    $analysis = $behavior->analyze($signals);
    $cameraRequired = $camera->isRequired($executionId, (float)$analysis['behavior_score'], $signals);
    $cameraRequestId = $cameraRequired ? $camera->createRequest($executionId, $executorId) : null;
    $pendingBefore = $camera->getPendingRequest($executionId);

    $cameraResult = $camera->processResult($executionId, $executorId, 85, ['camera_permission_granted', 'live_video_stream', 'task_type_follow']);
    $submit = $service->submitExecution($executorId, $executionId, [
        'active_time' => 15,
        'idempotency_key' => 'DBTEST_SOCIAL_' . $executionId . '_' . bin2hex(random_bytes(4)),
    ]);

    $execRow = $db->fetch("SELECT id, status, proof_text, behavior_data, final_score, decision, verification_required, camera_score FROM social_task_executions WHERE id = ?", [$executionId]);
    $cameraRow = $db->fetch("SELECT id, execution_id, user_id, status, camera_score, verified_signals, image_path FROM social_camera_requests WHERE execution_id = ?", [$executionId]);

    echo json_encode([
        'ok' => $recorded && $cameraRequired && !empty($cameraResult['success']) && !empty($submit['success']) && ($execRow->status ?? '') === 'submitted' && ($cameraRow->status ?? '') === 'completed' && empty($cameraRow->image_path),
        'advertiser_id' => $advertiserId,
        'executor_id' => $executorId,
        'ad_id' => $adId,
        'start' => $start,
        'behavior_recorded' => $recorded,
        'analysis' => $analysis,
        'camera_required' => $cameraRequired,
        'camera_request_id' => $cameraRequestId,
        'pending_before' => row_to_array($pendingBefore),
        'camera_result' => $cameraResult,
        'submit' => $submit,
        'execution' => row_to_array($execRow),
        'camera_row' => row_to_array($cameraRow),
        'assertions' => [
            'camera_only_signal_no_image_path' => empty($cameraRow->image_path),
            'execution_submitted' => ($execRow->status ?? '') === 'submitted',
            'camera_completed' => ($cameraRow->status ?? '') === 'completed',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
