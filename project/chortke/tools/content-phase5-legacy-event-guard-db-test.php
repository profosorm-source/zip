<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Listeners\ContentEventListeners;
use App\Listeners\WalletDepositRequestListener;
use Core\Container;
use Core\Database;
use Core\GenericEvent;

$c = Container::getInstance();
$db = $c->make(Database::class);
$contentListener = $c->make(ContentEventListeners::class);
$walletDepositListener = $c->make(WalletDepositRequestListener::class);

function cp5row($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM outbox_events WHERE payload LIKE '%CP5LEGACY%' OR aggregate_id LIKE 'CP5LEGACY%'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%CP5LEGACY%' OR description LIKE '%CP5LEGACY%'");
    $db->query("DELETE FROM content_revenues WHERE period LIKE 'CP5LEGACY%'");
    $db->query("DELETE FROM content_submissions WHERE title LIKE 'CP5LEGACY:%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'content_phase5_legacy_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'content_phase5_legacy_%@example.test'");

    $userId = (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())",
        ['content_phase5_legacy_user', 'content_phase5_legacy_user@example.test', 'Content Phase5 Legacy User', 'active', 'user', 'verified']
    );
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,0,0,0,NOW(),NOW())", [$userId]);

    // Legacy content.revenue_paid must be notification-only now, not wallet mutation.
    $contentListener->handleContentRevenuePaid(new GenericEvent([
        'revenue_id' => 990001,
        'user_id' => $userId,
        'amount' => 12345,
        'marker' => 'CP5LEGACY_CONTENT_REVENUE_PAID',
    ]));
    $walletAfterLegacyContentEvent = $db->fetch("SELECT balance_irt,balance_usdt,locked_irt FROM wallets WHERE user_id = ?", [$userId]);

    // Current payment-recorded event must also be notification-only.
    $contentListener->handleContentRevenuePaymentRecorded(new GenericEvent([
        'revenue_id' => 990002,
        'user_id' => $userId,
        'amount' => 67890,
        'marker' => 'CP5LEGACY_PAYMENT_RECORDED',
    ]));
    $walletAfterPaymentRecorded = $db->fetch("SELECT balance_irt,balance_usdt,locked_irt FROM wallets WHERE user_id = ?", [$userId]);

    // Legacy wallet.deposit.requested for a content revenue that already has a direct transaction must be skipped.
    $submissionId = (int)$db->insert(
        "INSERT INTO content_submissions
         (user_id,title,url,video_url,platform,status,description,category,agreement_accepted,agreement_accepted_at,approved_at,approved_by,published_at,published_url,published_by,is_deleted,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,1,NOW(),DATE_SUB(NOW(), INTERVAL 3 MONTH),1,DATE_SUB(NOW(), INTERVAL 2 MONTH),?,1,0,NOW(),NOW())",
        [
            $userId,
            'CP5LEGACY: duplicate guard submission',
            'https://www.youtube.com/watch?v=CP5LEGACY',
            'https://www.youtube.com/watch?v=CP5LEGACY',
            'youtube',
            'published',
            'legacy duplicate guard test',
            'education',
            'https://www.youtube.com/watch?v=CP5LEGACY_PUBLISHED',
        ]
    );
    $revenueId = (int)$db->insert(
        "INSERT INTO content_revenues
         (user_id,content_id,submission_id,amount,status,created_at,period,views,total_revenue,gross_amount,
          site_share_percent,site_share_amount,platform_fee,user_share_percent,user_share_amount,tax_percent,tax_amount,
          net_user_amount,currency,reviewed_by,reviewed_at,paid_at,paid_by_admin,transaction_id,created_by,is_deleted,updated_at)
         VALUES (?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())",
        [
            $userId,
            $submissionId,
            $submissionId,
            50000,
            'paid',
            'CP5LEGACY-01',
            1000,
            100000,
            100000,
            40,
            40000,
            0,
            60,
            60000,
            9,
            5400,
            54600,
            'irt',
            1,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            1,
            'CP5LEGACY_DIRECT_TX',
            1,
        ]
    );

    $walletDepositListener->handle(new GenericEvent([
        'user_id' => $userId,
        'amount' => '54600',
        'currency' => 'irt',
        'metadata' => [
            'type' => 'content_revenue',
            'reference_id' => $revenueId,
            'description' => 'CP5LEGACY duplicate wallet deposit request',
            'idempotency_key' => 'content_revenue:' . $revenueId,
        ],
    ]));
    $walletAfterLegacyWalletDeposit = $db->fetch("SELECT balance_irt,balance_usdt,locked_irt FROM wallets WHERE user_id = ?", [$userId]);

    $ok = abs((float)$walletAfterLegacyContentEvent->balance_irt) < 0.0001
        && abs((float)$walletAfterPaymentRecorded->balance_irt) < 0.0001
        && abs((float)$walletAfterLegacyWalletDeposit->balance_irt) < 0.0001;

    echo json_encode([
        'ok' => $ok,
        'user_id' => $userId,
        'submission_id' => $submissionId,
        'revenue_id' => $revenueId,
        'wallet_after_legacy_content_event' => cp5row($walletAfterLegacyContentEvent),
        'wallet_after_payment_recorded' => cp5row($walletAfterPaymentRecorded),
        'wallet_after_legacy_wallet_deposit' => cp5row($walletAfterLegacyWalletDeposit),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
