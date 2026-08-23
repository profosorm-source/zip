<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

/**
 * User Model - Centralized data access for users table
 * @property int $id
 * @property string $email
 * @property string $mobile
 * @property string $full_name
 * @property string $role
 * @property string $status
 * @property string|null $kyc_status
 * @property string $password
 * @property string $referral_code
 * @property int|null $referred_by
 * @property string $created_at
 * @property string|null $updated_at
 * @property string|null $deleted_at
 * @property string|null $email_verified_at
 * @property string|null $email_verification_token
 * @property string|null $remember_token
 * @property string $level_slug
 * @property int $fraud_score
 * @property int $id
 * @property string $email
 * @property string|null $mobile
 * @property string|null $avatar
 * @property string|null $username
 * @property string|null $two_factor_secret
 * @property int $two_factor_enabled
 * @property string|null $two_factor_method
 * @property string|null $national_id
 * @property string|null $birth_date
 * @property string|null $gender
 * @property string|null $address
 * @property string|null $bio
 * @property float|string|null $current_balance
 * @property float|string|null $usdt_balance
 * @property float|string|null $wallet_usdt
 * @property float|string|null $balance_irt
 * @property float|string|null $referral_quality_score
 */
#[\AllowDynamicProperties]
class User extends Model
{
    protected static string $table = 'users';

    /**
     * 🛡️ Phase 3: Relations برای eager loading
     *
     * استفاده در Controller:
     *   $users = $this->userModel->allWith(['wallet', 'transactions'], 50);
     *   // به جای N+1 query، فقط ۳ query اجرا می‌شود
     *
     * در view/template:
     *   foreach ($users as $user) {
     *       echo $user->full_name;
     *       echo $user->wallet->balance ?? 0;        // loaded eagerly
     *       echo count($user->transactions);         // loaded eagerly
     *   }
     */
    protected array $relations = [
        'wallet'       => [\App\Models\Wallet::class,      'user_id', 'one'],
        'transactions' => [\App\Models\Transaction::class, 'user_id', 'many'],
    ];

    protected array $fillable = [
        'full_name', 'email', 'mobile', 'password', 'username',
        'bio', 'avatar', 'website', 'location', 'gender',
        'birth_date', 'national_id', 'address',
        'role', 'status', 'level_slug', 'fraud_score',
        'is_admin', 'email_verified_at', 'email_verification_token',
        'two_factor_secret', 'two_factor_enabled', 'two_factor_method',
        'referral_code', 'referred_by',
        'country_code', 'country_name',
    ];
    protected static array $searchable = ['full_name', 'email', 'mobile'];

    /** @var array<string, array{string, string}> */
    protected static array $filterable = [
        'status' => ['status', '='],
        'role' => ['role', '='],
    ];

    /**
     * شخصی‌سازی جستجو برای مدل کاربر (افزودن تطبیق دقیق برای کد معرف)
     */
    public function applySearch(\Core\QueryBuilder $query, ?string $term): \Core\QueryBuilder
    {
        $term = trim((string)$term);
        if (empty($term)) {
            return $query;
        }

        $escaped = $this->escapeLikeValue($term);
        $like = "%{$escaped}%";

        return $query->where(function(\Core\QueryBuilder $q) use ($like, $term) {
            // ۱. جستجوی مشابهت (LIKE) روی فیلدهای استاندارد
            foreach (static::$searchable as $index => $column) {
                if ($index === 0) {
                    $q->where((string)$column, 'LIKE', $like);
                } else {
                    $q->orWhere((string)$column, 'LIKE', $like);
                }
            }
            // ۲. جستجوی دقیق (EXACT) روی فیلدهای خاص دامنه
            $q->orWhere('referral_code', '=', $term);
        });
    }

    public function findByEmail(string $email): ?\stdClass
    {
        $row = $this->db->fetch("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
        return $this->normalizeToObject($row);
    }

    public function findByMobile(string $mobile): ?\stdClass
    {
        $row = $this->db->fetch("SELECT * FROM users WHERE mobile = ? LIMIT 1", [$mobile]);
        return $this->normalizeToObject($row);
    }

    public function findByReferralCode(string $code): ?\stdClass
    {
        $row = $this->db->fetch("SELECT * FROM users WHERE referral_code = ? LIMIT 1", [$code]);
        return $this->normalizeToObject($row);
    }

    public function findByCredentials(string $identifier): ?\stdClass
    {
        $row = $this->db->fetch(
            "SELECT * FROM users WHERE (email = ? OR username = ? OR mobile = ?) AND deleted_at IS NULL LIMIT 1",
            [$identifier, $identifier, $identifier]
        );
        return $this->normalizeToObject($row);
    }

    public function findByCredentialsForUpdate(string $identifier): ?\stdClass
    {
        $row = $this->db->fetch(
            "SELECT * FROM users WHERE (email = ? OR username = ? OR mobile = ?) AND deleted_at IS NULL LIMIT 1 FOR UPDATE",
            [$identifier, $identifier, $identifier]
        );
        return $this->normalizeToObject($row);
    }

    public function findById(int $userId): ?\stdClass
    {
        $row = $this->db->table('users')
            ->where('id', '=', $userId)
            ->whereNull('deleted_at')
            ->first();
        return $this->normalizeToObject($row);
    }

    public function findByIdForUpdate(int $userId): ?\stdClass
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("findByIdForUpdate must be called within an active database transaction.");
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $this->fetchObject($stmt);
        return $this->normalizeToObject($row);
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06.
     *
     * Return the id of every non-deleted user holding a given role.
     * Used by RoleController::update() and RoleController::toggle() to
     * walk the affected users and clear their permission cache after a
     * role definition changes. The controller used to inline the
     * `SELECT id FROM users WHERE role_id = ? AND deleted_at IS NULL`
     * twice; we centralise it here so the column reference and the
     * soft-delete predicate live in exactly one place.
     *
     * @return int[]  list of user ids
     */
    public function findIdsByRoleId(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM users WHERE role_id = ? AND deleted_at IS NULL",
            [$roleId]
        );
        return array_map(static fn($r) => (int)($r->id ?? 0), $rows);
    }

    public function incrementFraudScore(int $userId, int $amount = 1): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET fraud_score = COALESCE(fraud_score, 0) + ?, updated_at = NOW() WHERE id = ?",
            [$amount, $userId]
        );
    }

    public function isBlacklisted(int $userId): bool
    {
        $row = $this->db->fetch("SELECT is_blacklisted FROM users WHERE id = ?", [$userId]);
        return (bool)($row->is_blacklisted ?? false);
    }

    public function updateLastLogin(int $userId, string $ip, string $userAgent): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET last_login = NOW(), last_ip = ?, last_user_agent = ?, updated_at = NOW() WHERE id = ?",
            [$ip, $userAgent, $userId]
        );
    }

    public function storeRememberToken(int $userId, string $hashedToken, string $expiresAt): bool
    {
        $statement = $this->db->query(
            'UPDATE users SET remember_token=?, remember_expires_at=?, updated_at=NOW() WHERE id=?',
            [$hashedToken, $expiresAt, $userId]
        );
        return $statement->rowCount() === 1;
    }

    public function revokeRememberToken(int $userId): bool
    {
        $statement = $this->db->query(
            'UPDATE users SET remember_token=NULL, remember_expires_at=NULL, updated_at=NOW() WHERE id=?',
            [$userId]
        );
        return $statement->rowCount() > 0;
    }

    public function verifyEmail(int $userId): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }

    /**
     * CRITICAL-03 Fix: Atomic check and update for account lockout to prevent race conditions.
     * Checks if the user status is 'active' and updates it to 'locked' if rowCount matches.
     */
    public function lockIfExceededAttempts(int $userId): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET status = 'locked', updated_at = NOW()
             WHERE id = ? AND status = 'active'",
            [$userId]
        );
        return $stmt->rowCount() === 1;
    }

    /**
     * CRIT-06 Fix: به‌روزرسانی اتمیک تایم‌اسلایس 2FA برای جلوگیری از Race Condition و Replay Attack
     */
    public function update2FATimeslice(int $userId, int $slice): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET last_2fa_timeslice = ?
             WHERE id = ? AND (last_2fa_timeslice IS NULL OR last_2fa_timeslice < ?)",
            [$slice, $userId, $slice]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $this->applySearch($query, str_value($filters['search']));
        }

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'DESC')
                     ->limit($limit)
                     ->offset($offset)
                     ->get();
    }

    /** @param array<string, mixed> $filters */
    public function countWithFilters(array $filters = []): int
    {
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $this->applySearch($query, str_value($filters['search']));
        }

        $query = $this->applyFilters($query, $filters);

        return $query->count();
    }

    public function getAdminStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) AS banned_count
             FROM users
             WHERE deleted_at IS NULL"
        ) ?: (object)[];
    }

    /** @return list<\stdClass> */
    public function getUserSettings(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?",
            [$userId]
        );
    }

    public function upsertSetting(int $userId, string $key, string $value): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [$userId, $key, $value]
        );
    }

    public function deleteSettings(int $userId): bool
    {
        return (bool)$this->db->query("DELETE FROM user_settings WHERE user_id = ?", [$userId]);
    }

    // ==================== ANALYTICS METHODS ====================

    /**
     * آمار کلی کاربران
     * M39: Fixed status inconsistency (int vs string) - use string values
     */
    /** @return array<string, mixed> */
    public function getUserCountStats(): array
    {
        $row = $this->db->fetch("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) as banned,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
            FROM users
            WHERE deleted_at IS NULL
        ");
        return [
            'total' => (int)($row->total ?? 0),
            'active' => (int)($row->active ?? 0),
            'banned' => (int)($row->banned ?? 0),
            'suspended' => (int)($row->suspended ?? 0),
        ];
    }

    /**
     * آمار ثبت‌نام جدید
     */
    /** @return array<string, mixed> */
    public function getNewUserStats(): array
    {
        $today = date('Y-m-d');
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as new_today,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_this_week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_this_month
            FROM users
            WHERE deleted_at IS NULL
        ", [$today]);
        return [
            'new_today' => (int)($row->new_today ?? 0),
            'new_this_week' => (int)($row->new_this_week ?? 0),
            'new_this_month' => (int)($row->new_this_month ?? 0),
        ];
    }

    /**
     * آمار فعالیت کاربران (DAU, WAU, MAU)
     */
    /** @return array<string, mixed> */
    public function getUserActivityStats(): array
    {
        $today = date('Y-m-d');
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN DATE(last_login) = ? THEN 1 ELSE 0 END) as dau,
                SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as wau,
                SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as mau
            FROM users
            WHERE deleted_at IS NULL
        ", [$today]);
        return [
            'dau' => (int)($row->dau ?? 0),
            'wau' => (int)($row->wau ?? 0),
            'mau' => (int)($row->mau ?? 0),
        ];
    }

    /**
     * آمار سطح‌بندی کاربران
     */
    /** @return array<string, mixed> */
    public function getUserTierStats(): array
    {
        $rows = $this->db->fetchAll("
            SELECT COALESCE(level_slug, 'silver') as tier, COUNT(*) as count
            FROM users
            WHERE deleted_at IS NULL
            GROUP BY level_slug
        ");
        $tiers = ['silver' => 0, 'gold' => 0, 'vip' => 0];
        foreach ($rows as $row) {
            $tiers[(string)$row->tier] = (int)$row->count;
        }
        return $tiers;
    }

    // ==================== RBAC (ROLES & PERMISSIONS) HELPER METHODS ====================

    public function hasPermission(int $userId, string $slug): bool
    {
        $userRow = $this->db->fetch("SELECT role FROM users WHERE id = ? LIMIT 1", [$userId]);
        if ($userRow && in_array((string)($userRow->role ?? ''), ['super_admin', 'superadmin'], true)) {
            return true;
        }

        $result = $this->db->table('user_roles as ur')
            ->join('role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_id', '=', $userId)
            ->where('p.slug', '=', $slug)
            ->selectRaw('1')
            ->first();

        return (bool)$result;
    }

    /** @return list<string> */
    public function getUserPermissions(int $userId): array
    {
        $result = $this->db->table('user_roles as ur')
            ->join('role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_id', '=', $userId)
            ->select('p.slug')
            ->get();

        return array_map(fn($p) => (string)($p->slug ?? ''), $result);
    }

    public function assignRole(int $userId, int $roleId, ?int $grantedBy = null): bool
    {
        return (bool)$this->db->query(
            "INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, ?, ?, NOW())",
            [$userId, $roleId, $grantedBy]
        );
    }

    public function removeRole(int $userId, int $roleId): bool
    {
        return (bool)$this->db->table('user_roles')
            ->where('user_id', '=', $userId)
            ->where('role_id', '=', $roleId)
            ->delete();
    }


    /**
     * اعمال فیلترهای جستجو روی QueryBuilder
     * @param \Core\QueryBuilder $query
     * @param array<string, mixed> $filters
     * @return \Core\QueryBuilder
     */
    public function applyFilters(\Core\QueryBuilder $query, array $filters): \Core\QueryBuilder
    {
        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }
        if (!empty($filters['kyc_status'])) {
            $query->where('kyc_status', '=', $filters['kyc_status']);
        }
        if (!empty($filters['role'])) {
            $query->where('role', '=', $filters['role']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
        if (isset($filters['is_verified'])) {
            $query->where('email_verified_at', $filters['is_verified'] ? 'IS NOT NULL' : 'IS NULL', null);
        }
        return $query;
    }

    /**
     * Auto-generate a unique referral code on new user creation.
     */
    /** پیدا کردن کاربر با remember token */
    public function findByRememberToken(string $hashedToken): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE remember_token = ? AND deleted_at IS NULL LIMIT 1",
            [$hashedToken]
        );
    }

    public function generateReferralCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen((string)$chars) - 1)];
            }
        } while ($this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE referral_code = ?", [$code]) > 0);
        return $code;
    }
}
