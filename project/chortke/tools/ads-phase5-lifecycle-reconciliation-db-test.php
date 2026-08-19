<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\Ads\AdsBudgetSettlementService;
use Core\Container;
use Core\Database;

$c = Container::getInstance();
/** @var Database $db */
$db = $c->make(Database::class);
/** @var AdsBudgetSettlementService $service */
$service = $c->make(AdsBudgetSettlementService::class);

function p5user(Database $db, string $name): int
{
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",
        [$name, $name . '@example.test', $name]
    );
}

function p5wallet(Database $db, int $userId, string $balance, string $locked): void
{
    $db->insert(
        "INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,?,0,NOW(),NOW())",
        [$userId, $balance, $locked]
    );
}

function p5ad(Database $db, int $userId, string $type, string $title, string $remainingBudget, int $remainingCount = 0, ?string $endDate = null): int
{
    $initialBudget = bccomp($remainingBudget, '100', 8) < 0 ? '100' : $remainingBudget;
    return (int)$db->insert(
        "INSERT INTO ads (user_id,title,description,type,placement,link,budget,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,is_active,currency,end_date,created_at,updated_at)
         VALUES (?, ?, 'ADS5 lifecycle test', ?, 'header', 'https://example.com', ?, ?, ?, ?, ?, 0, 0, 'active', 1, 'irt', ?, NOW(), NOW())",
        [$userId, $title, $type, $initialBudget, $initialBudget, $remainingBudget, $remainingCount, $remainingCount, $endDate]
    );
}

function p5escrow(Database $db, int $adId, int $userId, string $orderType, string $amount, string $status = 'partial'): int
{
    return (int)$db->insert(
        "INSERT INTO escrow_transactions (order_id,order_type,buyer_id,seller_id,amount,currency,status,held_at,partial_released,created_at,updated_at)
         VALUES (?, ?, ?, ?, ?, 'irt', ?, NOW(), 0, NOW(), NOW())",
        [(string)$adId, $orderType, $userId, $userId, $amount, $status]
    );
}

/** @return array<string, mixed>|null */
function p5arr(?object $row): ?array
{
    if ($row === null) return null;
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : null;
}

function p5decimalEquals(?object $row, string $field, string $expected): bool
{
    if ($row === null) return false;
    $value = get_object_vars($row)[$field] ?? null;
    return is_scalar($value) && is_numeric((string)$value) && bccomp((string)$value, $expected, 8) === 0;
}

/** Removes every synthetic ADS5 record, including wallet/ledger descendants. */
function p5cleanup(Database $db): void
{
    if ($db->inTransaction()) $db->rollback();
    $db->beginTransaction();
    try {
        $db->query(
            "DELETE le FROM ledger_entries le
             JOIN transactions t ON t.transaction_id = le.transaction_id
             JOIN users u ON u.id = t.user_id
             WHERE u.email LIKE 'ads5_%@example.test'"
        );
        $db->query("DELETE FROM ad_delivery_events WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS5:%')");
        $db->query("DELETE FROM adtube_views WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS5:%')");
        $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADS5:%')");
        $db->query(
            "DELETE t FROM transactions t
             JOIN users u ON u.id = t.user_id
             WHERE u.email LIKE 'ads5_%@example.test'"
        );
        $db->query("DELETE FROM ads WHERE title LIKE 'ADS5:%'");
        $db->query(
            "DELETE w FROM wallets w
             JOIN users u ON u.id = w.user_id
             WHERE u.email LIKE 'ads5_%@example.test'"
        );
        $db->query("DELETE FROM users WHERE email LIKE 'ads5_%@example.test'");
        $db->commit();
    } catch (Throwable $cleanupError) {
        if ($db->inTransaction()) $db->rollback();
        throw $cleanupError;
    }
}

$report = [];
$exitCode = 0;
$runtimeOutputLevel = ob_get_level();
ob_start();
try {
    p5cleanup($db);

    // 1) A replayed delivery idempotency key must not double-spend locked funds.
    $idempotencyUser = p5user($db, 'ads5_idem');
    p5wallet($db, $idempotencyUser, '0', '112');
    $idempotencyAd = p5ad($db, $idempotencyUser, 'banner', 'ADS5: idempotent banner', '100');
    p5escrow($db, $idempotencyAd, $idempotencyUser, 'banner_budget', '112', 'pending');
    $first = $service->consumeDeliveryBudget($idempotencyAd, 'banner', 'impression', 1, null, ['source' => 'ADS5:idempotency'], 'ads5_same_delivery_key');
    $second = $service->consumeDeliveryBudget($idempotencyAd, 'banner', 'impression', 1, null, ['source' => 'ADS5:idempotency'], 'ads5_same_delivery_key');
    $idempotencyAdAfter = $db->fetch("SELECT status,is_active,remaining_budget,spent_budget,impressions FROM ads WHERE id=?", [$idempotencyAd]);
    $idempotencyEscrow = $db->fetch("SELECT amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='banner_budget'", [(string)$idempotencyAd]);
    $idempotencyEvents = (int)$db->fetchColumn("SELECT COUNT(*) FROM ad_delivery_events WHERE ad_id=?", [$idempotencyAd]);
    $idempotencyWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$idempotencyUser]);

    // 2) An insufficient next unit closes the campaign and refunds only its locked remainder.
    $tinyUser = p5user($db, 'ads5_tiny');
    p5wallet($db, $tinyUser, '0', '5.6');
    $tinyAd = p5ad($db, $tinyUser, 'banner', 'ADS5: tiny remainder banner', '5');
    p5escrow($db, $tinyAd, $tinyUser, 'banner_budget', '5.6', 'partial');
    $tiny = $service->consumeDeliveryBudget($tinyAd, 'banner', 'impression', 1, null, ['source' => 'ADS5:tiny'], 'ads5_tiny_delivery');
    $tinyAdAfter = $db->fetch("SELECT status,is_active,remaining_budget,spent_budget,impressions FROM ads WHERE id=?", [$tinyAd]);
    $tinyEscrow = $db->fetch("SELECT amount,partial_released,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='banner_budget'", [(string)$tinyAd]);
    $tinyWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$tinyUser]);

    // 3) Lifecycle reconciliation expires a dated campaign and refunds its active escrow.
    $expiredUser = p5user($db, 'ads5_expired');
    p5wallet($db, $expiredUser, '0', '115');
    $expiredAd = p5ad($db, $expiredUser, 'notification', 'ADS5: expired notification', '100', 0, date('Y-m-d H:i:s', strtotime('-1 day')));
    p5escrow($db, $expiredAd, $expiredUser, 'notification_ad_budget', '115', 'pending');
    $reconcile = $service->reconcileLifecycle(50);
    $expiredAdAfter = $db->fetch("SELECT status,is_active,remaining_budget FROM ads WHERE id=?", [$expiredAd]);
    $expiredEscrow = $db->fetch("SELECT amount,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='notification_ad_budget'", [(string)$expiredAd]);
    $expiredWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$expiredUser]);

    $ok = !empty($first['success'])
        && !empty($second['success']) && !empty($second['duplicate'])
        && $idempotencyEvents === 1
        && $idempotencyAdAfter instanceof stdClass && (int)$idempotencyAdAfter->impressions === 1
        && p5decimalEquals($idempotencyAdAfter, 'remaining_budget', '90')
        && p5decimalEquals($idempotencyEscrow, 'amount', '101')
        && p5decimalEquals($idempotencyWallet, 'locked_irt', '101')
        && empty($tiny['success']) && ($tiny['code'] ?? '') === 'budget_exhausted'
        && $tinyAdAfter instanceof stdClass && $tinyAdAfter->status === 'completed'
        && p5decimalEquals($tinyAdAfter, 'remaining_budget', '0')
        && $tinyEscrow instanceof stdClass && $tinyEscrow->status === 'refunded'
        && p5decimalEquals($tinyWallet, 'balance_irt', '5.6')
        && p5decimalEquals($tinyWallet, 'locked_irt', '0')
        && $expiredAdAfter instanceof stdClass && $expiredAdAfter->status === 'expired'
        && p5decimalEquals($expiredAdAfter, 'remaining_budget', '0')
        && $expiredEscrow instanceof stdClass && $expiredEscrow->status === 'refunded'
        && p5decimalEquals($expiredWallet, 'balance_irt', '115')
        && p5decimalEquals($expiredWallet, 'locked_irt', '0');

    $report = [
        'ok' => $ok,
        'idempotency' => [
            'first' => $first,
            'second' => $second,
            'ad' => p5arr($idempotencyAdAfter),
            'escrow' => p5arr($idempotencyEscrow),
            'events' => $idempotencyEvents,
            'wallet' => p5arr($idempotencyWallet),
        ],
        'tiny_budget' => [
            'result' => $tiny,
            'ad' => p5arr($tinyAdAfter),
            'escrow' => p5arr($tinyEscrow),
            'wallet' => p5arr($tinyWallet),
        ],
        'expired_reconcile' => [
            'result' => $reconcile,
            'ad' => p5arr($expiredAdAfter),
            'escrow' => p5arr($expiredEscrow),
            'wallet' => p5arr($expiredWallet),
        ],
    ];
    if (!$ok) $exitCode = 1;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollback();
    $report = ['ok' => false, 'error' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()];
    $exitCode = 1;
} finally {
    try {
        p5cleanup($db);
    } catch (Throwable $cleanupError) {
        $report['cleanup_error'] = $cleanupError->getMessage();
        $exitCode = 1;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
while (ob_get_level() > $runtimeOutputLevel) ob_end_clean();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($exitCode);
