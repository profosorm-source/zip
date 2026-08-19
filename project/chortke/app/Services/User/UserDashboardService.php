<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Contracts\LoggerInterface;

use Core\Cache;

class UserDashboardService
{
    private function toObject(mixed $row): ?\stdClass
    {
        if ($row instanceof \stdClass) return $row;
        if (is_array($row)) return (object)$row;
        return null;
    }

    /** @return list<\stdClass> */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows)) return [];
        $result = [];
        foreach ($rows as $row) {
            $object = $this->toObject($row);
            if ($object === null) throw new \UnexpectedValueException('Dashboard query returned an invalid row.');
            $result[] = $object;
        }
        return $result;
    }






    private \Core\Cache $cache;
    private \Core\Database $db;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db
    ) {        $this->cache = $cache;
        $this->db = $db;

        
        }

    /** @return array<string, mixed> */
    public function getStats(int $userId): array
    {
        $cacheKey = "user_dashboard_stats:{$userId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            if (!is_array($cached)) throw new \UnexpectedValueException('Dashboard cache must contain an array.');
            return $cached;
        }

        // Optimized: Consolidated transaction statistics into a single query
        $todayStart = \date('Y-m-d 00:00:00');
        $todayEnd   = \date('Y-m-d 00:00:00', \strtotime('+1 day'));

        // 1. Transactions Summary
        //
        // BUGFIX-DASHBOARD-NAMED-PARAMS-2026-06:
        //   Core\Database->bindValue() binds each named parameter exactly
        //   once via PDO::bindValue(). With ATTR_EMULATE_PREPARES = false
        //   (set in createPdoConnection() for security), MySQL requires
        //   ONE bound value per occurrence — not per name. The previous
        //   query reused :start and :end in two SUM(CASE …) branches and
        //   was therefore failing every call with
        //     SQLSTATE[HY093]: Invalid parameter number
        //   which the surrounding try/catch swallowed silently, leaving
        //   the dashboard "earnings" chart and other metrics permanently
        //   at zero. We rewrite the query with positional ? placeholders
        //   that the binder can satisfy unambiguously.
        $txSummary = $this->toObject($this->db->fetch("
            SELECT
                SUM(CASE WHEN type = 'deposit'  AND status = 'completed' AND created_at >= ? AND created_at < ? THEN amount ELSE 0 END) as today_deposit,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' AND created_at >= ? AND created_at < ? THEN amount ELSE 0 END) as today_withdraw,
                COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) as pending_tx,
                SUM(CASE WHEN type IN ('task_reward', 'commission') AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as earnings_30d,
                SUM(CASE WHEN type = 'deposit'  AND status = 'completed' THEN amount ELSE 0 END) as total_deposits_irt,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdraws_irt
            FROM transactions
            WHERE user_id = ?",
            [$todayStart, $todayEnd, $todayStart, $todayEnd, $userId]
        ));
        if ($txSummary === null) $txSummary = new \stdClass();


        // 2. Wallet Info
        $walletInfo = $this->toObject($this->db->fetch("
            SELECT balance_irt, balance_usdt, locked_irt 
            FROM wallets 
            WHERE user_id = :uid LIMIT 1",
            ['uid' => $userId]
        ));
        if (!$walletInfo) {
            $walletInfo = (object)[
                'balance_irt' => 0.0,
                'balance_usdt' => 0.0,
                'locked_irt' => 0.0
            ];
        }

        // 3. Social Task Executions Statistics
        $taskSummary = $this->toObject($this->db->fetch("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN reward_amount ELSE 0 END), 0) as earned
            FROM social_task_executions
            WHERE executor_id = :uid",
            ['uid' => $userId]
        ));

        if ($taskSummary === null) $taskSummary = new \stdClass();

        // 4. Referral System Info
        $referralSummary = $this->toObject($this->db->fetch("
            SELECT 
                COUNT(*) as referred_count,
                COALESCE(SUM(commission_amount), 0) as total_earned_irt
            FROM referral_commissions
            WHERE referrer_id = :uid AND status = 'paid'",
            ['uid' => $userId]
        ));

        if ($referralSummary === null) $referralSummary = new \stdClass();

        // 5. Campaigns (Active Ads) Info
        $campaignsCount = (int)$this->db->fetchColumn("
            SELECT COUNT(*) 
            FROM ads 
            WHERE user_id = :uid",
            ['uid' => $userId]
        );

        $recentCampaigns = $this->toObjectArray($this->db->fetchAll("
            SELECT id, title, platform, task_type, remaining_count, status, created_at
            FROM ads
            WHERE user_id = :uid
            ORDER BY id DESC LIMIT 5",
            ['uid' => $userId]
        ));

        // 6. User Level Slug
        $userLevel = $this->toObject($this->db->fetch("
            SELECT level_slug, level_expires_at, level_type 
            FROM users 
            WHERE id = :uid LIMIT 1",
            ['uid' => $userId]
        ));

        if ($userLevel === null) $userLevel = new \stdClass();

        // 7. Recent Transactions List
        $lastTransactions = $this->toObjectArray($this->db->fetchAll("
            SELECT id, type, currency, amount, status, created_at
            FROM transactions
            WHERE user_id = :uid
            ORDER BY id DESC LIMIT 10",
            ['uid' => $userId]
        ));

        // 8. BUGFIX-DASHBOARD-CHARTS-2026-06:
        //    Earnings time-series for the last 30 days.
        //
        //    The dashboard view (views/user/dashboard.php) wires four
        //    Chart.js canvases — "درآمد", "تسک‌ها", "پلتفرم", "مالی" —
        //    against `$chartLabels` / `$chartData` and `$platformLabels`
        //    / `$platformData`. The Controller reads those from
        //    `$stats['charts']['earnings']` and `$stats['charts']['platforms']`,
        //    but the service NEVER populated that key. The view therefore
        //    fell back to 30 zeros and the chart rendered as a flat line
        //    (reported by E2E as "income tab is empty"). We fill the gap
        //    here with one extra query per chart, each constrained to the
        //    user's own rows and bounded to 30 days for speed.
        $earningsRows = $this->toObjectArray($this->db->fetchAll("
            SELECT DATE(created_at) AS d,
                   COALESCE(SUM(amount), 0) AS v
              FROM transactions
             WHERE user_id   = :uid
               AND type      IN ('task_reward', 'commission')
               AND status    = 'completed'
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at) ASC",
            ['uid' => $userId]
        ));

        // Build a dense 30-day series (fill zero on days with no rows so the
        // line chart shows a continuous x-axis, not a sparse one).
        /** @var array<string, float> $earningsByDate */
        $earningsByDate = [];
        if (is_array($earningsRows)) {
            foreach ($earningsRows as $r) {
                $earningsByDate[$r->d] = (float)$r->v;
            }
        }
        $earningsLabels = [];
        $earningsValues = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = \date('Y-m-d', \strtotime("-{$i} day") ?: time());
            $earningsLabels[] = \date('m-d', \strtotime($day) ?: time());
            $earningsValues[] = $earningsByDate[$day] ?? 0.0;
        }

        // 9. Platform breakdown for the doughnut chart — based on social
        //    task executions joined to ads to get the platform.
        //    We tolerate missing tables / columns by guarding with a try.
        $platformLabels = [];
        $platformValues = [];
        try {
            $platformRows = $this->toObjectArray($this->db->fetchAll("
                SELECT sa.platform AS p, COUNT(*) AS n
                  FROM social_task_executions ste
             LEFT JOIN ads sa ON sa.id = ste.ad_id
                 WHERE ste.executor_id = :uid
                   AND ste.status = 'approved'
              GROUP BY sa.platform
              ORDER BY n DESC
                 LIMIT 6",
                ['uid' => $userId]
            ));
            if (is_array($platformRows)) {
                foreach ($platformRows as $row) {
                    $platformLabels[] = (string)($row->p ?: 'سایر');
                    $platformValues[] = (int)$row->n;
                }
            }
        } catch (\Throwable $e) {
            // ads or social_task_executions might be missing on a
            // partially-migrated install — the view has its own fallback.
        }

        $stats = [
            'today_deposit'      => floatval($txSummary->today_deposit ?? 0),
            'today_withdraw'     => floatval($txSummary->today_withdraw ?? 0),
            'pending_tx'         => (int)($txSummary->pending_tx ?? 0),
            'earnings_30d'       => floatval($txSummary->earnings_30d ?? 0),
            'last_transactions'  => $lastTransactions,

            'wallet' => [
                'balance_irt' => (float)$walletInfo->balance_irt,
                'balance_usdt' => (float)$walletInfo->balance_usdt,
                'locked_irt' => (float)$walletInfo->locked_irt,
            ],
            'tasks' => [
                'completed' => (int)($taskSummary->completed ?? 0),
                'pending' => (int)($taskSummary->pending ?? 0),
                'rejected' => (int)($taskSummary->rejected ?? 0),
                'total' => (int)($taskSummary->total ?? 0),
                'earned' => floatval($taskSummary->earned ?? 0),
            ],
            'transactions' => [
                'total_deposits_irt' => floatval($txSummary->total_deposits_irt ?? 0),
                'total_withdraws_irt' => floatval($txSummary->total_withdraws_irt ?? 0),
                'pending_count' => (int)($txSummary->pending_tx ?? 0),
                'recent' => $lastTransactions,
            ],
            'campaigns' => [
                'total' => $campaignsCount,
                'recent' => $recentCampaigns,
            ],
            'level' => [
                'name' => strtoupper($userLevel->level_slug ?? 'silver'),
                'slug' => strtolower($userLevel->level_slug ?? 'silver'),
                'progress' => 0,
                'is_max' => ($userLevel->level_slug ?? 'silver') === 'diamond',
                'current' => $userLevel->level_slug ?? 'silver',
                'next' => null,
                'details' => [],
            ],
            'referral' => [
                'referred_count' => (int)($referralSummary->referred_count ?? 0),
                'total_earned_irt' => floatval($referralSummary->total_earned_irt ?? 0),
                'pending_irt' => 0.0,
                'paid_count' => (int)($referralSummary->referred_count ?? 0),
            ],
            // BUGFIX-DASHBOARD-CHARTS-2026-06: was missing, view fell back to zeros.
            'charts' => [
                'earnings'  => ['labels' => $earningsLabels, 'values' => $earningsValues],
                'platforms' => ['labels' => $platformLabels, 'values' => $platformValues],
            ],
        ];

        $this->cache->set($cacheKey, $stats, 60);

        return $stats;
    }

    /**
     * Get all dashboard data in a single call to minimize round-trips
     */
    /** @return array<string, mixed> */
    public function getFullDashboardData(int $userId): array
    {
        return [
            'stats' => $this->getStats($userId),
            'recent_executions' => $this->getRecentTaskExecutions($userId, 5),
            'ticket_count' => $this->getOpenTicketCount($userId)
        ];
    }

    /** @return list<\stdClass> */
    public function getRecentTaskExecutions(int $userId, int $limit = 5, int $offset = 0): array
    {
        $safeLimit = max(1, min(100, $limit));
        $safeOffset = max(0, $offset);

        // MED-03: Join against ads to surface critical visual metadata pointers to layout views
        return $this->db->fetchAll(
            "SELECT ste.*, sa.title AS ad_title, sa.platform AS ad_platform, sa.task_type AS ad_task_type
             FROM social_task_executions ste
             LEFT JOIN ads sa ON sa.id = ste.ad_id
             WHERE ste.executor_id = :uid
             ORDER BY ste.created_at DESC
             LIMIT :limit OFFSET :offset",
            [
                'uid' => $userId,
                'limit' => $safeLimit,
                'offset' => $safeOffset,
            ]
        );
    }

    public function getOpenTicketCount(int $userId): int
    {
        $cacheKey = "user_open_tickets:{$userId}";
        if (($cachedCount = $this->cache->get($cacheKey)) !== false) {
            return int_value($cachedCount);
        }

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE user_id = :uid AND status IN ('open', 'pending')",
            ['uid' => $userId]
        );

        // LOW-03: Cache open tickets count to lower synchronous execution impacts on shell load
        $this->cache->set($cacheKey, $count, 60);

        return $count;
    }
}
