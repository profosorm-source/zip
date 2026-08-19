<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * ApiToken Model - Secured and Optimized
 */
class ApiToken extends Model
{
    // Evaluates is_active status of tokens
    protected static string $table = 'api_tokens';

    public const ALLOWED_SCOPES = [
        'read', 'write', 'admin', 'delete',
        'profile:read', 'profile:write', 
        'wallet:read', 'wallet:write', 
        'transactions:read', 'tasks:read', 'tasks:write',
        'security:read', 'security:write', 
        'settings:read', 'settings:write',
        // Scopes used by /api/v1 route middleware. Keep legacy colon scopes above
        // for backwards compatibility, but allow the actual route-level scopes too.
        'auth.manage',
        'user.read', 'user.write',
        'wallet.read', 'wallet.write',
        'social.read', 'social.write',
        'influencer.read', 'influencer.write',
        'verification.read', 'verification.write',
        'realtime'
    ];

    public function __construct(Database $db) {
        parent::__construct($db);
    }

    public function findById(int $id): ?\stdClass
    {
        $this->validateId($id);

        $token = $this->db->fetch(
            "SELECT at.*, u.full_name, u.email 
             FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id
             WHERE at.id = ?",
            [$id]
        );

        return $token ?: null;
    }

    /** @return list<object> */
    public function findAllPaginated(
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $query = $this->db->table('api_tokens as at')
            ->leftJoin('users as u', 'u.id', '=', 'at.user_id');

        if ($search) {
            $search = $this->escapeLikeValue($search);
            $searchParam = "%{$search}%";
            $query->where(function($q) use ($searchParam) {
                $q->where('at.name', 'LIKE', $searchParam)
                  ->orWhere('u.email', 'LIKE', $searchParam);
            });
        }

        if ($statusFilter === 'active') {
            $query->where('at.revoked', '=', 0)
                  ->where(function($q) {
                      $q->whereNull('at.expires_at')
                        ->orWhere('at.expires_at', '>', date('Y-m-d H:i:s'));
                  });
        } elseif ($statusFilter === 'revoked') {
            $query->where('at.revoked', '=', 1);
        } elseif ($statusFilter === 'expired') {
            $query->where('at.revoked', '=', 0)
                  ->whereNotNull('at.expires_at')
                  ->where('at.expires_at', '<', date('Y-m-d H:i:s'));
        }

        $results = $query->select('at.*', 'u.full_name', 'u.email')
                         ->orderBy('at.created_at', 'DESC')
                         ->limit($limit)
                         ->offset($offset)
                         ->get();

        return $results ?: [];
    }

    public function countAll(?string $search = null, ?string $statusFilter = null): int
    {
        $query = $this->db->table('api_tokens as at')
            ->leftJoin('users as u', 'u.id', '=', 'at.user_id');

        if ($search) {
            $search = $this->escapeLikeValue($search);
            $searchParam = "%{$search}%";
            $query->where(function($q) use ($searchParam) {
                $q->where('at.name', 'LIKE', $searchParam)
                  ->orWhere('u.email', 'LIKE', $searchParam);
            });
        }

        if ($statusFilter === 'active') {
            $query->where('at.revoked', '=', 0)
                  ->where(function($q) {
                      $q->whereNull('at.expires_at')
                        ->orWhere('at.expires_at', '>', date('Y-m-d H:i:s'));
                  });
        } elseif ($statusFilter === 'revoked') {
            $query->where('at.revoked', '=', 1);
        } elseif ($statusFilter === 'expired') {
            $query->where('at.revoked', '=', 0)
                  ->whereNotNull('at.expires_at')
                  ->where('at.expires_at', '<', date('Y-m-d H:i:s'));
        }

        return $query->count();
    }

    public function revokeById(int $id): bool
    {
        $this->validateId($id);

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ?",
            [$id]
        );

        return true;
    }

    /**
     * Atomic Compare-And-Swap revocation for Refresh Token Rotation (Finding #5)
     */
    public function revokeByIdIfActive(int $id): bool
    {
        $this->validateId($id);

        $stmt = $this->db->prepare(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ? AND revoked = 0"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * M-25: revoke every non-revoked token belonging to a user. Used for refresh-token-reuse
     * (theft) response — when a already-rotated refresh token is replayed we must kill the whole
     * family, not just the presented one, because the attacker likely holds a live descendant.
     */
    public function revokeAllForUser(int $userId): int
    {
        $this->validateId($userId, 'user_id');
        $stmt = $this->db->prepare(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE user_id = ? AND revoked = 0"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * M-25: locate a token by its refresh token INCLUDING already-revoked / expired rows.
     * findByRefreshToken() intentionally filters revoked=0; this variant lets the service detect
     * replay of a previously-rotated (and therefore revoked) refresh token.
     */
    public function findAnyByRefreshToken(string $plainRefreshToken): ?\stdClass
    {
        $secretsValue = config('security.api.secrets', []);
        $secrets = is_array($secretsValue) ? $secretsValue : [];
        if (empty($secrets)) {
            $legacySecret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
            if ($legacySecret) {
                $secrets = ['v2' => $legacySecret];
            }
        }
        $currentVersionValue = config('security.api.current_secret_version', 'v2');
        $currentVersion = is_string($currentVersionValue) ? $currentVersionValue : 'v2';
        $orderedSecrets = [];
        if (isset($secrets[$currentVersion])) {
            $orderedSecrets[$currentVersion] = $secrets[$currentVersion];
        }
        foreach ((array)$secrets as $version => $secret) {
            if ($version !== $currentVersion) {
                $orderedSecrets[$version] = $secret;
            }
        }
        foreach ((array)$orderedSecrets as $version => $secret) {
            if (empty($secret) || strlen((string)$secret) < 32) {
                continue;
            }
            $hashedToken = hash_hmac('sha256', $plainRefreshToken, $secret);
            $tokenRow = $this->db->fetch(
                "SELECT at.*, u.status as user_status, u.email, u.role
                 FROM api_tokens at
                 LEFT JOIN users u ON u.id = at.user_id
                 WHERE at.refresh_token = ? AND at.secret_version = ?
                 LIMIT 1",
                [$hashedToken, $version]
            );
            if ($tokenRow) {
                return (object)['row' => $tokenRow, 'hashed' => $hashedToken, 'version' => $version];
            }
        }
        return null;
    }

    public function revokeForUser(int $id, int $userId): bool
    {
        $this->validateId($id);
        $this->validateId($userId, 'user_id');

        $stmt = $this->db->prepare(
            "UPDATE api_tokens 
             SET revoked = 1, revoked_at = NOW() 
             WHERE id = ? AND user_id = ? AND revoked = 0"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function revokeByHashForUser(string $plainToken, int $userId): bool
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }
        $this->validateId($userId, 'user_id');

        $details = $this->getHashedTokenDetails($plainToken);
        if (!$details) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE api_tokens 
             SET revoked = 1, revoked_at = NOW() 
             WHERE token = ? AND secret_version = ? AND user_id = ? AND revoked = 0"
        );
        $stmt->execute([$details->hashed, $details->version, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function createToken(
        int $userId, 
        string $plainToken, 
        string $name, 
        string $scopes, 
        ?string $expiresAt,
        ?string $plainRefreshToken = null
    ): int {
        $this->validateId($userId, 'user_id');

        if (empty($plainToken) || strlen((string)$plainToken) < 32) {
            throw new \InvalidArgumentException('Token too weak');
        }
        
        if (empty($name) || strlen((string)$name) > 100) {
            throw new \InvalidArgumentException('Invalid token name');
        }
        
        $userRow = $this->db->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
        $isAdminUser = in_array($userRow->role ?? '', ['admin', 'super_admin'], true);

        $scopesArray = explode(',', $scopes);
        foreach ($scopesArray as $scope) {
            $trimmed = trim((string)$scope);
            if (!in_array($trimmed, self::ALLOWED_SCOPES, true)) {
                throw new \InvalidArgumentException("Invalid scope: {$scope}");
            }
            if ($trimmed === 'admin' && !$isAdminUser) {
                throw new \InvalidArgumentException("Normal user cannot create API token with admin scope.");
            }
        }

        if ($expiresAt !== null) {
            $this->validateDate($expiresAt, 'expires_at');
        }

        $currentVersionValue = config('security.api.current_secret_version', 'v2');
        $currentVersion = is_string($currentVersionValue) ? $currentVersionValue : 'v2';
        $secret = config("security.api.secrets.{$currentVersion}");
        if (empty($secret)) {
            $secret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
        }
        
        if (!$secret || strlen((string)$secret) < 32) {
            throw new \RuntimeException('API secret key is not configured or too weak (minimum 32 characters required)');
        }
        
        $hashedToken = hash_hmac('sha256', $plainToken, $secret);
        $hashedRefreshToken = $plainRefreshToken ? hash_hmac('sha256', $plainRefreshToken, $secret) : null;

        $this->db->query(
            "INSERT INTO api_tokens (user_id, token, refresh_token, secret_version, name, scopes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $hashedToken, $hashedRefreshToken, $currentVersion, $name, $scopes, $expiresAt]
        );

        return (int)$this->db->lastInsertId();
    }

    public function findByRefreshToken(string $plainRefreshToken): ?\stdClass
    {
        $secretsValue = config('security.api.secrets', []);
        $secrets = is_array($secretsValue) ? $secretsValue : [];
        if (empty($secrets)) {
            $legacySecret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
            if ($legacySecret) {
                $secrets = ['v2' => $legacySecret];
            }
        }

        $currentVersionValue = config('security.api.current_secret_version', 'v2');
        $currentVersion = is_string($currentVersionValue) ? $currentVersionValue : 'v2';
        
        $orderedSecrets = [];
        if (isset($secrets[$currentVersion])) {
            $orderedSecrets[$currentVersion] = $secrets[$currentVersion];
        }
        foreach ((array)$secrets as $version => $secret) {
            if ($version !== $currentVersion) {
                $orderedSecrets[$version] = $secret;
            }
        }

        foreach ((array)$orderedSecrets as $version => $secret) {
            if (empty($secret) || strlen((string)$secret) < 32) {
                continue;
            }

            $hashedToken = hash_hmac('sha256', $plainRefreshToken, $secret);
            
            $tokenRow = $this->db->fetch(
                "SELECT at.*, u.status as user_status, u.email, u.role 
                 FROM api_tokens at
                 LEFT JOIN users u ON u.id = at.user_id
                 WHERE at.refresh_token = ?
                   AND at.secret_version = ?
                   AND at.revoked = 0
                   AND (at.expires_at IS NULL OR at.expires_at > NOW())
                 LIMIT 1",
                [$hashedToken, $version]
            );

            if ($tokenRow) {
                return (object)[
                    'row' => $tokenRow,
                    'hashed' => $hashedToken,
                    'version' => $version
                ];
            }
        }

        return null;
    }

    /**
     * @return array<object>
     */
    public function findByUserId(int $userId): array
    {
        $this->validateId($userId);

        $results = $this->db->fetchAll(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        return $results ?: [];
    }

    public function countActiveByUserId(int $userId): int
    {
        $this->validateId($userId);

        $count = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE user_id = ? AND revoked = 0",
            [$userId]
        );

        if (!$count) {
            return 0;
        }

        return $this->scalarCount($count);
    }

    public function revokeByHash(string $plainToken): bool
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $details = $this->getHashedTokenDetails($plainToken);
        if (!$details) {
            return false;
        }

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ? AND secret_version = ?",
            [$details->hashed, $details->version]
        );

        return true;
    }

    public function findByHash(string $plainToken): ?\stdClass
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $details = $this->getHashedTokenDetails($plainToken);
        return $details ? $details->row : null;
    }

    /**
     * Helper to lookup hashed token details iteratively over active secret versions
     */
    /** @return \stdClass|null */
    private function getHashedTokenDetails(string $plainToken): ?\stdClass
    {
        $secretsValue = config('security.api.secrets', []);
        $secrets = is_array($secretsValue) ? $secretsValue : [];
        if (empty($secrets)) {
            $legacySecret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
            if ($legacySecret) {
                $secrets = ['v2' => $legacySecret];
            }
        }

        $currentVersionValue = config('security.api.current_secret_version', 'v2');
        $currentVersion = is_string($currentVersionValue) ? $currentVersionValue : 'v2';
        
        $orderedSecrets = [];
        if (isset($secrets[$currentVersion])) {
            $orderedSecrets[$currentVersion] = $secrets[$currentVersion];
        }
        foreach ((array)$secrets as $version => $secret) {
            if ($version !== $currentVersion) {
                $orderedSecrets[$version] = $secret;
            }
        }

        foreach ((array)$orderedSecrets as $version => $secret) {
            if (empty($secret) || strlen((string)$secret) < 32) {
                continue;
            }

            $hashedToken = hash_hmac('sha256', $plainToken, $secret);
            
            // Check if this hash and version exists in database
            $tokenRow = $this->db->fetch(
                "SELECT * FROM api_tokens WHERE token = ? AND secret_version = ? LIMIT 1",
                [$hashedToken, $version]
            );

            if ($tokenRow) {
                return (object)[
                    'row' => $tokenRow,
                    'hashed' => $hashedToken,
                    'version' => $version
                ];
            }
        }

        return null;
    }

    public function revokeAllExpired(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE api_tokens 
             SET revoked = 1, revoked_at = NOW() 
             WHERE revoked = 0 AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        $stmt->execute();
        return (int)$stmt->rowCount();
    }

    private function scalarCount(mixed $row): int
    {
        if (is_object($row)) {
            $vars = get_object_vars($row);
            return is_numeric($vars['count'] ?? null) ? (int)$vars['count'] : 0;
        }
        if (is_array($row)) {
            return is_numeric($row['count'] ?? null) ? (int)$row['count'] : 0;
        }
        return 0;
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        $activeCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"
        );

        $revokedCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE revoked = 1"
        );

        $expiredCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND expires_at IS NOT NULL AND expires_at < NOW()"
        );

        $usedTodayCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE DATE(last_used_at) = CURDATE()"
        );

        $active = $this->scalarCount($activeCount);
        $revoked = $this->scalarCount($revokedCount);
        $expired = $this->scalarCount($expiredCount);
        $usedToday = $this->scalarCount($usedTodayCount);

        return [
            'active' => (int)$active,
            'revoked' => (int)$revoked,
            'expired' => (int)$expired,
            'used_today' => (int)$usedToday,
        ];
    }
}
