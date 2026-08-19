<?php

namespace App\Models;

use Core\Model;
use Core\Database;
use App\Traits\Filterable;

class Ticket extends Model {
    use Filterable;

    protected static string $table = 'tickets';

    /**
     * Relations برای eager loading با allWith/loadRelations
     *
     * استفاده:
     *   // لیست تیکت‌ها با کاربر و پیام‌هایشان
     *   $tickets = $ticketModel->allWith(['user', 'messages', 'category'], 20);
     *   echo $tickets[0]->user->full_name;
     *   echo count($tickets[0]->messages);
     *   echo $tickets[0]->category->name;
     *
     *   // یک تیکت با همه جزئیات
     *   $ticket = $ticketModel->findWith($id, ['user', 'messages', 'category']);
     */
    protected array $relations = [
        'user'     => [\App\Models\User::class,            'user_id',   'one'],
        'messages' => [\App\Models\TicketMessage::class,   'ticket_id', 'many'],
        'category' => [\App\Models\TicketCategory::class,  'id',        'one'],
    ];
    protected static array $searchable = ['t.subject', 't.ticket_id'];

    /** @var array<string, array{string, string}> */
    protected static array $filterable = [
        'status' => ['t.status', '='],
        'priority' => ['t.priority', '='],
        'category_id' => ['t.category_id', '='],
        'assigned_to' => ['t.assigned_to', '='],
        'user_id' => ['t.user_id', '='],
    ];

/* -------------------------
     * Helpers (DB fetch wrappers)
     * ------------------------- */
    /** @param array<int|string, mixed> $params */
    private function fetchOne(string $sql, array $params = []): ?\stdClass
    {
        $stmt = $this->db->query($sql, $params);
        /** @var \stdClass|false $row */
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /** @param array<int|string, mixed> $params */
    private function execBool(string $sql, array $params = []): bool
    {
        $stmt = $this->db->query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * ایجاد تیکت جدید
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        $publicTicketId = $data['ticket_id'] ?? ('TCK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))));

        $sql = "INSERT INTO tickets
                (user_id, category_id, ticket_id, subject, priority, status, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'open', ?, NOW(), NOW())";

        $this->db->query($sql, [
            $data['user_id'],
            $data['category_id'],
            $publicTicketId,
            $data['subject'],
            $data['priority'] ?? 'normal',
            $data['metadata'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * دریافت با ID
     */
    public function findById(int $id): ?\stdClass
    {
        $sql = "SELECT t.*, tc.name as category_name,
                       u.full_name as user_name, u.email as user_email
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                JOIN users u ON t.user_id = u.id
                WHERE t.id = ?";

        return $this->fetchOne($sql, [$id]);
    }


    /**
     * دریافت یک تیکت فقط اگر متعلق به همان کاربر باشد.
     */
    public function findForUser(int $id, int $userId): ?\stdClass
    {
        if ($id <= 0 || $userId <= 0) {
            return null;
        }

        $sql = "SELECT t.*, tc.name as category_name, tc.icon as category_icon
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                WHERE t.id = ? AND t.user_id = ?
                LIMIT 1";

        return $this->fetchOne($sql, [$id, $userId]);
    }

    /**
     * دریافت تیکت‌های کاربر
     */
    /** @return list<\stdClass> */
    public function getUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $page = \max(1, (int)$page);
        $perPage = \max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT t.*, tc.name as category_name, tc.icon as category_icon
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                WHERE t.user_id = ?";

        $params = [$userId];

        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY t.updated_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش تیکت‌های کاربر
     */
    public function countUserTickets(int $userId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE user_id = ?";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $row = $this->fetchOne($sql, $params);
        return $row ? (int)$row->count : 0;
    }

    /**
     * دریافت تیکت‌ها برای ادمین
     */
    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function getForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = \max(1, (int)$page);
        $perPage = \max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT t.*, tc.name as category_name, u.full_name, u.email
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                JOIN users u ON t.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND t.priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND t.category_id = ?";
            $params[] = int_value($filters['category_id']);
        }

        if (!empty($filters['assigned_to'])) {
            $sql .= " AND t.assigned_to = ?";
            $params[] = int_value($filters['assigned_to']);
        }

        $sql .= " ORDER BY
                    CASE t.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'normal' THEN 3
                        WHEN 'low' THEN 4
                        ELSE 5
                    END,
                    t.updated_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش برای ادمین
     */
    /** @param array<string, mixed> $filters */
    public function countForAdmin(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND category_id = ?";
            $params[] = int_value($filters['category_id']);
        }

        $row = $this->fetchOne($sql, $params);
        return $row ? (int)$row->count : 0;
    }

    /**
     * بروزرسانی وضعیت
     */
    public function updateStatus(int $id, string $status): bool
    {
        $data = ['status' => $status];

        if ($status === 'closed') {
            $data['closed_at'] = \date('Y-m-d H:i:s');
        }

        return $this->update($id, $data);
    }

    /**
     * بروزرسانی آخرین پاسخ
     */
    public function updateLastReply(int $id, string $replyBy): bool
    {
        $sql = "UPDATE tickets
                SET status = CASE
                        WHEN status = 'closed' THEN 'open'
                        WHEN ? = 'admin' THEN 'answered'
                        ELSE status
                    END,
                    updated_at = NOW()
                WHERE id = ?";

        return $this->execBool($sql, [$replyBy, $id]);
    }

    /**
     * تخصیص به ادمین
     */
    public function assign(int $id, int $adminId): bool
    {
        $sql = "UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?";
        return $this->execBool($sql, [$adminId, $id]);
    }

    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;

        $allowedColumns = ['category_id', 'subject', 'priority', 'status', 'metadata', 'assigned_to', 'closed_at'];
        $fields = [];
        $params = [];

        foreach ((array)$data as $key => $value) {
            if (!in_array($key, $allowedColumns, true)) {
                continue;
            }
            $fields[] = "{$key} = ?";
            $params[] = $value;
        }

        if (empty($fields)) return false;

        // همیشه updated_at را بروز کن
        $fields[] = "updated_at = NOW()";

        $params[] = $id;

        $sql = "UPDATE tickets SET " . \implode(', ', $fields) . " WHERE id = ?";

        return $this->execBool($sql, $params);
    }

    /**
     * آمار تیکت‌ها
     */
    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) as on_hold,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent
                FROM tickets";

        $row = $this->fetchOne($sql);

        return [
            'total' => $row ? (int)$row->total : 0,
            'open' => $row ? (int)$row->open : 0,
            'answered' => $row ? (int)$row->answered : 0,
            'in_progress' => $row ? (int)$row->in_progress : 0,
            'on_hold' => $row ? (int)$row->on_hold : 0,
            'closed' => $row ? (int)$row->closed : 0,
            'urgent' => $row ? (int)$row->urgent : 0,
        ];
    }

    /**
     * Advanced search logic utilizing Central Filterable architecture.
     */
    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('tickets as t')
            ->select('t.*', 'tc.name as category_name', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('ticket_categories as tc', 'tc.id', '=', 't.category_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id');

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        // Specialized intelligent support prioritization
        // M-36: searched-CASE form so the expression is provable against the
        // SafeExpression allowlist grammar used by QueryBuilder::orderByRaw().
        $query->orderByRaw(
            "CASE WHEN t.priority = 'urgent' THEN 1"
            . " WHEN t.priority = 'high' THEN 2"
            . " WHEN t.priority = 'normal' THEN 3"
            . " WHEN t.priority = 'low' THEN 4"
            . " ELSE 5 END",
            'ASC'
        )
              ->orderBy('t.updated_at', 'DESC');

        return [
            'total' => $query->count(),
            'items' => (clone $query)->limit($limit)->offset($offset)->get()
        ];
    }
}