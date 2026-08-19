<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\AdTubeExecutionModel;
use App\Services\Ads\AdsBudgetSettlementService;
use App\Services\AdSystemManager;
use Core\Container;
use Core\Database;

$c = Container::getInstance();
/** @var Database $db */
$db = $c->make(Database::class);
/** @var AdSystemManager $manager */
$manager = $c->make(AdSystemManager::class);
/** @var AdsBudgetSettlementService $finance */
$finance = $c->make(AdsBudgetSettlementService::class);
/** @var AdTubeExecutionModel $adtubeExecutions */
$adtubeExecutions = $c->make(AdTubeExecutionModel::class);

function a4user(Database $db, string $name): int
{
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",
        [$name, $name . '@example.test', $name]
    );
}

function a4wallet(Database $db, int $userId, string $balance = '1000000'): void
{
    $db->insert(
        "INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",
        [$userId, $balance]
    );
}

/** @return array<string, mixed>|null */
function a4arr(?object $row): ?array
{
    if ($row === null) return null;
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : null;
}

function a4decimalEquals(?object $row, string $field, string $expected): bool
{
    if ($row === null) return false;
    $value = get_object_vars($row)[$field] ?? null;
    return is_scalar($value) && is_numeric((string)$value) && bccomp((string)$value, $expected, 8) === 0;
}

function a4decimalGreaterThan(?object $row, string $field, string $threshold): bool
{
    if ($row === null) return false;
    $value = get_object_vars($row)[$field] ?? null;
    return is_scalar($value) && is_numeric((string)$value) && bccomp((string)$value, $threshold, 8) > 0;
}

/** Removes every synthetic ADS4 record, including wallet/ledger descendants. */
function a4cleanup(Database $db): void
{
    if ($db->inTransaction()) $db->rollback();
    $db->beginTransaction();
    try {
        $db->query(
            "DELETE le FROM ledger_entries le
             JOIN transactions t ON t.transaction_id = le.transaction_id
             JOIN users u ON u.id = t.user_id
             WHERE u.email LIKE 'ads4_%@example.test'"
        );
        $db->query("DELETE FROM ad_delivery_events WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS4:%')");
        $db->query("DELETE FROM adtube_views WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'ADS4:%')");
        $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADS4:%')");
        $db->query(
            "DELETE t FROM transactions t
             JOIN users u ON u.id = t.user_id
             WHERE u.email LIKE 'ads4_%@example.test'"
        );
        $db->query("DELETE FROM ads WHERE title LIKE 'ADS4:%'");
        $db->query(
            "DELETE w FROM wallets w
             JOIN users u ON u.id = w.user_id
             WHERE u.email LIKE 'ads4_%@example.test'"
        );
        $db->query("DELETE FROM users WHERE email LIKE 'ads4_%@example.test'");
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
    a4cleanup($db);

    $advertiserId = a4user($db, 'ads4_adv');
    $workerId = a4user($db, 'ads4_worker');
    a4wallet($db, $advertiserId, '1000000');
    a4wallet($db, $workerId, '0');

    // Banner: approval, real impression/click spend, then canonical user cancellation/refund.
    $bannerCreate = $manager->create('banner', $advertiserId, [
        'title' => 'ADS4: banner',
        'placement' => 'header',
        'link' => 'https://example.com/banner',
        'budget' => '100000',
        'image_path' => 'banners/test-placeholder.jpg',
        'currency' => 'irt',
    ]);
    $bannerId = $bannerCreate['ad_id'];
    $bannerApprove = $finance->applyAdminAction($bannerId, 'approve', 1, 'phase4 test approve');
    $bannerImpression = $finance->consumeDeliveryBudget($bannerId, 'banner', 'impression', 2, null, ['source' => 'ADS4:test', 'title' => 'ADS4'], 'ads4_banner_impressions');
    $bannerClick = $finance->consumeDeliveryBudget($bannerId, 'banner', 'click', 1, $workerId, ['source' => 'ADS4:test', 'title' => 'ADS4'], 'ads4_banner_click');
    $bannerAfterSpend = $db->fetch("SELECT id,status,is_active,remaining_budget,spent_budget,impressions,clicks FROM ads WHERE id=?", [$bannerId]);
    $bannerEscrowAfterSpend = $db->fetch("SELECT amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='banner_budget' ORDER BY id DESC LIMIT 1", [(string)$bannerId]);
    $bannerReject = $manager->cancelAd($bannerId, $advertiserId, 'phase4 user cancellation refund');
    $bannerAfterReject = $db->fetch("SELECT id,status,is_active,remaining_budget,spent_budget FROM ads WHERE id=?", [$bannerId]);
    $bannerEscrowAfterReject = $db->fetch("SELECT amount,partial_released,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='banner_budget' ORDER BY id DESC LIMIT 1", [(string)$bannerId]);
    $advertiserWalletAfterBanner = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$advertiserId]);

    // Notification: delivery consumes escrow only after successful dispatch.
    $notificationCreate = $manager->create('notification', $advertiserId, [
        'title' => 'ADS4: notification',
        'body' => 'متن پیام تبلیغاتی تستی برای فاز چهارم تبلیغات',
        'target_link' => 'https://example.com/notification',
        'budget' => '25000',
        'currency' => 'irt',
    ]);
    $notificationId = $notificationCreate['ad_id'];
    $notificationApprove = $finance->applyAdminAction($notificationId, 'approve', 1, 'phase4 notification approve');
    $notificationDelivery = $finance->consumeDeliveryBudget($notificationId, 'notification', 'delivery', 10, null, ['source' => 'ADS4:test', 'title' => 'ADS4'], 'ads4_notification_delivery');
    $notificationAfter = $db->fetch("SELECT id,status,remaining_budget,spent_budget,impressions,clicks FROM ads WHERE id=?", [$notificationId]);
    $notificationEscrow = $db->fetch("SELECT amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='notification_ad_budget' ORDER BY id DESC LIMIT 1", [(string)$notificationId]);

    // AdTube: completed real view pays the executor and consumes advertiser escrow atomically.
    $adtubeCreate = $manager->create('adtube', $advertiserId, [
        'title' => 'ADS4: adtube',
        'target_link' => 'https://youtube.com/watch?v=abc123xyz',
        'price_per_task' => '1200',
        'total_count' => 2,
        'currency' => 'irt',
    ]);
    $adtubeId = $adtubeCreate['ad_id'];
    $finance->applyAdminAction($adtubeId, 'approve', 1, 'phase4 adtube approve');
    $execution = $adtubeExecutions->findOrCreate($adtubeId, $workerId, ['ip' => '127.0.0.1', 'user_agent' => 'ADS4']);
    if (!$execution instanceof stdClass || !isset($execution->id) || !is_scalar($execution->id)) {
        throw new RuntimeException('AdTube test execution was not persisted');
    }
    $executionId = (int)$execution->id;
    $adtubeExecutions->startWatching($executionId);
    $adtubeSettlement = $finance->settleAdTubeView($executionId, $workerId, 90, 95, 1.0);
    $adtubeAfter = $db->fetch("SELECT id,status,remaining_budget,spent_budget,remaining_count,completed_count,impressions FROM ads WHERE id=?", [$adtubeId]);
    $adtubeExecutionAfter = $db->fetch("SELECT id,status,reward_amount,reward_paid FROM adtube_views WHERE id=?", [$executionId]);
    $adtubeEscrow = $db->fetch("SELECT amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='adtube_budget' ORDER BY id DESC LIMIT 1", [(string)$adtubeId]);
    $workerWallet = $db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?", [$workerId]);

    $snapshot = $finance->financeSnapshot($notificationId, 'notification');
    $deliveryRows = $db->fetchAll("SELECT ad_type,event_type,units,amount,platform_fee FROM ad_delivery_events WHERE ad_id IN (?,?,?) ORDER BY id", [$bannerId, $notificationId, $adtubeId]);

    $ok = !empty($bannerApprove['success'])
        && !empty($bannerImpression['success'])
        && !empty($bannerClick['success'])
        && a4decimalEquals($bannerAfterSpend, 'remaining_budget', '99480')
        && $bannerAfterSpend instanceof stdClass && (int)$bannerAfterSpend->impressions === 2
        && $bannerAfterSpend instanceof stdClass && (int)$bannerAfterSpend->clicks === 1
        && a4decimalGreaterThan($bannerEscrowAfterSpend, 'partial_released', '500')
        && !empty($bannerReject['success'])
        && $bannerAfterReject instanceof stdClass && $bannerAfterReject->status === 'cancelled'
        && a4decimalEquals($bannerAfterReject, 'remaining_budget', '0')
        && $bannerEscrowAfterReject instanceof stdClass && $bannerEscrowAfterReject->status === 'refunded'
        && a4decimalEquals($advertiserWalletAfterBanner, 'locked_irt', '0')
        && !empty($notificationApprove['success'])
        && !empty($notificationDelivery['success'])
        && a4decimalEquals($notificationAfter, 'remaining_budget', '24750')
        && $notificationAfter instanceof stdClass && (int)$notificationAfter->impressions === 10
        && a4decimalGreaterThan($notificationEscrow, 'partial_released', '250')
        && !empty($adtubeSettlement['success'])
        && $adtubeExecutionAfter instanceof stdClass && $adtubeExecutionAfter->status === 'completed'
        && $adtubeExecutionAfter instanceof stdClass && (int)$adtubeExecutionAfter->reward_paid === 1
        && a4decimalEquals($workerWallet, 'balance_irt', '1200')
        && a4decimalEquals($adtubeAfter, 'remaining_budget', '1200')
        && count($deliveryRows) >= 4
        && $snapshot['escrows'] !== [];

    $report = [
        'ok' => $ok,
        'banner' => [
            'create' => $bannerCreate,
            'approve' => $bannerApprove,
            'impression' => $bannerImpression,
            'click' => $bannerClick,
            'after_spend' => a4arr($bannerAfterSpend),
            'escrow_after_spend' => a4arr($bannerEscrowAfterSpend),
            'reject' => $bannerReject,
            'after_reject' => a4arr($bannerAfterReject),
            'escrow_after_reject' => a4arr($bannerEscrowAfterReject),
        ],
        'notification' => [
            'create' => $notificationCreate,
            'approve' => $notificationApprove,
            'delivery' => $notificationDelivery,
            'after' => a4arr($notificationAfter),
            'escrow' => a4arr($notificationEscrow),
        ],
        'adtube' => [
            'create' => $adtubeCreate,
            'settlement' => $adtubeSettlement,
            'ad_after' => a4arr($adtubeAfter),
            'execution' => a4arr($adtubeExecutionAfter),
            'escrow' => a4arr($adtubeEscrow),
            'worker_wallet' => a4arr($workerWallet),
        ],
        'delivery_rows' => array_map(static fn(stdClass $row): ?array => a4arr($row), $deliveryRows),
        'finance_snapshot' => [
            'order_type' => $snapshot['order_type'],
            'escrow_count' => count($snapshot['escrows']),
            'delivery_summary' => a4arr($snapshot['delivery_summary']),
        ],
        'advertiser_wallet_after_banner' => a4arr($advertiserWalletAfterBanner),
    ];
    if (!$ok) $exitCode = 1;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollback();
    $report = ['ok' => false, 'error' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()];
    $exitCode = 1;
} finally {
    try {
        a4cleanup($db);
    } catch (Throwable $cleanupError) {
        $report['cleanup_error'] = $cleanupError->getMessage();
        $exitCode = 1;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
while (ob_get_level() > $runtimeOutputLevel) ob_end_clean();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($exitCode);
