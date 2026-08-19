<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class AuditTrail extends Model
{
    protected static string $table = 'audit_trail';

    public const SYSTEM_ACTOR_ID = 0; // 🚀 M-07: ID for system/cron actions

    /** @param array<string, mixed> $data */
    public function createEntry(array $data): mixed
    {
        // 🚀 M-07 Fix: Ensure attribution for system actions
        if (!isset($data['actor_id']) && !isset($data['user_id'])) {
            $data['actor_id'] = self::SYSTEM_ACTOR_ID;
        }

        // Finding #11 Fix: Fetch previous hash under transaction FOR UPDATE to prevent chain forking
        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $prevEntry = $this->db->fetch("SELECT hash FROM `" . static::$table . "` ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $prevHash = ($prevEntry !== null && is_string($prevEntry->hash) && $prevEntry->hash !== '')
                ? $prevEntry->hash
                : str_repeat('0', 64);

            $cleanData = [
                'user_id' => $data['user_id'] ?? null,
                'actor_id' => $data['actor_id'] ?? self::SYSTEM_ACTOR_ID,
                'event' => mb_substr(str_value($data['event'] ?? ''), 0, 255),
                'context' => is_string($data['context'] ?? null) ? $data['context'] : json_encode($data['context'] ?? '{}', JSON_UNESCAPED_UNICODE),
                'ip_address' => $data['ip_address'] ?? (function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1'),
                'user_agent' => $data['user_agent'] ?? get_user_agent(),
                'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
            ];

            $payload = json_encode([
                'request_id' => $data['request_id'] ?? null,
                'event' => $cleanData['event'],
                'user_id' => $cleanData['user_id'],
                'actor_id' => $cleanData['actor_id'],
                'context' => $cleanData['context'],
                'ip_address' => $cleanData['ip_address'],
                'user_agent' => $cleanData['user_agent'],
                'created_at' => $cleanData['created_at'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $cleanData['prev_hash'] = $prevHash;
            $cleanData['hash'] = hash('sha256', $prevHash . '|' . $payload);

            $id = $this->create($cleanData);

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Verification Tool for Audit Chain Integrity (Finding #13 Fix)
     * @return array<string, mixed>
     */
    public function verifyChainIntegrity(int $limit = 1000): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, user_id, actor_id, event, context, ip_address, user_agent, created_at, prev_hash, hash
             FROM `" . static::$table . "`
             ORDER BY id ASC
             LIMIT ?",
            [$limit]
        ) ?: [];

        $errors = [];
        $prevHash = str_repeat('0', 64);

        foreach ($rows as $row) {
            if (empty($row->hash)) {
                $errors[] = [
                    'id' => $row->id,
                    'type' => 'missing_hash',
                    'event' => $row->event ?? '',
                ];
                continue;
            }

            if (!empty($row->prev_hash) && $row->prev_hash !== $prevHash) {
                $errors[] = [
                    'id' => $row->id,
                    'type' => 'prev_hash_mismatch',
                    'expected' => $prevHash,
                    'actual' => $row->prev_hash,
                ];
            }

            $prevHash = $row->hash;
        }

        return [
            'success' => empty($errors),
            'checked_count' => count($rows),
            'errors' => $errors,
        ];
    }

    /** @return list<\stdClass> */
    public function getForUser(int $userId, int $limit = 50): array
    {
        // 🚀 L-04 Fix: Max cap for limit
        $limit = min($limit, 500);
        return $this->db->fetchAll(
            "SELECT * FROM `" . static::$table . "`
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        ) ?: [];
    }

    /** @return array{rows: list<\stdClass>, total: int, page: int, totalPages: int} */
    public function getAll(
        int $page = 1,
        int $perPage = 50,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        // 🚀 L-04 Fix: Max cap for perPage
        $perPage = min(max(1, $perPage), 100);
        $params = [];
        $where = $this->buildAuditFilters($event, $userId, $search, $dateFrom, $dateTo, $params);

        $offset = \max(0, ($page - 1) * $perPage);

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM `" . static::$table . "` at
             LEFT JOIN users u ON u.id = at.user_id
             {$where}",
            $params
        );

        $sql = "SELECT at.*,
                       u.full_name AS user_name, u.email AS user_email,
                       a.full_name AS actor_name, a.email AS actor_email
                FROM `" . static::$table . "` at
                LEFT JOIN users u ON u.id = at.user_id
                LEFT JOIN users a ON a.id = at.actor_id
                {$where}
                ORDER BY at.created_at DESC
                LIMIT :limit OFFSET :offset";

        // M17: Use named parameters consistently to avoid mixing with positional
        $namedParams = $params;
        $namedParams['limit'] = $perPage;
        $namedParams['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($namedParams);
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'totalPages' => (int)ceil($total / max($perPage, 1)),
        ];
    }

    /** @return list<\stdClass> */
    public function getEventTypes(): array
    {
        return $this->db->fetchAll(
            "SELECT event, COUNT(*) AS total
             FROM `" . static::$table . "`
             GROUP BY event
             ORDER BY total DESC, event ASC"
        ) ?: [];
    }

    /** @return array<string, mixed> */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $params = [];
        $where = 'WHERE 1=1';

        if (!empty($dateFrom)) {
            $where .= ' AND at.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if (!empty($dateTo)) {
            $where .= ' AND at.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $total = $this->fetchCount("SELECT COUNT(*) FROM `" . static::$table . "` at {$where}", $params);

        $uniqueUsers = $this->fetchCount(
            "SELECT COUNT(DISTINCT at.user_id)
             FROM `" . static::$table . "` at
             {$where} AND at.user_id IS NOT NULL",
            $params
        );

        $uniqueActors = $this->fetchCount(
            "SELECT COUNT(DISTINCT at.actor_id)
             FROM `" . static::$table . "` at
             {$where} AND at.actor_id IS NOT NULL",
            $params
        );

        $today = $this->fetchCount(
            "SELECT COUNT(*)
             FROM `" . static::$table . "` at
             {$where} AND DATE(at.created_at) = CURDATE()",
            $params
        );

        return [
            'total' => $total,
            'unique_users' => $uniqueUsers,
            'unique_actors' => $uniqueActors,
            'today' => $today,
        ];
    }

    /** @return list<\stdClass> */
    public function fetchBatchOlderThan(string $cutoff, int $lastId, int $chunkSize): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `" . static::$table . "`
             WHERE created_at < ? AND id > ?
             ORDER BY id ASC
             LIMIT ?",
            [$cutoff, $lastId, $chunkSize]
        ) ?: [];
    }

    public function deleteOlderThan(string $cutoff, int $limit = 5000, bool $bypassCompliance = false): int
    {
        // 🚀 BUG FIX [C-03]: Audit logs must be immutable. Physical deletion is prohibited.
        // جایگزینی با سیستم آرشیو به Cold Storage در آینده
        if (!$bypassCompliance) {
            throw new \RuntimeException("Physical deletion of audit logs is prohibited for security compliance. Use archival instead.");
        }

        $limit = (int)$limit;
        $stmt = $this->db->prepare("DELETE FROM `" . static::$table . "` WHERE created_at < ? LIMIT {$limit}");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    public function cleanupOlderThan(int $days = 365, bool $bypassCompliance = false): int
    {
        // 🚀 BUG FIX [C-03]: Prevent automated cleanup via physical delete
        if (!$bypassCompliance) {
            throw new \RuntimeException("Automated physical cleanup is disabled. Use archival processes.");
        }

        $cutoff = date('Y-m-d H:i:s', (strtotime("-{$days} days") ?: time()));
        return $this->deleteOlderThan($cutoff, 500000, true);
    }

    /** @param array<string, mixed> $params */
    private function buildAuditFilters(
        ?string $event,
        ?int $userId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        array &$params
    ): string {
        $where = 'WHERE 1=1';

        if ($event !== null && $event !== '') {
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId !== null) {
            $where .= ' AND (at.user_id = :user_id OR at.actor_id = :actor_id)';
            $params['user_id'] = $userId;
            $params['actor_id'] = $userId;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= ' AND at.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where .= ' AND at.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        if ($search !== null && $search !== '') {
            $searchTerm = \trim((string)$search);
            $escaped = \addcslashes($searchTerm, '%_\\');
            $like = "%{$escaped}%";
            $where .= ' AND (at.event LIKE :search_event OR at.context LIKE :search_context OR u.email LIKE :search_email)';
            $params['search_event'] = $like;
            $params['search_context'] = $like;
            $params['search_email'] = $like;
        }

        return $where;
    }

    /** @param list<string> $params */
    private function fetchCount(string $sql, array $params = []): int
    {
        return (int)$this->db->fetchColumn($sql, $params);
    }

    /**
     * 🛡️ Audit Fix: ادغام شده از AuditEvent (که حذف شد)
     */
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->query(
            "SELECT at.*, u.full_name as user_name, u.email as user_email,
                    a.full_name as actor_name, a.email as actor_email
             FROM audit_trail at
             LEFT JOIN users u ON at.user_id = u.id
             LEFT JOIN users a ON at.actor_id = a.id
             WHERE at.id = ?",
            [$id]
        );

        return $this->fetchAssoc($stmt) ?: null;
    }

    /**
     * 🛡️ Audit Fix: ادغام شده از AuditEvent
     */
    /** @return list<\stdClass> */
    public function findAllPaginated(
        int $limit,
        int $offset,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $where = 'WHERE 1=1';
        $params = [];

        if ($event) {
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId) {
            $where .= ' AND (at.user_id = :user_id OR at.actor_id = :actor_id)';
            $params['user_id'] = $userId;
            $params['actor_id'] = $userId;
        }

        if ($search) {
            $escaped = \addcslashes($search, '%_\\');
            $like = "%{$escaped}%";
            $where .= ' AND (at.context LIKE :search_context OR u.email LIKE :search_email)';
            $params['search_context'] = $like;
            $params['search_email'] = $like;
        }

        if ($dateFrom) {
            $where .= ' AND DATE(at.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(at.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT at.*, u.full_name as user_name, u.email as user_email
                FROM audit_trail at
                LEFT JOIN users u ON at.user_id = u.id
                {$where}
                ORDER BY at.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * 🛡️ Audit Fix: ادغام شده از AuditEvent
     */
    public function countAll(
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $where = 'WHERE 1=1';
        $params = [];

        if ($event) {
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId) {
            $where .= ' AND (at.user_id = :user_id OR at.actor_id = :actor_id)';
            $params['user_id'] = $userId;
            $params['actor_id'] = $userId;
        }

        if ($search) {
            $escaped = \addcslashes($search, '%_\\');
            $like = "%{$escaped}%";
            $where .= ' AND (at.context LIKE :search_context OR u.email LIKE :search_email)';
            $params['search_context'] = $like;
            $params['search_email'] = $like;
        }

        if ($dateFrom) {
            $where .= ' AND DATE(at.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(at.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT COUNT(*) as count 
                FROM audit_trail at
                LEFT JOIN users u ON at.user_id = u.id
                {$where}";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $this->fetchAssoc($stmt);

        return int_value($row['count'] ?? 0);
    }

    public function update(int $id, array $data): bool
    {
        throw new \RuntimeException("Audit logs are strictly immutable and cannot be updated.");
    }

    public function delete(int $id): bool
    {
        throw new \RuntimeException("Physical deletion of audit logs is prohibited for compliance.");
    }
}

