<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class PredictionGame extends Model
{
    protected static string $table = 'prediction_games';

    // ─── Find با آمار کامل بهینه‌سازی شده ────────────────────────────
    public function find(int $id): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT pg.*, 
                    COALESCE(stats.total_bets, 0) AS total_bets,
                    COALESCE(stats.total_pool, 0) AS total_pool,
                    COALESCE(stats.pool_home, 0) AS pool_home,
                    COALESCE(stats.pool_away, 0) AS pool_away,
                    COALESCE(stats.pool_draw, 0) AS pool_draw
             FROM prediction_games pg
             LEFT JOIN (
                 SELECT game_id,
                        COUNT(*) AS total_bets,
                        COALESCE(SUM(amount_usdt), 0) AS total_pool,
                        COALESCE(SUM(CASE WHEN prediction = 'home' THEN amount_usdt ELSE 0 END), 0) AS pool_home,
                        COALESCE(SUM(CASE WHEN prediction = 'away' THEN amount_usdt ELSE 0 END), 0) AS pool_away,
                        COALESCE(SUM(CASE WHEN prediction = 'draw' THEN amount_usdt ELSE 0 END), 0) AS pool_draw
                 FROM prediction_bets
                 WHERE status != 'refunded'
                 GROUP BY game_id
             ) stats ON stats.game_id = pg.id
             WHERE pg.id = ? AND pg.deleted_at IS NULL",
            [$id]
        );
    }

    // ─── Create با validation کامل ────────────────────────────────────
    /** @param array<string, mixed> $d */
    public function createGame(array $d): ?\stdClass
    {
        $bonusPool = $this->consumeRolloverReserve();

        $id = $this->db->insert(
            "INSERT INTO prediction_games
                (title, team_home, team_away, sport_type, match_date, bet_deadline,
                 min_bet_usdt, max_bet_usdt, commission_percent, bonus_pool_usdt, status,
                 description, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, NOW())",
            [
                $d['title'],
                $d['team_home'],
                $d['team_away'],
                $d['sport_type']           ?? 'football',
                $d['match_date'],
                $d['bet_deadline'],
                str_value($d['min_bet_usdt'] ?? 1),
                str_value($d['max_bet_usdt'] ?? 1000),
                str_value($d['commission_percent'] ?? 5),
                $bonusPool,
                $d['description']          ?? null,
                int_value($d['created_by'] ?? 0),
            ]
        );

        return $id ? $this->find((int)$id) : null;
    }

    private function consumeRolloverReserve(): string
    {
        // M-17 FIX: reading the reserve and then resetting it to 0 in two separate statements let a
        // contribution that landed in between get wiped out. The read-and-clear is now a single
        // atomic step: instead of overwriting with 0 we subtract exactly the amount we consumed
        // (value = value - consumed) under a row lock, so any concurrent addRolloverReserve() is
        // preserved. A transaction with SELECT ... FOR UPDATE serialises against other consumers.
        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            $reserve = (string)($this->db->fetchColumn("SELECT `value` FROM system_settings WHERE `key` = 'prediction_rollover_reserve_usdt' LIMIT 1 FOR UPDATE") ?? '0');
            if (!is_numeric($reserve) || bccomp($reserve, '0', 8) <= 0) {
                if ($startedTransaction) $this->db->commit();
                return '0';
            }

            $this->db->execute(
                "UPDATE system_settings SET `value` = CAST(`value` AS DECIMAL(30,8)) - CAST(? AS DECIMAL(30,8)), updated_at = NOW() WHERE `key` = 'prediction_rollover_reserve_usdt'",
                [$reserve]
            );

            if ($startedTransaction) $this->db->commit();
            return $reserve;
        } catch (\Throwable) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            return '0';
        }
    }

    // ─── لیست بازی‌های باز برای کاربران با آمار بهینه شده ─────────────
    /** @return list<\stdClass> */
    public function getOpen(int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT pg.*, 
                    COALESCE(stats.total_bets, 0) AS total_bets,
                    COALESCE(stats.total_pool, 0) AS total_pool,
                    COALESCE(stats.pool_home, 0) AS pool_home,
                    COALESCE(stats.pool_away, 0) AS pool_away,
                    COALESCE(stats.pool_draw, 0) AS pool_draw
             FROM prediction_games pg
             LEFT JOIN (
                 SELECT game_id,
                        COUNT(*) AS total_bets,
                        COALESCE(SUM(amount_usdt), 0) AS total_pool,
                        COALESCE(SUM(CASE WHEN prediction = 'home' THEN amount_usdt ELSE 0 END), 0) AS pool_home,
                        COALESCE(SUM(CASE WHEN prediction = 'away' THEN amount_usdt ELSE 0 END), 0) AS pool_away,
                        COALESCE(SUM(CASE WHEN prediction = 'draw' THEN amount_usdt ELSE 0 END), 0) AS pool_draw
                 FROM prediction_bets
                 WHERE status != 'refunded'
                 GROUP BY game_id
             ) stats ON stats.game_id = pg.id
             WHERE pg.status = 'open'
               AND pg.bet_deadline > NOW()
               AND pg.deleted_at IS NULL
             ORDER BY pg.match_date ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * لیست عمومی بازی‌های اخیر برای Hub کاربر.
     *
     * PRIMARY_READ_MODEL: این متد برای نمایش نتایج/بازی‌های بسته در Hub پیش‌بینی است
     * و مسیر مالی یا عملیات ادمین را دور نمی‌زند.
     *
     * @param array<int,string> $statuses
     */
    /** @return list<\stdClass> */
    /** @param list<string> $statuses */
    /**
     * @param list<string> $statuses
     * @return list<\stdClass>
     */
    public function getPublicRecent(array $statuses = ['finished', 'cancelled'], int $limit = 12, int $offset = 0): array
    {
        $allowed = ['open', 'closed', 'finished', 'cancelled'];
        $statuses = array_values(array_intersect($statuses, $allowed));
        if (empty($statuses)) {
            $statuses = ['finished', 'cancelled'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge($statuses, [$limit, $offset]);

        return $this->db->fetchAll(
            "SELECT pg.*,
                    COALESCE(stats.total_bets, 0) AS total_bets,
                    COALESCE(stats.total_pool, 0) AS total_pool,
                    COALESCE(stats.pool_home, 0) AS pool_home,
                    COALESCE(stats.pool_away, 0) AS pool_away,
                    COALESCE(stats.pool_draw, 0) AS pool_draw
             FROM prediction_games pg
             LEFT JOIN (
                 SELECT game_id,
                        COUNT(*) AS total_bets,
                        COALESCE(SUM(amount_usdt), 0) AS total_pool,
                        COALESCE(SUM(CASE WHEN prediction = 'home' THEN amount_usdt ELSE 0 END), 0) AS pool_home,
                        COALESCE(SUM(CASE WHEN prediction = 'away' THEN amount_usdt ELSE 0 END), 0) AS pool_away,
                        COALESCE(SUM(CASE WHEN prediction = 'draw' THEN amount_usdt ELSE 0 END), 0) AS pool_draw
                 FROM prediction_bets
                 WHERE status != 'refunded'
                 GROUP BY game_id
             ) stats ON stats.game_id = pg.id
             WHERE pg.status IN ($placeholders)
               AND pg.deleted_at IS NULL
             ORDER BY COALESCE(pg.finished_at, pg.match_date, pg.created_at) DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * خلاصه دقیق برای کارت‌های آماری پنل ادمین.
     */
    /** @param array<string, mixed> $filters */
    public function adminSummary(array $filters = []): object
    {
        $where  = ['pg.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'pg.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['sport_type'])) {
            $where[]  = 'pg.sport_type = ?';
            $params[] = $filters['sport_type'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(pg.title LIKE ? OR pg.team_home LIKE ? OR pg.team_away LIKE ?)';
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_games,
                SUM(CASE WHEN pg.status = 'open' THEN 1 ELSE 0 END) AS open_games,
                SUM(CASE WHEN pg.status = 'closed' THEN 1 ELSE 0 END) AS closed_games,
                SUM(CASE WHEN pg.status = 'finished' THEN 1 ELSE 0 END) AS finished_games,
                SUM(CASE WHEN pg.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_games,
                COALESCE(SUM(stats.total_bets), 0) AS total_bets,
                COALESCE(SUM(stats.total_pool), 0) AS total_pool,
                COALESCE(SUM(pg.site_fee_usdt), 0) AS site_fee_usdt,
                COALESCE(SUM(pg.rollover_amount_usdt), 0) AS rollover_amount_usdt
             FROM prediction_games pg
             LEFT JOIN (
                 SELECT game_id,
                        COUNT(*) AS total_bets,
                        COALESCE(SUM(amount_usdt), 0) AS total_pool
                 FROM prediction_bets
                 WHERE status != 'refunded'
                 GROUP BY game_id
             ) stats ON stats.game_id = pg.id
             WHERE " . implode(' AND ', $where),
            $params
        );

        return $row ?? (object)[
            'total_games' => 0,
            'open_games' => 0,
            'closed_games' => 0,
            'finished_games' => 0,
            'cancelled_games' => 0,
            'total_bets' => 0,
            'total_pool' => '0',
            'site_fee_usdt' => '0',
            'rollover_amount_usdt' => '0',
        ];
    }

    // ─── لیست ادمین با فیلتر با آمار بهینه شده ───────────────────────
    /** @param array<string, mixed> $filters */
    /** @return list<\stdClass> */
    /** @param array<string, mixed> $filters */
    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where  = ['pg.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'pg.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['sport_type'])) {
            $where[]  = 'pg.sport_type = ?';
            $params[] = $filters['sport_type'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(pg.title LIKE ? OR pg.team_home LIKE ? OR pg.team_away LIKE ?)';
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll(
            "SELECT pg.*, 
                    COALESCE(stats.total_bets, 0) AS total_bets,
                    COALESCE(stats.total_pool, 0) AS total_pool,
                    COALESCE(stats.pool_home, 0) AS pool_home,
                    COALESCE(stats.pool_away, 0) AS pool_away,
                    COALESCE(stats.pool_draw, 0) AS pool_draw
             FROM prediction_games pg
             LEFT JOIN (
                 SELECT game_id,
                        COUNT(*) AS total_bets,
                        COALESCE(SUM(amount_usdt), 0) AS total_pool,
                        COALESCE(SUM(CASE WHEN prediction = 'home' THEN amount_usdt ELSE 0 END), 0) AS pool_home,
                        COALESCE(SUM(CASE WHEN prediction = 'away' THEN amount_usdt ELSE 0 END), 0) AS pool_away,
                        COALESCE(SUM(CASE WHEN prediction = 'draw' THEN amount_usdt ELSE 0 END), 0) AS pool_draw
                 FROM prediction_bets
                 WHERE status != 'refunded'
                 GROUP BY game_id
             ) stats ON stats.game_id = pg.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY pg.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /** @param array<string, mixed> $filters */
    public function adminCount(array $filters = []): int
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['sport_type'])) {
            $where[]  = 'sport_type = ?';
            $params[] = $filters['sport_type'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(title LIKE ? OR team_home LIKE ? OR team_away LIKE ?)';
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM prediction_games WHERE " . implode(' AND ', $where),
            $params
        );

        return (int)($row->cnt ?? 0);
    }

    // ─── ثبت نتیجه و بستن بازی برای شرط‌های جدید ────────────────────
    public function setResult(int $id, string $result): bool
    {
        if (!\in_array($result, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException("Invalid prediction result: " . $result);
        }

        $affected = $this->db->execute(
            "UPDATE prediction_games
             SET result = ?, status = 'finished', finished_at = NOW()
             WHERE id = ? AND status IN ('open','closed') AND deleted_at IS NULL",
            [$result, $id]
        );

        return $affected > 0;
    }

    // ─── بستن بازی (توقف شرط‌های جدید) ──────────────────────────────
    public function closeBetting(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET status = 'closed'
             WHERE id = ? AND status = 'open' AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }

    // ─── لغو بازی ────────────────────────────────────────────────────
    public function cancel(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games
             SET status = 'cancelled', cancelled_at = NOW()
             WHERE id = ? AND status IN ('open','closed') AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }

    /** @param array<string, mixed> $summary */
    public function updateSettlementSummary(int $id, array $summary): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games
             SET site_fee_usdt = ?, rollover_amount_usdt = ?, settlement_policy = ?, settlement_summary = ?, updated_at = NOW()
             WHERE id = ?",
            [
                str_value($summary['site_fee_amount'] ?? $summary['site_fee'] ?? '0'),
                str_value($summary['rollover_amount'] ?? '0'),
                str_value($summary['settlement_policy'] ?? 'loser_pool_commission'),
                json_encode($summary, JSON_UNESCAPED_UNICODE),
                $id,
            ]
        );

        return $affected >= 0;
    }

    public function addRolloverReserve(string $amount): void
    {
        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            return;
        }
        // M-17 FIX: the previous read-then-write (SELECT value → bcadd → overwrite) was a
        // lost-update race — two concurrent settlements both read the same base and each wrote
        // base+own, silently dropping one contribution. The addition is now performed atomically
        // inside the DB engine so concurrent contributions accumulate without a lock or a re-read.
        $this->db->query(
            "INSERT INTO system_settings (`key`, `value`, `group`, `type`, `description`, is_public, created_at, updated_at)
             VALUES ('prediction_rollover_reserve_usdt', ?, 'prediction', 'numeric', 'ذخیره انتقالی پیش‌بینی‌ها برای بازی‌های بعدی', 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE `value` = CAST(`value` AS DECIMAL(30,8)) + CAST(VALUES(`value`) AS DECIMAL(30,8)), updated_at = NOW()",
            [$amount]
        );
    }

    // ─── علامت پرداخت برندگان ─────────────────────────────────────────
    public function markWinnersPaid(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET winners_paid = 1, paid_at = NOW()
             WHERE id = ? AND status = 'finished' AND winners_paid = 0",
            [$id]
        );

        return $affected > 0;
    }

    // ─── Soft delete ──────────────────────────────────────────────────
    public function softDelete(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET deleted_at = NOW()
             WHERE id = ? AND status IN ('finished','cancelled') AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }
}
