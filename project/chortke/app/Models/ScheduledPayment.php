<?php

namespace App\Models;

use Core\Model;

class ScheduledPayment extends Model
{
    protected static string $table = 'scheduled_payments';

    /** @param array<string, mixed> $data */
    public function createSchedule(array $data): ?\stdClass
    {
        $data['user_id'] = int_value($data['user_id'] ?? 0);
        $data['amount'] = str_value($data['amount'] ?? '0');
        $data['currency'] = strtolower(str_value($data['currency'] ?? 'irt'));
        $data['frequency'] = $data['frequency'] ?? 'one_time';
        if ($data['frequency'] === 'once') {
            $data['frequency'] = 'one_time';
        }
        $data['next_run_at'] = $data['next_run_at'] ?? date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'active';
        $data['description'] = $data['description'] ?? null;
        $data['metadata'] = isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : ($data['metadata'] ?? null);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        if (!empty($data['idempotency_key'])) {
            $data['idempotency_key'] = substr(str_value($data['idempotency_key']), 0, 128);
        } else {
            unset($data['idempotency_key']);
        }

        $id = parent::create($data);
        return is_int($id) ? $this->find($id) : null;
    }

    /** @return list<\stdClass> */
    public function getDuePayments(int $limit = 50): array
    {
        $limit = max(1, (int)$limit);
        $sql = "SELECT * FROM `" . static::$table . "` 
                WHERE status = 'active' AND next_run_at <= NOW() 
                ORDER BY next_run_at ASC 
                LIMIT :limit FOR UPDATE SKIP LOCKED";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * دریافت لیست پرداخت‌های معوق با گارانتی تراکنش و قفل ایمن ردیف
     */
    /** @return list<\stdClass> */
    public function getDuePaymentsWithTransaction(int $limit = 50): array
    {
        try {
            $this->db->beginTransaction();
            $payments = $this->getDuePayments($limit);
            if (empty($payments)) {
                $this->db->rollback();
                return [];
            }
            return $payments;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return [];
        }
    }

    public function updateNextRun(int $id, string $nextRunAt, string $status = 'active'): bool
    {
        return $this->update($id, [
            'next_run_at' => $nextRunAt,
            'status' => $status,
            'last_run_at' => date('Y-m-d H:i:s'),
            'processed_count' => (int)($this->db->fetchColumn('SELECT processed_count FROM scheduled_payments WHERE id = ?', [$id]) ?? 0) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }
}
