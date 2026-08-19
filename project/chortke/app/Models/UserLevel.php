<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserLevel extends Model {
    /**
     * نام جدول (الزام قرارداد Core\\Model).
     * این مدل عمدتاً از کوئری‌های خام استفاده می‌کند، اما برای سازگاری با
     * متدهای پایه‌ی Model، جدول مرجع آن تعریف می‌شود.
     */
    protected static string $table = 'user_levels';

/**
     * یافتن با ID
     */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM user_levels WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * یافتن با slug
     */
    public function findBySlug(string $slug): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM user_levels WHERE slug = ?");
        $stmt->execute([$slug]);
        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * تمام سطوح (مرتب‌شده)
     */
    /** @return list<\stdClass> */
    /** @param array<string, mixed> $filters */
    public function all(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        // Read onlyActive from filters if provided, defaulting to true for UserLevel
        $onlyActive = $filters['onlyActive'] ?? true;

        // شروع کوئری
        $sql = "SELECT * FROM user_levels";
        
        // اگر onlyActive فعال باشد، فیلتر مربوطه را به کوئری اضافه می‌کنیم
        if ($onlyActive) {
            $sql .= " WHERE is_active = 1";
        }

        // اضافه کردن ترتیب و محدود کردن نتایج
        $sql .= " ORDER BY sort_order ASC LIMIT :limit OFFSET :offset";

        // آماده‌سازی و اجرای کوئری
        $stmt = $this->db->prepare($sql);
        
        // بایند کردن پارامترها
        $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, \PDO::PARAM_INT);

        // اجرای کوئری
        $stmt->execute();

        // بازگشت نتایج
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * بروزرسانی سطح
     */
    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = [
            'name', 'icon', 'color', 'sort_order',
            'min_score',
            'purchase_price_irt', 'purchase_price_usdt', 'purchase_duration_days',
            'earning_bonus_percent', 'referral_bonus_percent', 'daily_task_limit_bonus',
            'withdrawal_limit_bonus', 'priority_support', 'special_badge', 'is_active',
        ];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) return false;
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE user_levels SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * سطح بالاتر بعدی
     */
    public function getNextLevel(string $currentSlug): ?\stdClass
    {
        $current = $this->findBySlug($currentSlug);
        if (!$current) return null;

        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE sort_order > ? AND is_active = 1
            ORDER BY sort_order ASC 
            LIMIT 1
        ");
        $stmt->execute([$current->sort_order]);
        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * سطح پایین‌تر بعدی
     */
    public function getPreviousLevel(string $currentSlug): ?\stdClass
    {
        $current = $this->findBySlug($currentSlug);
        if (!$current) return null;

        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE sort_order < ? AND is_active = 1
            ORDER BY sort_order DESC 
            LIMIT 1
        ");
        $stmt->execute([$current->sort_order]);
        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * بالاترین سطح قابل دسترسی با فعالیت
     */
    public function getEligibleLevel(float $totalScore): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE is_active = 1
            AND min_score <= ?
            ORDER BY sort_order DESC
            LIMIT 1
        ");
        $stmt->execute([$totalScore]);
        /** @var \stdClass|false $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }

    /**
     * آمار کاربران هر سطح
     */
    /** @return array<string, int> */
    public function getUserCountPerLevel(): array
    {
        $stmt = $this->db->prepare("
            SELECT level_slug, COUNT(*) as user_count 
            FROM users 
            WHERE deleted_at IS NULL
            GROUP BY level_slug
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->level_slug] = (int) $row->user_count;
        }
        return $result;
    }

    /**
     * ایجاد سطح جدید
     */
    /** @param array<string, mixed> $data */
    public function createLevel(array $data): ?\stdClass
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_levels (
                name, slug, icon, color, sort_order, min_score,
                purchase_price_irt, purchase_price_usdt, purchase_duration_days,
                earning_bonus_percent, referral_bonus_percent, daily_task_limit_bonus,
                withdrawal_limit_bonus, priority_support, special_badge, is_active,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");

        $ok = $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['icon'] ?? 'workspace_premium',
            $data['color'] ?? '#c0c0c0',
            $data['sort_order'] ?? 0,
            $data['min_score'] ?? 0,
            $data['purchase_price_irt'] ?? 0,
            $data['purchase_price_usdt'] ?? 0,
            $data['purchase_duration_days'] ?? 30,
            $data['earning_bonus_percent'] ?? 0,
            $data['referral_bonus_percent'] ?? 0,
            $data['daily_task_limit_bonus'] ?? 0,
            $data['withdrawal_limit_bonus'] ?? 0,
            $data['priority_support'] ?? 0,
            $data['special_badge'] ?? 0,
            $data['is_active'] ?? 1,
        ]);

        if (!$ok) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * حذف سطح
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_levels WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * بررسی وجود slug تکراری
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM user_levels WHERE slug = ?";
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * همه سطوح فعال کاربری
     * @return array<object>
     */
    public function getAllActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM user_levels WHERE is_active = 1 ORDER BY min_score ASC"
        ) ?: [];
    }

    /**
     * بیشترین sort_order موجود
     */
    public function getMaxSortOrder(): int
    {
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM user_levels");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

}