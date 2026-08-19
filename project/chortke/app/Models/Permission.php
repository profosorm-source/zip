<?php

namespace App\Models;

use Core\Model;

class Permission extends Model
{
    protected static string $table = 'permissions';
    
    /** @var array<int, list<string>> */
    private array $userPermissionsCache = [];
    /** @var array<int, bool> */
    private array $isSuperAdminCache = [];

    /**
     * یافتن دسترسی با ID
     */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * یافتن دسترسی با slug
     */
    public function findBySlug(string $slug): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions WHERE slug = ?");
        $stmt->execute([$slug]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * دریافت تمام دسترسی‌ها
     */
    /** @param array<string, mixed> $filters */
    /** @return list<\stdClass> */
    public function all(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions ORDER BY group_name ASC, name ASC");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت دسترسی‌ها گروه‌بندی شده
     */
    /** @return array<string, list<\stdClass>> */
    public function allGrouped(): array
    {
        $permissions = $this->all();
        $grouped = [];

        foreach ($permissions as $perm) {
            $group = (string)($perm->group_name ?? 'other');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $perm;
        }

        return $grouped;
    }

    /**
     * نام فارسی گروه‌ها
     */
    /** @return array<string, string> */
    public function groupLabels(): array
    {
        return [
            'users'       => 'مدیریت کاربران',
            'finance'     => 'مدیریت مالی',
            'kyc'         => 'احراز هویت',
            'tasks'       => 'تسک‌ها و تبلیغات',
            'tickets'     => 'پشتیبانی',
            'investments' => 'سرمایه‌گذاری',
            'lottery'     => 'قرعه‌کشی',
            'stories'     => 'سفارش استوری',
            'content'     => 'محتوا و استعداد',
            'settings'    => 'تنظیمات سیستم',
            'roles'       => 'نقش‌ها و دسترسی‌ها',
            'logs'        => 'لاگ‌ها',
            'reports'     => 'گزارش‌ها',
            'banners'     => 'بنر و تبلیغات',
            'coupons'     => 'کوپن تخفیف',
            'referrals'   => 'سیستم معرفی',
            'system'      => 'سیستم',
            'bugs'        => 'گزارش باگ',
            'other'       => 'سایر',
        ];
    }

    /**
     * بررسی دسترسی کاربر با ککش داینامیک درون‌حافظه‌ای
     */
    public function userHasPermission(int $userId, string $permSlug): bool
    {
        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        if (!isset($this->userPermissionsCache[$userId])) {
            $this->userPermissionsCache[$userId] = $this->getUserPermissions($userId);
        }

        return \in_array($permSlug, $this->userPermissionsCache[$userId], true);
    }

    /**
     * بررسی آیا کاربر super_admin است با ککش درون‌حافظه‌ای
     */
    public function isSuperAdmin(int $userId): bool
    {
        if (isset($this->isSuperAdminCache[$userId])) {
            return $this->isSuperAdminCache[$userId];
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.id = ?
              AND u.deleted_at IS NULL
              AND (u.role = 'super_admin' OR r.slug = 'super_admin')
        ");

        $stmt->execute([$userId]);
        $isSuper = (int)$stmt->fetchColumn() > 0;

        $this->isSuperAdminCache[$userId] = $isSuper;
        return $isSuper;
    }

    /**
     * دریافت تمام دسترسی‌های کاربر (slug ها)
     */
    /** @return list<string> */
    public function getUserPermissions(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT p.slug
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");

        $stmt->execute([$userId]);
        $permissions = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        // Backward compatibility for deployments that still store a single role in users.role.
        if (empty($permissions)) {
            $fallback = $this->db->prepare("
                SELECT DISTINCT p.slug
                FROM users u
                INNER JOIN roles r ON r.slug = u.role
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE u.id = ? AND u.deleted_at IS NULL
            ");
            $fallback->execute([$userId]);
            $permissions = $fallback->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        }

        return $permissions;
    }
}