<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use Core\TransactionWrapper;
use Core\Database;

class AdsSeoService
{
    private TransactionWrapper $transactionWrapper;
    private WalletServiceInterface $walletService;
    private Ads $adModel;
    private SeoExecution $executionModel;
    private User $userModel;
    private Database $db;
    private LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;

    /**
     * Centralized toObject (root-cause normalization).
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    public function __construct(
        TransactionWrapper $transactionWrapper,
        WalletServiceInterface $walletService,
        Ads $adModel,
        SeoExecution $executionModel,
        User $userModel,
        Database $db,
        LoggerInterface $logger
    ,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->walletService = $walletService;
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->userModel = $userModel;
        $this->db = $db;
        $this->logger = $logger;
        $this->outbox = $outbox;
}

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAd(int $userId, array $data, float $budget, float $minPayout, float $maxPayout): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($userId, $data, $budget, $minPayout, $maxPayout) {
                assert_fraud_allowed($userId, 'seo.ad_budget', ['amount' => (string)$budget]);
                $debit = $this->walletService->pay(
                    $userId,
                    (string)$budget,
                    'irt',
                    [
                        'type' => 'seo_ad',
                        'description' => 'SEO Ad: ' . $data['keyword'],
                        'ref_type' => 'seo_ad',
                    ]
                );
                
                if (empty($debit['success'])) {
                    throw new \RuntimeException((is_string($debit['message'] ?? null) ? $debit['message'] : '?????? ???? ????.'));
                }
        
                $adId = $this->adModel->create([
                    'user_id' => $userId,
                    'type' => 'seo',
                    'site_url' => $data['site_url'],
                    'title' => $data['title'] ?? $data['keyword'],
                    'keyword' => $data['keyword'],
                    'description' => $data['description'] ?? null,
                    'budget' => $budget,
                    'remaining_budget' => $budget,
                    'min_payout' => $minPayout,
                    'max_payout' => $maxPayout,
                    'target_duration' => int_value($data['target_duration'] ?? 60),
                    'min_score' => int_value($data['min_score'] ?? 40),
                    'max_per_day' => int_value($data['max_per_day'] ?? 10),
                    'deadline' => !empty($data['deadline']) ? $data['deadline'] : null,
                    'status' => 'pending',
                ]);
        
                if (!$adId) {
                    throw new \RuntimeException('??? ?? ??? ???? ?? ???????.');
                }
                
                return ['success' => true, 'ad_id' => $adId];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_ad.create_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Data access helpers (replacing the old SeoRepository)
    public function getAd(int $adId): ?object
    {
        $ad = $this->toObject($this->adModel->find($adId));
        if (!$ad) { 
        return null;
        }
        return $ad;
    }

    public function getAdForUpdate(int $adId): ?\stdClass
    {
        $ad = $this->toObject($this->adModel->findByIdForUpdate($adId));
        if (!$ad) { 
        return null;
        }
        return $ad;
    }

    /** @param array<string, mixed> $data */
    public function createExecution(array $data): int|false
    {
        $execution = $this->executionModel->createExecution($data);
        return $execution ? (int)$execution->id : false;
    }

    public function executionExistsToday(int $adId, int $userId): bool
    {
        return $this->executionModel->existsByAdAndUserToday($adId, $userId);
    }

    public function countUserExecutionsToday(int $userId): int
    {
        return $this->executionModel->countByUserToday($userId);
    }

    public function countUserExecutionsLastHour(int $userId): int
    {
        return $this->executionModel->countByUserLastHour($userId);
    }

    public function countIpExecutionsLastHour(string $ip): int
    {
        return $this->executionModel->countByIPLastHour($ip);
    }

    public function updateExecutionStatus(int $executionId, string $status): bool
    {
        return (bool)$this->db->query('UPDATE seo_executions SET status = ?, updated_at = ? WHERE id = ?', [$status, date('Y-m-d H:i:s'), $executionId]);
    }

    public function rejectExecution(int $executionId, string $reason, ?int $fraudScore = null): bool
    {
        return (bool)$this->db->query(
            'UPDATE seo_executions SET status = ?, rejection_reason = ?, fraud_flags = ?, fraud_score = ?, updated_at = ? WHERE id = ?',
            ['rejected', $reason, json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE), $fraudScore, date('Y-m-d H:i:s'), $executionId]
        );
    }

    public function cancelExecution(int $executionId, string $reason): bool
    {
        return (bool)$this->db->query(
            'UPDATE seo_executions SET status = ?, cancel_reason = ?, updated_at = ? WHERE id = ? AND status = ?',
            ['cancelled', $reason, date('Y-m-d H:i:s'), $executionId, 'started']
        );
    }

    /** @param array<string, mixed> $scores */
    public function completeExecution(int $executionId, array $scores, string $payout): bool
    {
        return $this->executionModel->complete($executionId, $scores, $payout);
    }

    /** @param array<string, mixed> $flags */
    public function markExecutionAsFraud(int $executionId, array $flags): bool
    {
        return $this->executionModel->markAsFraud($executionId, $flags);
    }

    public function getUser(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }

    public function approveAd(int $adId): bool
    {
        $ad = $this->toObject($this->adModel->find($adId));
        if (!$ad) { return false; }

        $ok = $this->adModel->update($adId, [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.approved', "???? SEO #{$adId} ????? ??", user_id(), ['ad_id' => $adId]);
            try {
                $this->outbox?->record('seo_ad', $adId, 'seo_ad.approved', [
                        'ad_id' => $adId,
                        'module' => 'seo_ad',
                        'type' => 'seo_ad'
                    ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.approved.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }

    public function rejectAd(int $adId, string $reason): bool
    {
        $result = $this->closeAndRefundBudget($adId, 'rejected', $reason ?: 'رد شده توسط ادمین', (int)(function_exists('user_id') ? user_id() : 0));
        $ok = !empty($result['success']);

        if ($ok) {
            $this->logger->activity('seo_ad.rejected', "SEO #{$adId} rejected", function_exists('user_id') ? user_id() : null, ['ad_id' => $adId, 'reason' => $reason]);
            try {
                $this->outbox?->record('seo_ad', $adId, 'seo_ad.rejected', [
                    'ad_id' => $adId,
                    'module' => 'seo_ad',
                    'type' => 'seo_ad',
                    'refund_amount' => $result['refund_amount'] ?? 0,
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.rejected.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }


    /**
     * Close a SEO campaign and refund remaining locked budget.
     * For campaigns created through AdSystemManager, escrow amount includes remaining campaign budget
     * plus any not-yet-settled fee. We refund the remaining escrow to prevent locked funds from getting stuck.
     */
    /** @return array<string, mixed> */
    public function closeAndRefundBudget(int $adId, string $status, string $reason, int $actorId = 0): array
    {
        $allowedStatuses = ['cancelled', 'rejected', 'expired', 'completed'];
        if (!in_array($status, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'وضعیت بستن کمپین معتبر نیست.'];
        }

        try {
            $this->db->beginTransaction();
            $ad = $this->toObject($this->adModel->findByIdForUpdate($adId));
            if (!$ad || !isset($ad->id) || (string)$ad->type !== 'seo') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'کمپین SEO یافت نشد.'];
            }
            if (in_array((string)$ad->status, ['cancelled', 'rejected', 'expired', 'completed'], true) && floatval($ad->remaining_budget ?? 0) <= 0) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'کمپین قبلاً بسته شده است.', 'refund_amount' => 0.0];
            }

            $currency = strtolower((string)($ad->currency ?? 'irt')) === 'usdt' ? 'usdt' : 'irt';
            $balanceField = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
            $lockedField = $currency === 'usdt' ? 'locked_usdt' : 'locked_irt';
            $refundAmount = 0.0;
            $escrow = $this->toObject($this->db->fetch(
                "SELECT * FROM escrow_transactions
                 WHERE order_id = ? AND order_type = 'seo_ad_budget'
                   AND status IN ('pending','in_escrow','partial')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [(string)$adId]
            ));

            if ($escrow) {
                $refundAmount = (float)$escrow->amount;
                if ($refundAmount > 0) {
                    $releaseResult = $this->walletService->releaseLockedFunds(
                        (int)$ad->user_id, (string)$refundAmount, $currency,
                        ['type' => 'seo_ad_refund', 'ref_id' => $adId, 'ref_type' => 'seo_ad', 'description' => "بازگشت بودجه SEO: {$reason}"]
                    );
                    if (empty($releaseResult['success'])) {
                        throw new \RuntimeException('خطا در آزادسازی بودجه SEO: ' . ((is_string($releaseResult['message'] ?? null) ? $releaseResult['message'] : '')));
                    }
                }
                $this->db->query(
                    "UPDATE escrow_transactions SET status = 'refunded', amount = 0, refunded_at = NOW(), refund_reason = ?, refunded_by = ?, updated_at = NOW() WHERE id = ?",
                    [$reason, $actorId > 0 ? 'admin_' . $actorId : 'system', (int)$escrow->id]
                );
            } else {
                // Legacy campaigns may have been funded by direct wallet withdrawal without escrow.
                $refundAmount = max(0.0, floatval($ad->remaining_budget ?? 0));
                if ($refundAmount > 0) {
                    $releaseResult = $this->walletService->releaseLockedFunds(
                        (int)$ad->user_id,
                        (string)$refundAmount,
                        $currency,
                        ['type' => 'seo_ad_refund', 'ref_id' => (int)$ad->id, 'ref_type' => 'seo_ad', 'description' => 'بازگشت بودجه باقی‌مانده تبلیغ سئو']
                    );
                    if (empty($releaseResult['success'])) {
                        throw new \RuntimeException('خطا در بازگشت بودجه: ' . ((is_string($releaseResult['message'] ?? null) ? $releaseResult['message'] : '')));
                    }
                    $this->recordSeoRefundTransaction((int)$ad->user_id, (string)$refundAmount, $currency, $adId, $reason, $actorId);
                }
            }

            $this->adModel->update($adId, [
                'status' => $status,
                'reject_reason' => $status === 'rejected' ? $reason : ($ad->reject_reason ?? null),
                'remaining_budget' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();
            return ['success' => true, 'message' => 'کمپین بسته و بودجه باقی‌مانده آزاد شد.', 'refund_amount' => $refundAmount, 'status' => $status];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            $this->logger->error('seo_ad.close_refund_failed', ['ad_id' => $adId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function closeExhaustedCampaigns(int $limit = 100): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id, deadline, remaining_budget, min_payout
             FROM ads
             WHERE type = 'seo' AND status = 'active'
               AND (remaining_budget <= 0 OR (min_payout > 0 AND remaining_budget < min_payout) OR (deadline IS NOT NULL AND deadline < NOW()))
             ORDER BY id ASC LIMIT " . max(1, min(500, $limit))
        );
        $closed = 0;
        foreach ($rows as $row) {
            $status = (!empty($row->deadline) && strtotime((string)$row->deadline) < time()) ? 'expired' : 'completed';
            $res = $this->closeAndRefundBudget((int)$row->id, $status, $status === 'expired' ? 'پایان مهلت کمپین SEO' : 'اتمام بودجه قابل پرداخت SEO');
            if (!empty($res['success'])) $closed++;
        }
        return $closed;
    }

    private function recordSeoRefundTransaction(int $userId, string $amount, string $currency, int $adId, string $reason, int $actorId = 0): void
    {
        $transactionId = 'seo_refund_' . $adId . '_' . bin2hex(random_bytes(6));
        $this->db->query(
            "INSERT INTO transactions
                (transaction_id, user_id, type, currency, amount, balance_before, balance_after, status, description, ref_id, ref_type, ip_address, device_fingerprint, metadata, created_at, updated_at, completed_at)
             VALUES (?, ?, 'seo_ad_refund', ?, ?, 0, 0, 'completed', ?, ?, 'seo_ad', 'system', 'seo-refund', ?, NOW(), NOW(), NOW())",
            [
                $transactionId,
                $userId,
                $currency,
                $amount,
                'بازگشت بودجه باقی‌مانده کمپین SEO: ' . $reason,
                (string)$adId,
                json_encode(['ad_id' => $adId, 'reason' => $reason, 'actor_id' => $actorId], JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    public function pauseAd(int $adId): bool
    {
        $ad = $this->toObject($this->adModel->find($adId));

        $ok = $this->adModel->update($adId, [
            'status' => 'paused',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.paused', "???? SEO #{$adId} ????? ??", user_id(), ['ad_id' => $adId]);
            try {
                $this->outbox?->record('seo_ad', $adId, 'seo_ad.paused', [
                        'ad_id' => $adId,
                        'module' => 'seo_ad',
                        'type' => 'seo_ad'
                    ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.paused.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }
}
