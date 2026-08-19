<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeatureFlag;

/**
 * Feature Flag Policy
 * 
 * کنترل دسترسی برای مدیریت Feature Flags
 * فقط admin‌ها می‌توانند feature flags را مدیریت کنند
 * super admin می‌تواند همه کاری را انجام دهد
 */
class FeatureFlagPolicy
{
    /**
     * آیا کاربر می‌تواند feature flags را ببیند؟
     */
    public function view(?object $user): bool
    {
        return $user && in_array(str_value($user->role ?? ''), ['admin', 'super_admin']);
    }

    /**
     * آیا کاربر می‌تواند feature flags را ایجاد کند؟
     */
    public function create(?object $user): bool
    {
        return $user && (str_value($user->role ?? '')) === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند یک feature flag را ویرایش کند؟
     * L-06: Type hint $featureFlag as FeatureFlag object instead of generic object
     */
    public function update(?object $user, FeatureFlag $featureFlag): bool
    {
        if (!$user || !in_array(str_value($user->role ?? ''), ['admin', 'super_admin'])) {
            return false;
        }

        // اگر admin است (نه super_admin)، فقط می‌تواند اگر owner باشد
        if (str_value($user->role ?? '') === 'admin') {
            return (int)$featureFlag->owner_user_id === int_value($user->id ?? 0);
        }

        return true; // super_admin می‌تواند همه را ویرایش کند
    }

    /**
     * آیا کاربر می‌تواند یک feature flag را حذف کند؟
     */
    public function delete(?object $user, FeatureFlag $featureFlag): bool
    {
        if (!$user || str_value($user->role ?? '') !== 'super_admin') {
            return false;
        }

        return true; // فقط super_admin می‌تواند حذف کند
    }

    /**
     * آیا کاربر می‌تواند feature flag را تأیید کند؟
     */
    public function approve(?object $user, FeatureFlag $featureFlag): bool
    {
        return $user && str_value($user->role ?? '') === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند targeting تغییر دهد؟
     */
    public function updateTargeting(?object $user, FeatureFlag $featureFlag): bool
    {
        // فقط super_admin و owner می‌توانند
        if (!$user || !in_array(str_value($user->role ?? ''), ['admin', 'super_admin'])) {
            return false;
        }

        if (str_value($user->role ?? '') === 'admin') {
            return (int)$featureFlag->owner_user_id === int_value($user->id ?? 0);
        }

        return true;
    }

    /**
     * آیا کاربر می‌تواند config values تغییر دهد؟
     */
    public function updateConfig(?object $user, FeatureFlag $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند percentage rollout تغییر دهد؟
     */
    public function updateRollout(?object $user, FeatureFlag $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند feature flag را enable/disable کند؟
     */
    public function toggle(?object $user, FeatureFlag $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند تاریخچه تغییرات را ببیند؟
     */
    public function viewHistory(?object $user): bool
    {
        return $user && in_array(str_value($user->role ?? ''), ['admin', 'super_admin']);
    }

    /**
     * آیا کاربر می‌تواند A/B tests را مدیریت کند؟
     */
    public function manageABTests(?object $user, FeatureFlag $featureFlag): bool
    {
        return $user && str_value($user->role ?? '') === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند Policies را مدیریت کند؟
     */
    public function managePolicies(?object $user, FeatureFlag $featureFlag): bool
    {
        return $user && str_value($user->role ?? '') === 'super_admin';
    }
}
