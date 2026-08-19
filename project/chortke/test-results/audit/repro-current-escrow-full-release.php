<?php

declare(strict_types=1);

// Disposable local-only reproduction. It runs inside an outer DB transaction
// and rolls all rows/balances back before exit.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use App\Contracts\WalletServiceInterface;
use App\Services\EscrowService;
use Core\Container;
use Core\Database;

$container = Container::getInstance();
/** @var Database $db */
$db = $container->make(Database::class);
/** @var WalletServiceInterface $wallet */
$wallet = $container->make(WalletServiceInterface::class);
/** @var EscrowService $escrowService */
$escrowService = $container->make(EscrowService::class);

$buyerId = 1;
$sellerId = 2;
$amount = '20000.0000';
$orderId = 'audit-full-release-' . bin2hex(random_bytes(6));
$idempotencyKey = 'audit-withdraw-' . bin2hex(random_bytes(12));

$db->beginTransaction();
try {
    $db->query(
        'UPDATE wallets SET balance_irt = ?, locked_irt = 0, is_frozen = 0 WHERE user_id = ?',
        ['50000000.0000', $buyerId]
    );
    $db->query(
        'UPDATE wallets SET balance_irt = ?, locked_irt = 0, is_frozen = 0 WHERE user_id = ?',
        ['0.0000', $sellerId]
    );

    $hold = $wallet->withdraw($buyerId, $amount, 'irt', [
        'type' => 'audit_escrow_hold',
        'ref_id' => $orderId,
        'idempotency_key' => $idempotencyKey,
    ]);
    if (empty($hold['success'])) {
        throw new RuntimeException('Could not create test hold: ' . json_encode($hold));
    }

    $db->query(
        "INSERT INTO escrow_transactions
         (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
         VALUES (?, 'audit_full_release', ?, ?, ?, 'irt', 'in_escrow', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
        [$orderId, $buyerId, $sellerId, $amount]
    );
    $escrowId = (int) $db->lastInsertId();

    $before = $db->fetchAll('SELECT user_id, balance_irt, locked_irt FROM wallets WHERE user_id IN (?, ?) ORDER BY user_id', [$buyerId, $sellerId]);
    $result = $escrowService->releaseFunds($escrowId, $sellerId, 'audit');
    $after = $db->fetchAll('SELECT user_id, balance_irt, locked_irt FROM wallets WHERE user_id IN (?, ?) ORDER BY user_id', [$buyerId, $sellerId]);

    echo json_encode([
        'hold' => $hold,
        'release' => $result,
        'before' => $before,
        'after' => $after,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollback();
    }
}
