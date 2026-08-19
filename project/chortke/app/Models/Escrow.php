<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class Escrow extends Model
{
    protected static string $table = 'escrow_transactions';

    public function findByOrderId(int|string $orderId, string $orderType, string $excludeStatus = 'refunded'): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT id FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? AND status != ? 
             LIMIT 1",
            [$orderId, $orderType, $excludeStatus]
        );

        $result = $this->fetchObject($stmt);
        return $result ?: null;
    }

    public function createEscrow(
        int|string $orderId,
        string $orderType,
        int $buyerId,
        int $sellerId,
        string $amount,
        string $currency = 'USDT'
    ): int|false {
        $stmt = $this->db->prepare(
            "INSERT INTO escrow_transactions 
             (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $result = $stmt->execute([
            $orderId,
            $orderType,
            $buyerId,
            $sellerId,
            $amount,
            $currency,
            'pending',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', (strtotime('+30 days') ?: time())),
        ]);

        return $result ? $this->db->lastInsertId() : false;
    }

    public function findPendingForConfirm(int|string $orderId, string $orderType, int $sellerId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? AND seller_id = ?
             AND status = 'pending' FOR UPDATE",
            [$orderId, $orderType, $sellerId]
        );

        return $this->fetchObject($stmt);
    }

    public function confirmHold(int $escrowId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'in_escrow', confirmed_at = ?
             WHERE id = ? AND status = 'pending'"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function confirmHoldWithTransaction(int $orderId, string $orderType, int $sellerId): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findPendingForConfirm($orderId, $orderType, $sellerId);
            if (!$escrow) {
                $this->db->rollback();
                return false;
            }

            $success = $this->confirmHold((int)$escrow->id);
            if ($success) {
                $this->logEscrowAction((int)$escrow->id, 'confirm_hold', (string)$escrow->amount, 'seller_' . $sellerId, 'Held funds confirmed');
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return false;
        }
    }

    public function findReleasable(int $escrowId, int $sellerId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE id = ? AND (seller_id = ? OR seller_id = 0 OR order_type IN ('custom_task_budget','seo_ad_budget','social_task_budget')) 
             AND status IN ('in_escrow', 'partial', 'pending')
             FOR UPDATE",
            [$escrowId, $sellerId]
        );

        return $this->fetchObject($stmt);
    }

    public function releaseFunds(int $escrowId, string $releasedBy): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'released', released_at = ?, released_by = ?
             WHERE id = ? AND status IN ('pending', 'in_escrow', 'partial')"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $releasedBy, $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function releaseFundsWithTransaction(int $escrowId, int $sellerId, string $releasedBy): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findReleasable($escrowId, $sellerId);
            if (!$escrow) {
                $this->db->rollback();
                return false;
            }

            $success = $this->releaseFunds($escrowId, $releasedBy);
            if ($success) {
                $this->logEscrowAction($escrowId, 'release_funds', (string)$escrow->amount, $releasedBy, 'Escrow funds released');
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return false;
        }
    }

    public function logEscrowAction(int $escrowId, string $action, string $amount, string $performedBy, ?string $note = null): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO escrow_audit 
             (escrow_id, action, amount, performed_by, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $result = $stmt->execute([
            $escrowId,
            $action,
            $amount,
            $performedBy,
            $note,
            date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new \RuntimeException("Failed to log escrow action: {$action} for escrow {$escrowId}");
        }

        return true;
    }

    public function findRefundable(int $escrowId, int $buyerId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE id = ? AND buyer_id = ? 
             AND status IN ('in_escrow', 'pending', 'disputed')
             FOR UPDATE",
            [$escrowId, $buyerId]
        );

        return $this->fetchObject($stmt);
    }

    public function refundFunds(int $escrowId, string $reason, string $refundedBy): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'refunded', 
                 refunded_at = ?, 
                 refund_reason = ?,
                 refunded_by = ?
             WHERE id = ? AND status IN ('in_escrow', 'pending', 'disputed')"
        );

        $result = $stmt->execute([
            date('Y-m-d H:i:s'),
            $reason,
            $refundedBy,
            $escrowId
        ]);

        return $result && $stmt->rowCount() > 0;
    }

    public function refundFundsWithTransaction(int $escrowId, int $buyerId, string $reason, string $refundedBy): bool
    {
        try {
            $this->db->beginTransaction();

            $escrow = $this->findRefundable($escrowId, $buyerId);
            if (!$escrow) {
                $this->db->rollback();
                return false;
            }

            $success = $this->refundFunds($escrowId, $reason, $refundedBy);
            if ($success) {
                $this->logEscrowAction($escrowId, 'refund_funds', (string)$escrow->amount, $refundedBy, 'Escrow funds refunded: ' . $reason);
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return false;
        }
    }

    public function markDisputed(int $escrowId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE escrow_transactions 
             SET status = 'disputed', disputed_at = ?, dispute_reason = ?
             WHERE id = ? AND status IN ('in_escrow', 'pending')"
        );

        $result = $stmt->execute([date('Y-m-d H:i:s'), $reason, $escrowId]);
        return $result && $stmt->rowCount() > 0;
    }

    public function getStatus(int $escrowId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        );

        return $this->fetchObject($stmt);
    }

    public function getByOrder(int|string $orderId, string $orderType): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM escrow_transactions 
             WHERE order_id = ? AND order_type = ? 
             ORDER BY id DESC LIMIT 1",
            [$orderId, $orderType]
        );

        return $this->fetchObject($stmt);
    }

    public function isExpired(int $escrowId): bool
    {
        $stmt = $this->db->query(
            "SELECT expires_at FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        );

        $escrow = $this->fetchObject($stmt);
        return $escrow && strtotime($escrow->expires_at) < time();
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06.
     *
     * Return every escrow the user is involved in (either as buyer or
     * seller), joined with the counter-party identity columns the view
     * needs to render the list. EscrowController::index() used to inline
     * this query; we move it here so the view layer + controller stay
     * out of the JOIN business and the column list lives in one place.
     *
     * @return array<int,object>
     */
    public function getUserEscrowsWithParties(int $userId, int $limit = 50): array
    {
        $safeLimit = max(1, min(200, $limit));
        return $this->db->fetchAll(
            "SELECT et.*,
                    b.full_name AS buyer_name,  b.email AS buyer_email,
                    s.full_name AS seller_name, s.email AS seller_email
               FROM escrow_transactions et
          LEFT JOIN users b ON b.id = et.buyer_id
          LEFT JOIN users s ON s.id = et.seller_id
              WHERE et.buyer_id = ? OR et.seller_id = ?
           ORDER BY et.created_at DESC
              LIMIT {$safeLimit}",
            [$userId, $userId]
        ) ?: [];
    }
}
