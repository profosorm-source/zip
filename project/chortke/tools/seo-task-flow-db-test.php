<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\Seo\SeoService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$seo = $c->make(SeoService::class);

function makeUser(Database $db, string $name): int {
    return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active', 'user', 'verified', NOW(), NOW())", [$name, $name.'@example.test', $name]);
}
function wallet(Database $db, int $uid, float $bal=0): void {
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?, 0, 0, 0, NOW(), NOW())", [$uid, $bal]);
}
function arr($o){ return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM seo_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'SEODBTEST:%')");
    $db->query("DELETE FROM ads WHERE title LIKE 'SEODBTEST:%'");
    $db->query("DELETE FROM users WHERE email LIKE 'seodbtest_%@example.test'");

    $adv = makeUser($db, 'seodbtest_adv');
    $worker = makeUser($db, 'seodbtest_worker');
    wallet($db, $adv, 0); wallet($db, $worker, 0);

    $ad = (int)$db->insert("INSERT INTO ads (user_id,title,description,type,site_url,target_url,keyword,budget,remaining_budget,min_payout,max_payout,target_duration,min_score,max_per_day,status,currency,created_at,updated_at) VALUES (?, 'SEODBTEST: active seo', 'SEO DB test', 'seo', 'https://example.com', 'https://example.com', 'چرتکه تست', 100000, 100000, 1000, 5000, 60, 40, 10, 'active', 'irt', NOW(), NOW())", [$adv]);

    $start = $seo->startTask($ad, $worker);
    $executionId = (int)($start['execution_id'] ?? 0);
    $complete = $executionId ? $seo->completeTask($executionId, $worker, [
        'duration' => 180,
        'scroll_depth' => 85,
        'interactions' => 8,
        'scroll_speed' => 300,
        'mouse_pattern' => 'normal',
        'pause_count' => 5,
        'interaction_types' => ['external_open','return_to_task','scroll','click','pause'],
        'target_opened' => 1,
        'behavior' => ['scroll_speed'=>300,'mouse_pattern'=>'normal','pause_count'=>5,'interaction_types'=>['external_open','return_to_task','scroll','click','pause'],'target_opened'=>1],
    ]) : ['success'=>false,'message'=>'no execution'];

    $execution = $executionId ? $db->fetch("SELECT * FROM seo_executions WHERE id=?", [$executionId]) : null;
    $adAfter = $db->fetch("SELECT id,remaining_budget,executions_count,status FROM ads WHERE id=?", [$ad]);
    $workerWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$worker]);

    echo json_encode([
        'ok' => !empty($start['success']) && !empty($complete['success']) && ($execution->status ?? '') === 'completed' && (float)($workerWallet->balance_irt ?? 0) > 0,
        'start' => $start,
        'complete' => $complete,
        'execution' => arr($execution),
        'ad_after' => arr($adAfter),
        'worker_wallet' => arr($workerWallet),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
