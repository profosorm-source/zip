<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;

$c = Container::getInstance();
$db = $c->make(Database::class);

function cp4_insert_user(Database $db, string $suffix): int
{
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at)
         VALUES (?,?,?,?, 'user', 'verified', NOW(), NOW())",
        [
            'content_phase4_http_' . $suffix,
            'content_phase4_http_' . $suffix . '@example.test',
            'Content Phase4 HTTP ' . ucfirst($suffix),
            'active',
        ]
    );
}

function cp4_insert_submission(Database $db, int $userId, string $key, string $status, array $extra = []): int
{
    $nowExpr = 'NOW()';
    $approvedAt = $extra['approved_at'] ?? null;
    $publishedAt = $extra['published_at'] ?? null;
    $publishedUrl = $extra['published_url'] ?? null;
    $channelName = $extra['channel_name'] ?? null;
    $adminId = (int)($extra['admin_id'] ?? 1);

    return (int)$db->insert(
        "INSERT INTO content_submissions
         (user_id,title,url,video_url,platform,status,description,category,agreement_accepted,agreement_accepted_at,
          approved_at,approved_by,published_at,published_url,published_by,channel_name,is_deleted,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,1,NOW(),?,?,?,?,?,?,0,{$nowExpr},{$nowExpr})",
        [
            $userId,
            'CP4HTTP: ' . $key,
            'https://www.youtube.com/watch?v=CP4HTTP' . $key,
            'https://www.youtube.com/watch?v=CP4HTTP' . $key,
            'youtube',
            $status,
            'Fixture for content phase 4 admin HTTP actions',
            'education',
            $approvedAt,
            $approvedAt ? $adminId : null,
            $publishedAt,
            $publishedUrl,
            $publishedAt ? $adminId : null,
            $channelName,
        ]
    );
}

function cp4_insert_revenue(Database $db, int $submissionId, int $userId, string $period, string $status, int $adminId = 1): int
{
    $total = 50000.0;
    $site = 20000.0;
    $user = 30000.0;
    $tax = 2700.0;
    $net = 27300.0;
    $reviewedBy = in_array($status, ['approved', 'paid'], true) ? $adminId : null;
    $reviewedAt = in_array($status, ['approved', 'paid'], true) ? date('Y-m-d H:i:s') : null;

    return (int)$db->insert(
        "INSERT INTO content_revenues
         (user_id,content_id,submission_id,amount,status,created_at,period,views,total_revenue,gross_amount,
          site_share_percent,site_share_amount,platform_fee,user_share_percent,user_share_amount,tax_percent,tax_amount,
          net_user_amount,currency,reviewed_by,reviewed_at,created_by,is_deleted,updated_at)
         VALUES (?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())",
        [
            $userId,
            $submissionId,
            $submissionId,
            $net,
            $status,
            $period,
            8100,
            $total,
            $total,
            40,
            $site,
            0,
            60,
            $user,
            9,
            $tax,
            $net,
            'irt',
            $reviewedBy,
            $reviewedAt,
            $adminId,
        ]
    );
}

try {
    $db->query("DELETE FROM outbox_events WHERE aggregate_type IN ('content','content_revenue') AND payload LIKE '%CP4HTTP%'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%content_revenue_payment_%' AND user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase4_http_%@example.test')");
    $db->query("DELETE FROM content_revenues WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase4_http_%@example.test') OR period LIKE 'CP4HTTP-%'");
    $db->query("DELETE FROM content_agreements WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase4_http_%@example.test')");
    $db->query("DELETE FROM content_submissions WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase4_http_%@example.test') OR title LIKE 'CP4HTTP:%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase4_http_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'content_phase4_http_%@example.test'");

    $adminId = 1;
    $ids = [];
    $users = [];
    foreach (['approve','reject','publish','suspend','create_revenue','approve_revenue','pay_revenue'] as $suffix) {
        $users[$suffix] = cp4_insert_user($db, $suffix);
    }

    $ids['approve_submission'] = cp4_insert_submission($db, $users['approve'], 'approve', 'pending');
    $ids['reject_submission'] = cp4_insert_submission($db, $users['reject'], 'reject', 'pending');
    $ids['publish_submission'] = cp4_insert_submission($db, $users['publish'], 'publish', 'approved', [
        'approved_at' => date('Y-m-d H:i:s', strtotime('-3 months')),
        'admin_id' => $adminId,
    ]);
    $ids['suspend_submission'] = cp4_insert_submission($db, $users['suspend'], 'suspend', 'published', [
        'approved_at' => date('Y-m-d H:i:s', strtotime('-3 months')),
        'published_at' => date('Y-m-d H:i:s', strtotime('-2 months')),
        'published_url' => 'https://www.youtube.com/watch?v=CP4HTTPsuspendPublished',
        'channel_name' => 'CP4HTTP Channel',
        'admin_id' => $adminId,
    ]);
    $ids['create_revenue_submission'] = cp4_insert_submission($db, $users['create_revenue'], 'create-revenue', 'published', [
        'approved_at' => date('Y-m-d H:i:s', strtotime('-3 months')),
        'published_at' => date('Y-m-d H:i:s', strtotime('-2 months')),
        'published_url' => 'https://www.youtube.com/watch?v=CP4HTTPcreateRevenuePublished',
        'channel_name' => 'CP4HTTP Channel',
        'admin_id' => $adminId,
    ]);
    $approveRevSubmission = cp4_insert_submission($db, $users['approve_revenue'], 'approve-revenue', 'published', [
        'approved_at' => date('Y-m-d H:i:s', strtotime('-3 months')),
        'published_at' => date('Y-m-d H:i:s', strtotime('-2 months')),
        'published_url' => 'https://www.youtube.com/watch?v=CP4HTTPapproveRevenuePublished',
        'channel_name' => 'CP4HTTP Channel',
        'admin_id' => $adminId,
    ]);
    $payRevSubmission = cp4_insert_submission($db, $users['pay_revenue'], 'pay-revenue', 'published', [
        'approved_at' => date('Y-m-d H:i:s', strtotime('-3 months')),
        'published_at' => date('Y-m-d H:i:s', strtotime('-2 months')),
        'published_url' => 'https://www.youtube.com/watch?v=CP4HTTPpayRevenuePublished',
        'channel_name' => 'CP4HTTP Channel',
        'admin_id' => $adminId,
    ]);

    $ids['approve_revenue'] = cp4_insert_revenue($db, $approveRevSubmission, $users['approve_revenue'], 'CP4HTTP-01', 'pending', $adminId);
    $ids['pay_revenue'] = cp4_insert_revenue($db, $payRevSubmission, $users['pay_revenue'], 'CP4HTTP-02', 'approved', $adminId);
    $ids['pay_revenue_user'] = $users['pay_revenue'];
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$users['pay_revenue']]);

    echo json_encode(['ok' => true, 'ids' => $ids], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
