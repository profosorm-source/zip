<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class CaptchaLog extends Model
{
    protected static string $table = 'captcha_attempts';

    protected array $fillable = ['ip_address', 'user_agent', 'type', 'success', 'created_at'];
    /**
     * @param array<string, mixed> $data
     */

    public function recordAttempt(array $data): int
    {
        return (int)$this->db->table(self::$table)
            ->insert($data);
    }
    /**
     * @return list<object>
     */

    public function getRecentAttempts(int $limit = 50): array
    {
        return $this->db->table(self::$table)
            ->select('id', 'user_id', 'ip_address', 'is_success AS success', 'created_at')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function countFailedByIp(string $ip, string $since): int
    {
        return (int)$this->db->table(self::$table)
            ->where('ip_address', '=', $ip)
            ->where('created_at', '>=', $since)
            ->where('is_success', '=', 0)
            ->count();
    }
}
