<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * @property int $id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property bool|int $enabled
 * @property string $type
 * @property mixed $value
 * @property float|null $enabled_percentage
 * @property string|null $enabled_for_roles
 * @property string|null $enabled_for_users
 * @property string|null $depends_on
 * @property string|null $environments
 * @property string|null $tags
 * @property string|null $metadata
 * @property int|null $owner_user_id
 * @property int|null $min_age
 * @property int|null $max_age
 * @property string|null $priority
 * @property string $created_at

 * @property string|null $enabled_for_countries
 * @property string|null $enabled_for_devices
 * @property string|null $enabled_for_routes
 * @property string|null $enabled_from
 * @property string|null $enabled_until
 * @property int|null $min_age
 * @property int|null $max_age
 * @property string|null $updated_at
 * @property string|null $deleted_at

 * @property int|null $enabled_percentage
 * @property string|null $enabled_from
 * @property string|null $enabled_until
 * @property string|null $targeted_user_ids
 * @property string|null $targeted_roles
 * @property string|null $targeted_countries
 * @property string|null $targeted_plans
 * @property string|null $targeted_devices
 * @property string|null $targeted_routes
 * @property int|null $target_age_min
 * @property int|null $target_age_max
 * @property float|null $percentage_rollout
 * @property float|null $rollout_percentage
 * @property string|null $config_values
 */
#[\AllowDynamicProperties]
/**
 * FeatureFlag Model - Pure Data Access Layer
 * 
 * مسئولیت: ذخیره‌سازی و بازیابی داده‌های خام Feature Flags.
 * مدیریت Targeting، HTTP Context، Caching و رویدادها کاملاً در FeatureFlagService انجام می‌شود.
 */
class FeatureFlag extends Model

{
    protected array $fillable = [
        'name', 'description', 'enabled', 'enabled_percentage',
        'enabled_for_roles', 'enabled_for_users', 'metadata',
        'enabled_from', 'enabled_until', 'depends_on',
        'environments', 'priority', 'tags',
    ]; 

    protected static string $table = 'feature_flags';

    /** @var array<string, self> */
    private array $cachedFeatures = [];
    private bool $loaded = false;
    
    private const ALLOWED_UPDATE_FIELDS = [
        'enabled', 'description', 'enabled_percentage',
        'enabled_for_roles', 'enabled_for_users', 'metadata',
        'enabled_from', 'enabled_until', 'depends_on',
        'environments', 'priority', 'tags',
    ];
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    /**
     * بارگذاری تمام فیچرها در حافظه کش در سطح ریکوئست
     */
    private function loadAll(): void
    {
        if ($this->loaded) {
            return;
        }
        
        $sql = "SELECT * FROM feature_flags ORDER BY name ASC";
        $features = $this->db->fetchAll($sql);
        
        if (empty($features)) {
            $this->loaded = true;
            return;
        }

        foreach ($features as $row) {
            $feature = new self($this->db);
            foreach (get_object_vars($row) as $key => $val) {
                $feature->$key = $val;
            }
            // IMPORTANT: Use the actual column name 'enabled' from the database
            $feature->enabled = (bool)($row->enabled ?? false);
            $feature->enabled_percentage = (int)($row->rollout_percentage ?? 100);
            $feature->enabled_for_users = $row->enabled_for_users ?? null;
            $feature->enabled_from = $row->enabled_from ?? null;
            $feature->enabled_until = $row->enabled_until ?? null;
            $feature->targeted_user_ids = $row->targeted_user_ids ?? null;
            $feature->targeted_roles = $row->targeted_roles ?? null;
            $feature->targeted_countries = $row->targeted_countries ?? null;
            $feature->targeted_plans = $row->targeted_plans ?? null;
            $feature->targeted_devices = $row->targeted_devices ?? null;
            $feature->targeted_routes = $row->targeted_routes ?? null;
            $feature->target_age_min = $row->target_age_min ?? null;
            $feature->target_age_max = $row->target_age_max ?? null;
            $feature->percentage_rollout = $row->rollout_percentage ?? 100;
            
            // Ensure we use name as the index in cachedFeatures
            $this->cachedFeatures[$row->name] = $feature;
        }
        
        $this->loaded = true;
    }
    
    /**
     * تعداد رکوردهای کش جدول دیتابیسی
     */
    public function getCacheCount(): int
    {
        try {
            $row = $this->db->fetch("SELECT COUNT(*) as cnt FROM feature_flag_cache");
            return (int)($row->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * پاک کردن Cache های جدول موقت و رم
     */
    public function clearCache(): void
    {
        $this->cachedFeatures = [];
        $this->loaded = false;
        
        try {
            // پاکسازی فیزیکی کش جدول دیتابیس (اگر وجود داشته باشد)
            $this->db->query("DELETE FROM feature_flag_cache");
        } catch (\Throwable $e) {
            // Ignore if table doesn't exist
        }
    }

    /**
     * پاکسازی Metrics قدیمی
     */
    public function cleanupMetrics(int $days = 30): void
    {
        $sql = "CALL sp_cleanup_feature_metrics(?)";
        $this->db->query($sql, [$days]);
    }
    
    /** @return list<\App\Models\FeatureFlag> */
    public function getAll(): array
    {
        $this->loadAll();
        return array_values($this->cachedFeatures);
    }
    
    public function findByName(string $name): ?self
    {
        $this->loadAll();
        return $this->cachedFeatures[$name] ?? null;
    }
    
    public function toggle(string $name): bool
    {
        $feature = $this->findByName((string)$name);
        if (!$feature) {
            return false;
        }
        /** @var object{enabled: bool|int} $feature */
        $newStatus = !$feature->enabled;
        
        $sql = "UPDATE feature_flags SET enabled = ?, updated_at = NOW() WHERE name = ?";
        $result = $this->db->query($sql, [$newStatus ? 1 : 0, $name]);
        
        $this->clearCache();
        
        return (bool)$result;
    }
    
    /** @param array<string, mixed> $data */
    public function update(int|string $name, array $data): bool
    {
        $feature = $this->findByName((string)$name);
        if (!$feature) {
            throw new \InvalidArgumentException("Feature '{$name}' not found");
        }
        
        $fields = [];
        $params = [];
        
        foreach ((array)$data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'])) {
                $value = is_array($value) ? json_encode($value) : $value;
            }
            
            if ($key === 'enabled_percentage') {
                $value = is_numeric($value) ? max(0, min(100, (int)$value)) : 0;
            }
            
            if ($key === 'enabled') {
                $value = $value ? 1 : 0;
            }
            
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $name;
        
        $sql = "UPDATE feature_flags SET " . implode(', ', $fields) . " WHERE name = ?";
        $result = $this->db->query($sql, $params);
        
        $this->clearCache();
        
        return (bool)$result;
    }
    
    /** @param array<string, mixed> $data */
    public function create(array $data): bool
    {
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        $featureName = is_string($data['name'] ?? null) ? $data['name'] : '';
        if ($featureName === '') {
            throw new \InvalidArgumentException('Feature name must be a string');
        }
        if ($this->findByName($featureName)) {
            throw new \InvalidArgumentException("Feature '{$featureName}' already exists");
        }
        
        $defaults = [
            'enabled' => false,
            'enabled_percentage' => 100,
            'enabled_for_roles' => null,
            'enabled_for_users' => null,
            'metadata' => null,
            'enabled_from' => null,
            'enabled_until' => null,
            'depends_on' => null,
            'environments' => null,
            'priority' => 0,
            'tags' => null,
        ];
        
        $data = array_merge($defaults, $data);
        
        foreach (['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        $sql = "INSERT INTO feature_flags 
                (name, description, enabled, enabled_percentage, enabled_for_roles, enabled_for_users, 
                 metadata, enabled_from, enabled_until, depends_on, environments, priority, tags, 
                 created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $result = $this->db->query($sql, [
            $data['name'],
            $data['description'],
            $data['enabled'] ? 1 : 0,
            $data['enabled_percentage'],
            $data['enabled_for_roles'],
            $data['enabled_for_users'],
            $data['metadata'],
            $data['enabled_from'],
            $data['enabled_until'],
            $data['depends_on'],
            $data['environments'],
            $data['priority'],
            $data['tags'],
        ]);
        
        $this->clearCache();
        
        return (bool)$result;
    }
    
    public function delete(int|string $name): bool
    {
        $feature = $this->findByName((string)$name);
        if (!$feature) {
            return false;
        }
        
        $sql = "DELETE FROM feature_flags WHERE name = ?";
        $result = $this->db->query($sql, [$name]);
        
        $this->clearCache();
        
        return (bool)$result;
    }
    
    /**
     * دریافت آمارهای پایه فیچرتراکینگ در دیتابیس
     */
    /** @return array<string, int> */
    public function getStats(): array
    {
        $all = $this->getAll();
        
        $stats = [
            'total' => count($all),
            'enabled' => 0,
            'disabled' => 0,
            'role_restricted' => 0,
            'user_restricted' => 0,
            'percentage_based' => 0,
            'time_scheduled' => 0,
            'with_dependencies' => 0,
        ];
        
        /** @var object{enabled: bool|int, enabled_for_roles: mixed, enabled_for_users: mixed, enabled_percentage: int|float, enabled_from: mixed, enabled_until: mixed, depends_on: mixed} $feature */
        foreach ($all as $feature) {
            if ($feature->enabled) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
            
            if ($feature->enabled_for_roles) {
                $stats['role_restricted']++;
            }
            
            if ($feature->enabled_for_users) {
                $stats['user_restricted']++;
            }
            
            if ($feature->enabled_percentage < 100) {
                $stats['percentage_based']++;
            }
            
            if ($feature->enabled_from || $feature->enabled_until) {
                $stats['time_scheduled']++;
            }
            
            if ($feature->depends_on ?? null) {
                $stats['with_dependencies']++;
            }
        }
        
        return $stats;
    }
    
    /** @return list<\stdClass> */
    public function getHistory(string $name, int $limit = 50): array
    {
        $sql = "SELECT * FROM feature_flag_history 
                WHERE feature_name = ? 
                ORDER BY changed_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$name, $limit]) ?: [];
    }
    
    /** @return list<\stdClass> */
    public function getMetrics(string $name, int $hours = 24): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_checks,
                    SUM(check_result) as allowed_count,
                    COUNT(*) - SUM(check_result) as denied_count,
                    AVG(response_time_ms) as avg_response_time,
                    MAX(response_time_ms) as max_response_time,
                    check_reason,
                    COUNT(*) as reason_count
                FROM feature_flag_metrics
                WHERE feature_name = ?
                AND checked_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY check_reason";
        
        return $this->db->fetchAll($sql, [$name, $hours]) ?: [];
    }
    
    public function getConfigValue(string $name, string $key, mixed $default = null): mixed
    {
        $feature = $this->findByName((string)$name);
        
        if (!$feature || !isset($feature->config_values)) {
            return $default;
        }
        
        $config = (array)(json_decode($feature->config_values, true) ?? []);
        
        return $config[$key] ?? $default;
    }
}