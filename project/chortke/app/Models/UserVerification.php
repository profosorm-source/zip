<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * UserVerification Model — encapsulates access to the `user_verifications`
 * table.
 */
class UserVerification extends Model
{
    protected static string $table = 'user_verifications';

    protected array $fillable = [
        'user_id', 'type', 'token', 'code',
        'expires_at', 'verified_at', 'created_at',
    ];

    protected bool $timestamps = false;

    /**
     * Find a non-expired verification row by its hashed token.
     */
    public function findByToken(string $hashedToken): ?\stdClass
    {
        $row = $this->db->fetch(
            "SELECT *
               FROM `" . static::$table . "`
              WHERE token = ?
                AND expires_at > NOW()
              LIMIT 1",
            [$hashedToken]
        );
        return $row ?: null;
    }

    /**
     * Find a non-expired OTP code.
     */
    public function findValidCode(int $userId, string $code, string $type = 'email'): ?\stdClass
    {
        $row = $this->db->fetch(
            "SELECT *
               FROM `" . static::$table . "`
              WHERE user_id    = ?
                AND type       = ?
                AND code       = ?
                AND expires_at > NOW()
              LIMIT 1",
            [$userId, $type, $code]
        );
        return $row ?: null;
    }

    /**
     * Insert or refresh a verification row.
     * SECURITY-FIX: Token is hashed before storage to match findByToken().
     */
    public function upsertOtp(
        int $userId,
        string $token,
        string $code,
        string $expiresAt,
        string $type = 'email'
    ): bool {
        $hashedToken = hash_hmac('sha256', $token, secure_key());

        $stmt = $this->db->query(
            "INSERT INTO `" . static::$table . "`
                 (user_id, type, token, code, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 token      = VALUES(token),
                 code       = VALUES(code),
                 expires_at = VALUES(expires_at)",
            [$userId, $type, $hashedToken, $code, $expiresAt]
        );
        return $stmt->rowCount() > 0;
    }
}
