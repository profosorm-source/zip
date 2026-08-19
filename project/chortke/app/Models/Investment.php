<?php

namespace App\Models;

use Core\Model;
use Core\Database;
use App\Traits\Filterable;

class Investment extends Model {
    use Filterable;

    protected static string $table = 'investments';

    /**
     * Relations برای eager loading با allWith/loadRelations
     *
     * استفاده:
     *   // لیست سرمایه‌گذاری‌ها با کاربر و سودهای هر کدام
     *   $investments = $investmentModel->allWith(['user', 'profits'], 50);
     *   echo $investments[0]->user->full_name;
     *   echo count($investments[0]->profits);
     *
     *   // یک سرمایه‌گذاری با همه جزئیات
     *   $inv = $investmentModel->findWith($id, ['user', 'profits', 'withdrawals']);
     */
    protected array $relations = [
        'user'        => [\App\Models\User::class,               'user_id',       'one'],
        'profits'     => [\App\Models\InvestmentProfit::class,    'investment_id', 'many'],
        'withdrawals' => [\App\Models\InvestmentWithdrawal::class, 'investment_id', 'many'],
    ];
    protected static array $searchable = ['u.full_name', 'u.email', 'i.status'];

    /** @var array<string, array{string, string}> */
    protected static array $filterable = [
        'status' => ['i.status', '='],
        'user_id' => ['i.user_id', '='],
    ];
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SUSPENDED = 'suspended';

    public const WITHDRAWAL_COOLDOWN_DAYS = 7;
    public const DEPOSIT_LOCK_DAYS = 7;

    /**
     * ایجاد سرمایه‌گذاری جدید
     * خروجی: id یا null
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        // مقدارهای پیش‌فرض
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        unset($data['deleted_at']);  // ❌ SECURITY: Never from user input

        if (!isset($data['status'])) {
            $data['status'] = self::STATUS_ACTIVE;
        }
        if (!isset($data['current_balance']) && isset($data['amount'])) {
            $data['current_balance'] = $data['amount'];
        }
        if (!isset($data['start_date'])) {
            $data['start_date'] = $now;
        }

        // ساخت INSERT داینامیک با لیست سفید مجاز
        $allowed = [
            'user_id', 'amount', 'current_balance', 'total_profit', 'total_loss',
            'status', 'transaction_id', 'last_withdrawal_date', 'deposit_lock_until', 'start_date',
            'created_at', 'updated_at', 'deleted_at'
        ];

        $filtered = [];
        foreach ($allowed as $k) {
            if (\array_key_exists($k, $data)) {
                $filtered[$k] = $data[$k];
            }
        }

        $columns = \array_keys($filtered);
        $values  = \array_values($filtered);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `investments` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT * FROM investments WHERE id = ? AND (deleted_at IS NULL OR deleted_at = 0) LIMIT 1",
            [$id]
        );
    }

    public function findForUpdate(int $id): ?\stdClass
    {
        return $this->find($id);
    }

    public function findWithUser(int $id): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT i.*, u.full_name as user_name, u.email as user_email
              FROM investments i
              JOIN users u ON i.user_id = u.id
              WHERE i.id = ? AND (i.deleted_at IS NULL OR i.deleted_at = 0)
              LIMIT 1",
            [$id]
        );
    }

    /**
     * سرمایه‌گذاری فعال کاربر (فقط یک پلن)
     */
    public function getActiveByUser(int $userId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT * FROM investments
              WHERE user_id = ? AND status = ? AND (deleted_at IS NULL OR deleted_at = 0)
              ORDER BY created_at DESC LIMIT 1",
            [$userId, self::STATUS_ACTIVE]
        );
    }

    public function hasActiveInvestment(int $userId): bool
    {
        return $this->getActiveByUser($userId) !== null;
    }

    /**
     * تمام سرمایه‌گذاری‌های کاربر
     */
    /** @return list<\stdClass> */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->prepare(
            "SELECT * FROM investments
             WHERE user_id = :user_id AND (deleted_at IS NULL OR deleted_at = 0)
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countByUser(int $userId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM investments WHERE user_id = ? AND (deleted_at IS NULL OR deleted_at = 0)",
            [$userId]
        );
    }

    /**
     * تمام سرمایه‌گذاری‌ها (ادمین)
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT i.*, u.full_name as user_name, u.email as user_email
                FROM investments i
                JOIN users u ON i.user_id = u.id
                WHERE (i.deleted_at IS NULL OR i.deleted_at = 0)";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND i.user_id = :user_id";
            $params['user_id'] = int_value($filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE :search OR u.email LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /** @param array<string, mixed> $filters */
    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM investments i
                JOIN users u ON i.user_id = u.id
                WHERE (i.deleted_at IS NULL OR i.deleted_at = 0)";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND i.user_id = ?";
            $params[] = int_value($filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        return (int)$this->db->fetchColumn($sql, $params);
    }

    /**
     * بروزرسانی سرمایه‌گذاری
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        $allowed = [
            'status', 'amount', 'current_balance', 'profit_earned', 'total_profit', 'total_loss',
            'transaction_id', 'last_profit_date', 'last_withdrawal_date', 'deposit_lock_until', 'start_date', 'updated_at', 'deleted_at'
        ];

        foreach ($allowed as $k) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "`{$k}` = ?";
                $values[] = $data[$k];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;

        $sql = "UPDATE investments SET " . \implode(', ', $fields) . " WHERE id = ? AND (deleted_at IS NULL OR deleted_at = 0)";

        $stmt = $this->db->query($sql, $values);

        return $stmt->rowCount() > 0;
    }

    /**
     * بررسی قفل واریز (بعد از برداشت ۷ روز نمی‌تواند واریز کند)
     */
    public function isDepositLocked(int $userId): bool
    {
        $inv = $this->getActiveByUser($userId);
        if (!$inv || empty($inv->deposit_lock_until)) {
            return false;
        }

        return \strtotime((string)$inv->deposit_lock_until) > \time();
    }

    /**
     * بررسی اجازه برداشت (هر ۷ روز یکبار)
     */
    /** @return array<string, mixed> */
    public function canWithdraw(int $userId): array
    {
        $inv = $this->getActiveByUser($userId);

        if (!$inv) {
            return [
                'allowed' => false,
                'reason'  => 'شما سرمایه‌گذاری فعال ندارید.',
            ];
        }

        // اگر قبلاً برداشت کرده:
        if (!empty($inv->last_withdrawal_date)) {
            $last = \strtotime((string)$inv->last_withdrawal_date);
            $nextAllowed = $last + (self::WITHDRAWAL_COOLDOWN_DAYS * 86400);

            if (\time() < $nextAllowed) {
                $remaining = (int)\ceil(($nextAllowed - \time()) / 86400);

                return [
                    'allowed'   => false,
                    'reason'    => "برداشت بعدی تا {$remaining} روز دیگر مجاز است.",
                    'next_date' => \date('Y-m-d H:i:s', $nextAllowed),
                ];
            }
        }

        // حداقل ۷ روز از شروع سرمایه‌گذاری
        $startDate = \strtotime((string)($inv->start_date ?? $inv->created_at ?? 'now'));
        $minDate = $startDate + (self::WITHDRAWAL_COOLDOWN_DAYS * 86400);

        if (\time() < $minDate) {
            $remaining = (int)\ceil(($minDate - \time()) / 86400);

            return [
                'allowed' => false,
                'reason'  => "برداشت سود پس از {$remaining} روز دیگر مجاز است.",
            ];
        }

        return ['allowed' => true];
    }

    /**
     * آمار کلی (ادمین)
     */
    public function getStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN status = 'frozen' THEN 1 ELSE 0 END) as frozen_count,
                COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) as total_invested,
                COALESCE(SUM(CASE WHEN status = 'active' THEN current_balance ELSE 0 END), 0) as total_balance,
                COALESCE(SUM(total_profit), 0) as total_profit_all,
                COALESCE(SUM(total_loss), 0) as total_loss_all
            FROM investments WHERE (deleted_at IS NULL OR deleted_at = 0)"
        ) ?? (object)[];
    }

    /**
     * @param list<int> $ids
     * @return list<\stdClass>
     */
    public function findInIdsForUpdate(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->query(
            "SELECT * FROM investments WHERE id IN ($placeholders) AND (deleted_at IS NULL OR deleted_at = 0) FOR UPDATE",
            $ids
        );

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * Native Modern Query Builder utilizing central Filterable architecture.
     */
    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('investments as i')
            ->select('i.*', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->where(function($q) {
                $q->whereNull('i.deleted_at')->orWhere('i.deleted_at', '=', 0);
            });

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        $dir = strtoupper((string)$sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy("i.created_at", $dir)->limit($limit)->offset($offset)->get()
        ];
    }

    /**
     * مجموع موجودی فعال سرمایه‌گذاران (Total Liabilities)
     */
    public function getTotalActiveBalance(): string
    {
        return (string)($this->db->query(
            "SELECT COALESCE(SUM(current_balance), '0') FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        )->fetchColumn() ?? '0');
    }

    /**
     * مجموع سرمایه اولیه سرمایه‌گذاران فعال
     */
    public function getTotalInitialInvested(): string
    {
        return (string)($this->db->query(
            "SELECT COALESCE(SUM(amount), '0') FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        )->fetchColumn() ?? '0');
    }

    /**
     * مجموع سود/ضرر تجمیعی معاملات
     */
    public function getTotalTradingProfitLoss(): string
    {
        return (string)($this->db->query(
            "SELECT COALESCE(SUM(profit_loss_amount), '0') FROM trading_records WHERE is_deleted = 0"
        )->fetchColumn() ?? '0');
    }
}