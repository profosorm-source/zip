<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class BackupLog extends Model
{
    protected static string $table = 'backup_logs';

    /** @return list<object> */
    public function getRecentBackups(int $limit = 20, int $offset = 0): array
    {
        $query = $this->db->table(self::$table)
            ->select('id', 'status', 'type', 'file_path', 'size_bytes', 'created_at', 'completed_at')
            ->orderBy('created_at', 'DESC')
            ->limit($limit);

        if ($offset > 0) {
            $query->offset($offset);
        }

        return $query->get();
    }

    /** @return array<string, mixed>|null */
    /** @return array<string, mixed>|null */
    public function findById(int $backupId): ?array
    {
        $row = $this->db->table(self::$table)
            ->select('file_path', 'checksum', 'request_id')
            ->where('id', '=', $backupId)
            ->first();
        return $row ? (array)$row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByFilename(string $filename): ?array
    {
        $result = $this->db->table(self::$table)
            ->select('file_path', 'checksum', 'request_id')
            ->where('file_path', '=', $filename)
            ->first();
        return $result ? (array) $result : null;
    }

    /** @param array<string, mixed> $data */
    public function logBackup(array $data): int
    {
        return (int)$this->db->table(self::$table)
            ->insert($data);
    }

    /** @return array<string, mixed>|null */
    public function getBackupStatus(string $backupId): ?array
    {
        $row = $this->db->table(self::$table)
            ->select('*')
            ->where('id', '=', $backupId)
            ->first();
        return $row ? (array)$row : null;
    }

    /** @return list<object> */
    public function getOlderThan(string $cutoffDate): array
    {
        return $this->db->table(self::$table)
            ->select('file_path')
            ->where('created_at', '<', $cutoffDate)
            ->get();
    }

    public function deleteOlderThan(string $cutoffDate): int
    {
        return (int)$this->db->table(self::$table)
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $stats = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_backups,
                SUM(size_bytes) AS total_size,
                MAX(created_at) AS last_backup,
                MIN(created_at) AS first_backup
             FROM " . self::$table
        );

        return [
            'total_backups' => (int)($stats->total_backups ?? 0),
            'total_size' => (int)($stats->total_size ?? 0),
            'last_backup' => $stats->last_backup ?? null,
            'first_backup' => $stats->first_backup ?? null,
        ];
    }
}
