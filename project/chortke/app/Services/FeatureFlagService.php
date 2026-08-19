<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Models\KYCVerification;
use App\Contracts\FeatureFlagRepositoryInterface;
use Core\Cache;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use App\Events\FeatureFlagChanged;
use App\Listeners\LogFeatureFlagChange;

/**
 * Feature Flag Service
 * 
 * مدیریت Feature Flags با targeting پیشرفته
 * شامل: user targeting، role، کشور، پلن، device، route، age، percentage rollout
 */
class FeatureFlagService implements FeatureFlagRepositoryInterface
{
    private FeatureFlag $featureModel;
    private User $userModel;
    private KYCVerification $kycModel;
    private \Core\Cache $cache;



    private const ALLOWED_UPDATE_FIELDS = [
        'enabled', 'description', 'enabled_percentage',
        'enabled_for_roles', 'enabled_for_users', 'metadata',
        'enabled_from', 'enabled_until', 'depends_on',
        'environments', 'priority', 'tags',
    ];
    
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \Core\Cache $cache,
        FeatureFlag $featureModel,
        User $userModel,
        KYCVerification $kycModel
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;

        
        $this->featureModel = $featureModel;
        $this->userModel = $userModel;
        $this->kycModel = $kycModel;
        }

    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     * Guarantees ?object from Model->find* results.
     * Guard: if (!$x) before any $x->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    /**
     * بررسی آیا یک فیچر برای کاربر فعال است
     * شامل: targeting، زمان‌بندی، درصد کاربران
     */
    public function isEnabled(string $name, ?int $userId = null, ?array $context = null): bool
    {
        // cache check (🚀 BUG FIX [M-03]: Use SHA256 to avoid collisions)
        $contextHash = 'default';
        if ($context) {
            $json = json_encode($context);
            if ($json === false) {
                $this->logger->error('feature_flag.json_encode_failed', ['context' => $context]);
                $json = serialize($context); // fallback
            }
            $contextHash = hash('sha256', $json);
        }
        
        $userIdStr = $userId !== null ? (string)$userId : 'anon';
        $cacheKey = "enabled:{$name}:{$userIdStr}:{$contextHash}";
        
        if ($cached = $this->cache->tags(['feature_flag'])->get($cacheKey)) {
            return (bool)$cached;
        }

        try {
            $feature = $this->featureModel->findByName($name);
        } catch (\Throwable $e) {
            $feature = null;
        }

        if (!$feature) {
            $fallbackEnabled = config("feature_flags.{$name}.enabled");
            if ($fallbackEnabled !== null) {
                return (bool)$fallbackEnabled;
            }
            $this->cache->tags(['feature_flag'])->put($cacheKey, 0, 5);
            return false;
        }

        if (!$feature->enabled) {
            $this->cache->tags(['feature_flag'])->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی زمان‌بندی
        if (!$this->checkTimeSchedule($feature)) {
            $this->cache->tags(['feature_flag'])->put($cacheKey, 0, 5);
            return false;
        }

        // اگر user id نیست، فقط check عمومی
        if (!$userId) {
            $result = (bool)$feature->enabled;
            $this->cache->tags(['feature_flag'])->put($cacheKey, $result ? 1 : 0, 5);
            return $result;
        }

        // دریافت اطلاعات کاربر
        $userContext = $this->getUserContext($userId, $context);
        
        // بررسی targeting
        if (!$this->checkTargeting($feature, $userContext)) {
            $this->cache->tags(['feature_flag'])->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی percentage rollout (consistent و reproducible)
        if (!$this->checkPercentageRollout($feature, $userId)) {
            $this->cache->tags(['feature_flag'])->put($cacheKey, 0, 5);
            return false;
        }

        $this->cache->tags(['feature_flag'])->put($cacheKey, 1, 5);
        return true;
    }

    /**
     * بررسی چند فیچر به صورت AND
     */
    public function areEnabled(array $names, ?int $userId = null, ?array $context = null): bool
    {
        foreach ($names as $name) {
            if (!$this->isEnabled($name, $userId, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * دریافت تمام فیچرهای فعال برای کاربر
     * @return list<string>
     */
    public function getEnabled(?int $userId = null, ?array $context = null): array
    {
        $all = $this->featureModel->getAll();
        $enabled = [];
        
        foreach ($all as $feature) {
            if ($this->isEnabled($feature->name, $userId, $context)) {
                $enabled[] = $feature->name;
            }
        }
        
        return $enabled;
    }

    /**
     * دریافت مقدار پارامتر فیچر (برای اعداد dynamic)
     * مثال: getValue('lottery_profit_percentage', 10) => 15
     */
    public function getValue(string $name, mixed $default = null): mixed
    {
        $feature = $this->featureModel->findByName($name);
        if (!$feature) {
            return $default;
        }

        $config = (array)json_decode(str_value($feature->config_values), true);
        return $config;
    }

    /**
     * دریافت یک پارامتر خاص از feature flag
     */
    public function getConfig(string $featureName, string $configKey, mixed $default = null): mixed
    {
        $config = $this->getValue($featureName);
        if (is_array($config) && isset($config[$configKey])) {
            return $config[$configKey];
        }
        
        // بازگشت به فایل کانفیگ به عنوان Fallback لایه زیرساخت
        $configValue = config("feature_flags.{$featureName}.{$configKey}");
        if ($configValue !== null) {
            return $configValue;
        }
        
        return $default;
    }

    /**
     * بررسی targeting پیشرفته
     */
    /** @param array<string, mixed> $userContext */
    private function checkTargeting(\App\Models\FeatureFlag $feature, array $userContext): bool
    {
        // بررسی user_ids خاص
        if ($feature->targeted_user_ids) {
            $userIds = (array)json_decode($feature->targeted_user_ids, true);
            if (!empty($userIds) && !in_array($userContext['user_id'], $userIds)) {
                return false;
            }
        }

        // بررسی roles
        if ($feature->targeted_roles) {
            $roles = (array)json_decode($feature->targeted_roles, true);
            if (!empty($roles) && !in_array($userContext['role'], $roles)) {
                return false;
            }
        }

        // بررسی کشورها
        if ($feature->targeted_countries) {
            $countries = (array)json_decode($feature->targeted_countries, true);
            if (!empty($countries) && !in_array($userContext['country'] ?? null, $countries)) {
                return false;
            }
        }

        // بررسی پلن‌ها
        if ($feature->targeted_plans) {
            $plans = (array)json_decode($feature->targeted_plans, true);
            if (!empty($plans) && !in_array($userContext['plan'] ?? null, $plans)) {
                return false;
            }
        }

        // بررسی devices
        if ($feature->targeted_devices) {
            $devices = (array)json_decode($feature->targeted_devices, true);
            if (!empty($devices) && !in_array($userContext['device'] ?? null, $devices)) {
                return false;
            }
        }

        // بررسی routes
        if ($feature->targeted_routes) {
            $routes = (array)(json_decode((string)($feature->targeted_routes ?? '[]'), true) ?? []);
            $currentRoute = str_value($userContext['route'] ?? (app()->request->uri()));
            
            if (!empty($routes) && $currentRoute !== null) {
                $match = false;
                foreach ($routes as $route) {
                    // تطابق دقیق یا تطابق کامل پیشوند پوشه برای تضمین امنیت و عدم دور زدن مسیرها
                    if ($currentRoute === $route || strpos($currentRoute, $route . '/') === 0 || strpos($currentRoute, $route . '?') === 0) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) return false;
            }
        }

        // بررسی age
        if ($feature->target_age_min || $feature->target_age_max) {
            $age = $userContext['age'] ?? null;
            if ($age) {
                if ($feature->target_age_min && $age < $feature->target_age_min) return false;
                if ($feature->target_age_max && $age > $feature->target_age_max) return false;
            }
        }

        return true;
    }

    /**
     * بررسی percentage rollout (consistent عبر sessions)
     * استفاده از hash برای consistency
     */
    private function checkPercentageRollout(\App\Models\FeatureFlag $feature, int $userId): bool
    {
        if (!isset($feature->percentage_rollout) || $feature->percentage_rollout >= 100) {
            return true;
        }

        // استفاده از seed برای consistency
        $seed = $feature->rollout_seed ?? $feature->name;
        $hash = hexdec(substr(hash('sha256', "{$userId}:{$seed}"), 0, 8)) % 100;
        
        return $hash < (int)$feature->percentage_rollout;
    }

    /**
     * بررسی زمان‌بندی (time schedule)
     */
    private function checkTimeSchedule(\App\Models\FeatureFlag $feature): bool
    {
        $now = date('Y-m-d H:i:s');
        
        if ($feature->enabled_from && $now < $feature->enabled_from) {
            return false;
        }
        
        if ($feature->enabled_until && $now > $feature->enabled_until) {
            return false;
        }
        
        return true;
    }

    /**
     * دریافت اطلاعات کاربر برای targeting
     */
    /**
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>
     */
    private function getUserContext(int $userId, ?array $context = null): array
    {
        if ($context) {
            return array_merge(['user_id' => $userId], $context);
        }

        // دریافت از طریق مدلهای تزریق شده
        $user = $this->toObject($this->userModel->find($userId));
        if (!$user) { 
        return ['user_id' => $userId, 'role' => 'user'];
        }
        
        $plan = null;
        $device = null;
        
        try {
            $kyc = $this->toObject($this->kycModel->findByUserId($userId));
            if ($kyc && isset($kyc->id)) {
                $plan = $kyc->plan ?? null;
                $device = $kyc->device_type ?? null;
            }
        } catch (\Throwable $e) {
            // Silent fail
        }

        return [
            'user_id' => $userId,
            'role' => $user->role ?? 'user',
            'country' => 'IR',
            'plan' => $plan,
            'device' => $device,
            'age' => null,
            'route' => app()->request->uri(),
        ];
    }

    /**
     * پاک کردن cache
     */
    public function clearCache(?string $featureName = null): void
    {
        // 🚀 BUG FIX [M-07]: Clear internal model cache as well to avoid stale data
        $this->featureModel->clearCache();

        // 🚀 BUG FIX [M-08]: Flush the entire feature_flag tag to clear all user/context variations
        $this->cache->tags(['feature_flag'])->flush();

        if (function_exists('config_reload')) {
            config_reload('feature_flags');
        }
    }

    /**
     * دریافت تمام فیچرها
     */
    /** @return list<\App\Models\FeatureFlag> */
    public function getAll(): array
    {
        $features = $this->featureModel->getAll();
        $featureMap = [];

        foreach ($features as $feature) {
            $featureMap[$feature->name] = $feature;
        }

        $configFlags = config('feature_flags', []);
        foreach ((array)$configFlags as $name => $definition) {
            // Only array entries are feature flags; scalar entries (e.g. api_key) are skipped.
            if (!isset($featureMap[$name]) && is_array($definition) && !is_int($name)) {
                $featureMap[$name] = $this->makeFeatureObjectFromConfig($name, $definition);
            }
        }

        return array_values($featureMap);
    }

    /**
     * یافتن یک فیچر با نام
     */
    /** @return ?\App\Models\FeatureFlag */
    public function findByName(string $name): ?\App\Models\FeatureFlag
    {
        $feature = $this->featureModel->findByName($name);
        if ($feature) {
            return $feature;
        }

        $definition = $this->getConfigFlagDefinition($name);
        if ($definition) {
            return $this->makeFeatureObjectFromConfig($name, $definition);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function getConfigFlagDefinition(string $name): ?array
    {
        $definition = config("feature_flags.{$name}");
        if (!is_array($definition)) {
            return null;
        }

        return array_merge(
            [
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
            ],
            $definition
        );
    }

    /** @param array<string, mixed> $definition */
    private function makeFeatureObjectFromConfig(string $name, array $definition): FeatureFlag
    {
        $feature = new FeatureFlag($this->db);
        $feature->name = $name;
        $feature->description = str_value($definition['description'] ?? '');
        $feature->enabled = (bool)($definition['enabled'] ?? false);
        $feature->enabled_percentage = int_value($definition['enabled_percentage'] ?? 100);
        $feature->enabled_for_roles = isset($definition['enabled_for_roles']) ? (json_encode($definition['enabled_for_roles']) ?: null) : null;
        $feature->enabled_for_users = isset($definition['enabled_for_users']) ? (json_encode($definition['enabled_for_users']) ?: null) : null;
        $feature->enabled_for_countries = isset($definition['enabled_for_countries']) ? (json_encode($definition['enabled_for_countries']) ?: null) : null;
        $feature->enabled_for_devices = isset($definition['enabled_for_devices']) ? (json_encode($definition['enabled_for_devices']) ?: null) : null;
        $feature->enabled_for_routes = isset($definition['enabled_for_routes']) ? (json_encode($definition['enabled_for_routes']) ?: null) : null;
        $feature->metadata = isset($definition['metadata']) ? str_value($definition['metadata']) : null;
        $feature->enabled_from = isset($definition['enabled_from']) ? str_value($definition['enabled_from']) : null;
        $feature->enabled_until = isset($definition['enabled_until']) ? str_value($definition['enabled_until']) : null;
        $feature->depends_on = isset($definition['depends_on']) ? (json_encode($definition['depends_on']) ?: null) : null;
        $feature->environments = isset($definition['environments']) ? (json_encode($definition['environments']) ?: null) : null;
        $feature->priority = isset($definition['priority']) ? str_value($definition['priority']) : null;
        $feature->tags = isset($definition['tags']) ? (json_encode($definition['tags']) ?: null) : null;
        $feature->min_age = isset($definition['min_age']) ? int_value($definition['min_age']) : null;
        $feature->max_age = isset($definition['max_age']) ? int_value($definition['max_age']) : null;
        $feature->created_at = str_value($definition['created_at'] ?? '');
        $feature->updated_at = isset($definition['updated_at']) ? str_value($definition['updated_at']) : null;
        return $feature;
    }

    private function createFeatureFromConfig(string $name): ?\App\Models\FeatureFlag
    {
        $definition = $this->getConfigFlagDefinition($name);
        if (!$definition) {
            return null;
        }

        $this->featureModel->create([
            'name' => $name,
            'description' => $definition['description'],
            'enabled' => $definition['enabled'] ? 1 : 0,
            'enabled_percentage' => $definition['enabled_percentage'],
            'enabled_for_roles' => $definition['enabled_for_roles'],
            'enabled_for_users' => $definition['enabled_for_users'],
            'metadata' => $definition['metadata'],
            'enabled_from' => $definition['enabled_from'],
            'enabled_until' => $definition['enabled_until'],
            'depends_on' => $definition['depends_on'],
            'environments' => $definition['environments'],
            'priority' => $definition['priority'],
            'tags' => $definition['tags'],
        ]);

        return $this->featureModel->findByName($name);
    }

    public function enable(string $name): bool
    {
        return $this->update($name, ['enabled' => true]);
    }

    public function disable(string $name): bool
    {
        return $this->update($name, ['enabled' => false]);
    }

    public function toggle(string $name): bool
    {
        // 🚀 BUG FIX [M-06]: Clear cache to get fresh data for event logging
        $this->featureModel->clearCache();
        $feature = $this->featureModel->findByName($name);
        if (!$feature) {
            $feature = $this->createFeatureFromConfig($name);
            if (!$feature) {
                return false;
            }
        }
        
        $oldValues = ['enabled' => (bool)$feature->enabled];
        
        $result = $this->featureModel->toggle($name);
        
        if ($result) {
            $this->clearCache($name);
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'toggled',
                $oldValues,
                ['enabled' => !$oldValues['enabled']],
                function_exists('user_id') ? user_id() : 0
            ));
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    public function update(string $name, array $data): bool
    {
        // 🚀 BUG FIX [M-06]: Fresh data for audit log
        $this->featureModel->clearCache();
        $feature = $this->featureModel->findByName($name);
        if (!$feature) {
            $feature = $this->createFeatureFromConfig($name);
            if (!$feature) {
                throw new \InvalidArgumentException("Feature '{$name}' not found");
            }
        }
        
        // 1. Business Validation & Sanitization
        $sanitizedData = [];
        foreach ((array)$data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'])) {
                $value = is_array($value) ? json_encode($value) : $value;
            }
            
            if ($key === 'enabled_percentage') {
                $value = max(0, min(100, int_value($value)));
            }
            
            if ($key === 'enabled') {
                $value = $value ? 1 : 0;
            }
            
            $sanitizedData[$key] = $value;
        }
        
        if (empty($sanitizedData)) {
            return false;
        }
        
        // 2. Prepare Event tracking
        $oldValues = [];
        foreach (array_keys($sanitizedData) as $key) {
            if (property_exists($feature, $key)) {
                $oldValues[$key] = $feature->$key;
            }
        }
        
        // 3. Delegate to persistence layer
        $result = $this->featureModel->update($name, $sanitizedData);
        
        if ($result) {
            $this->clearCache($name);
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'updated',
                $oldValues,
                $data, // original human-readable data
                function_exists('user_id') ? user_id() : 0
            ));
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): bool
    {
        // 1. Business Validation
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        $name = str_value($data['name']);
        if ($this->featureModel->findByName($name)) {
            throw new \InvalidArgumentException("Feature '{$name}' already exists");
        }
        
        // 2. Fill defaults and sanitize
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
        
        $fullData = array_merge($defaults, $data);
        
        // 3. Serialization
        foreach (['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'] as $field) {
            if (is_array($fullData[$field])) {
                $fullData[$field] = json_encode($fullData[$field]);
            }
        }
        
        $fullData['enabled'] = $fullData['enabled'] ? 1 : 0;
        $fullData['enabled_percentage'] = max(0, min(100, int_value($fullData['enabled_percentage'])));
        
        // 4. Persistence
        $result = $this->featureModel->create($fullData);
        
        if ($result) {
            $this->clearCache();
            
            $this->dispatchEvent(new FeatureFlagChanged(
                str_value($data['name'] ?? 'unknown'),
                'created',
                [],
                $data,
                function_exists('user_id') ? user_id() : 0
            ));
        }
        return $result;
    }

    public function delete(string $name): bool
    {
        $feature = $this->featureModel->findByName($name);
        $oldValues = $feature ? (array)$feature : [];
        
        $result = $this->featureModel->delete($name);
        
        if ($result) {
            $this->clearCache();
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'deleted',
                $oldValues,
                [],
                function_exists('user_id') ? user_id() : 0
            ));
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->featureModel->getStats();
    }

    /** @return list<\stdClass> */
    public function getHistory(string $name, int $limit = 50): array
    {
        return $this->featureModel->getHistory($name, $limit);
    }

    public function getCacheCount(): int
    {
        return $this->featureModel->getCacheCount();
    }

    public function cleanupMetrics(int $days = 30): void
    {
        $this->featureModel->cleanupMetrics($days);
    }

    /** @return list<\stdClass> */
    public function getMetrics(string $name, int $hours = 24): array
    {
        return $this->featureModel->getMetrics($name, $hours);
    }
    
    /**
     * Dispatch logging event for feature changes
     */
    private function dispatchEvent(FeatureFlagChanged $event): void
    {
        try {
            $listener = new LogFeatureFlagChange($this->db, $this->logger, $this->eventDispatcher);
            $listener->handle($event);

            // Reload feature flag config in long-running processes after updates
            if (function_exists('config_reload')) {
                config_reload('feature_flags');
            }
        } catch (\Throwable $e) {
            $this->logger->error('feature_flag.service.dispatch_failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
            ]);
        }
    }
}



