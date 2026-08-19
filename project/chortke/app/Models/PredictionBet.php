<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class PredictionBet extends Model
{
    protected static string $table = 'prediction_bets';

    // وضعیت‌های مجاز برای شرط
    public const STATUS_PENDING  = 'pending';
    public const STATUS_WON      = 'won';
    public const STATUS_LOST     = 'lost';
    public const STATUS_REFUNDED = 'refunded';

    // ─── ثبت شرط جدید ─────────────────────────────────────────────────
    /** @param array<string, mixed> $d */
    public function createBet(array $d): ?\stdClass
    {
        $id = $this->db->insert(
            "INSERT INTO prediction_bets
                (user_id, game_id, prediction, amount, amount_usdt, currency, payment_transaction_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'usdt', ?, 'pending', NOW())",
            [
                int_value($d['user_id'] ?? 0),
                int_value($d['game_id'] ?? 0),
                str_value($d['prediction'] ?? ''),
                str_value($d['amount_usdt'] ?? 0),
                str_value($d['amount_usdt'] ?? 0),
                $d['payment_transaction_id'] ?? $d['transaction_id'] ?? null,
            ]
        );

        if (!$id) {
            return null;
        }

        return $this->db->fetch(
            "SELECT * FROM prediction_bets WHERE id = ?",
            [(int)$id]
        );
    }

    /**
     * ثبت شرط جدید تحت تراکنش برای حل همزمانی و جلوگیری از شرط تکراری
     */
    /** @param array<string, mixed> $d */
    public function createWithTransaction(array $d): ?\stdClass
    {
        try {
            $this->db->beginTransaction();

            $hasBet = $this->userHasBetForUpdate(int_value($d['user_id'] ?? 0), int_value($d['game_id'] ?? 0));
            if ($hasBet) {
                $this->db->rollback();
                return null;
            }

            $bet = $this->createBet($d);
            if ($bet) {
                $this->db->commit();
                return $bet;
            }

            $this->db->rollback();
            return null;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return null;
        }
    }

    // ─── شرط‌های یک کاربر با اطلاعات بازی ───────────────────────────
    /** @return list<\stdClass> */
    public function getByUser(int $userId, int $limit = 30, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT pb.*,
                    pg.title        AS game_title,
                    pg.team_home,
                    pg.team_away,
                    pg.match_date,
                    pg.result       AS game_result,
                    pg.status       AS game_status,
                    pg.sport_type
             FROM prediction_bets pb
             LEFT JOIN prediction_games pg ON pg.id = pb.game_id
             WHERE pb.user_id = ?
             ORDER BY pb.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public function countByUser(int $userId): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM prediction_bets WHERE user_id = ?",
            [$userId]
        );
        return (int)($row->cnt ?? 0);
    }

    /**
     * خلاصه دقیق پیش‌بینی‌های کاربر برای کارت‌های آماری Hub.
     * PRIMARY_READ_MODEL: فقط read-only است و هیچ مسیر مالی را تغییر نمی‌دهد.
     */
    public function getUserSummary(int $userId): object
    {
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_bets,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_bets,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won_bets,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_bets,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS refunded_bets,
                COALESCE(SUM(amount_usdt), 0) AS total_stake_usdt,
                COALESCE(SUM(CASE WHEN status = 'won' THEN payout_usdt ELSE 0 END), 0) AS total_payout_usdt,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount_usdt ELSE 0 END), 0) AS total_refunded_usdt
             FROM prediction_bets
             WHERE user_id = ?",
            [$userId]
        );

        return $row ?? (object)[
            'total_bets' => 0,
            'pending_bets' => 0,
            'won_bets' => 0,
            'lost_bets' => 0,
            'refunded_bets' => 0,
            'total_stake_usdt' => '0',
            'total_payout_usdt' => '0',
            'total_refunded_usdt' => '0',
        ];
    }

    // ─── همه شرط‌های یک بازی با اطلاعات کاربر ──────────────────────
    /** @return list<\stdClass> */
    public function getByGame(int $gameId): array
    {
        return $this->db->fetchAll(
            "SELECT pb.*,
                    u.full_name,
                    u.email,
                    u.username
             FROM prediction_bets pb
             LEFT JOIN users u ON u.id = pb.user_id
             WHERE pb.game_id = ?
             ORDER BY pb.created_at DESC",
            [$gameId]
        );
    }

    // ─── شرط‌های برنده یک بازی ────────────────────────────────────────
    /** @return list<\stdClass> */
    public function getWinnersByGame(int $gameId, string $winningPrediction): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM prediction_bets
             WHERE game_id = ? AND prediction = ? AND status = 'pending'",
            [$gameId, $winningPrediction]
        );
    }

    /** @return list<\stdClass> */
    public function getLosersByGame(int $gameId, string $winningPrediction): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM prediction_bets
             WHERE game_id = ? AND prediction <> ? AND status = 'pending'",
            [$gameId, $winningPrediction]
        );
    }

    // ─── همه شرط‌های فعال یک بازی (برای refund/settlement) ─────────────────────
    /** @return list<\stdClass> */
    /** @return list<\stdClass> */
    public function getPendingByGame(int $gameId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM prediction_bets
             WHERE game_id = ? AND status = 'pending'",
            [$gameId]
        );
    }

    // ─── بررسی شرط قبلی (با FOR UPDATE برای استفاده داخل transaction) ──
    public function userHasBetForUpdate(int $userId, int $gameId): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM prediction_bets
              WHERE user_id = ? AND game_id = ?
              LIMIT 1
              FOR UPDATE",
            [$userId, $gameId]
        );

        return $row !== null;
    }

    // ─── بدون قفل (برای نمایش) ────────────────────────────────────────
    public function userHasBet(int $userId, int $gameId): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM prediction_bets WHERE user_id = ? AND game_id = ? LIMIT 1",
            [$userId, $gameId]
        );

        return $row !== null;
    }

    // ─── بروزرسانی وضعیت با پرداخت ──────────────────────────────────
    public function markWon(int $betId, float|string $payoutUsdt, ?string $payoutTransactionId = null): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'won', payout_usdt = ?, payout_transaction_id = ?, settled_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [(string)$payoutUsdt, $payoutTransactionId, $betId]
        );

        return $affected > 0;
    }

    public function markLost(int $betId): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'lost', payout_usdt = 0, settled_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$betId]
        );

        return $affected > 0;
    }

    public function markRefunded(int $betId, ?string $refundTransactionId = null): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'refunded', refund_transaction_id = ?, settled_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$refundTransactionId, $betId]
        );

        return $affected > 0;
    }

    public function countByGame(int $gameId): int
    {
        $res = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM prediction_bets WHERE game_id = ?",
            [$gameId]
        );
        return (int)($res->cnt ?? 0);
    }

    // ─── آمار توزیع شرط‌ها برای یک بازی ─────────────────────────────
    public function getDistribution(int $gameId): object
    {
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_bets,
                CAST(COALESCE(SUM(amount_usdt), 0) AS CHAR) AS total_pool,
                CAST(COALESCE(SUM(CASE WHEN prediction='home' THEN amount_usdt ELSE 0 END), 0) AS CHAR) AS pool_home,
                CAST(COALESCE(SUM(CASE WHEN prediction='away' THEN amount_usdt ELSE 0 END), 0) AS CHAR) AS pool_away,
                CAST(COALESCE(SUM(CASE WHEN prediction='draw' THEN amount_usdt ELSE 0 END), 0) AS CHAR) AS pool_draw,
                COUNT(CASE WHEN prediction='home' THEN 1 END) AS count_home,
                COUNT(CASE WHEN prediction='away' THEN 1 END) AS count_away,
                COUNT(CASE WHEN prediction='draw' THEN 1 END) AS count_draw
             FROM prediction_bets
             WHERE game_id = ? AND status != 'refunded'",
            [$gameId]
        );

        return $row ?? (object)[
            'total_bets' => 0, 'total_pool' => '0',
            'pool_home'  => '0', 'pool_away'  => '0', 'pool_draw'  => '0',
            'count_home' => 0, 'count_away' => 0, 'count_draw' => 0,
        ];
    }
}
