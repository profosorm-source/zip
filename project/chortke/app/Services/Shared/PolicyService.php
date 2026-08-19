<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use App\Models\Role;
use Core\Database;
use App\Services\AuditTrail;
use App\Policies\RolePolicy;

use App\Contracts\LoggerInterface;
/**
 * PolicyService — سرویس اشتراکی مدیریت سطوح دسترسی (RBAC)
 *
 * این سرویس جایگزین App\Services\PolicyService شده است.
 * مسئول تمامی بررسی‌های احراز هویت و دسترسی‌های نقش‌محور می‌باشد.
 */
class PolicyService
{
    /** @var array<string, bool> */
    private array $permissionCache = [];

    private \App\Contracts\LoggerInterface $logger;
    private User $userModel;
    private Role $roleModel;
    private AuditTrail $auditTrail;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        Role $roleModel,
        AuditTrail $auditTrail
    ) {        $this->logger = $logger;
        $this->userModel = $userModel;
        $this->roleModel = $roleModel;
        $this->auditTrail = $auditTrail;

        
    }

    /** @param object|null $resource */
    public function can(string $action, object $user, ?object $resource = null): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if ($resource) return $this->canOnResource($action, $user, $resource);

        return $this->hasPermission($user, $action);
    }

    /** @param object|null $resource */
    public function authorize(string $action, object $user, ?object $resource = null): void
    {
        $userId = (int)($user->id ?? 0);
        if (!$this->can($action, $user, $resource)) {
            $this->auditTrail->record('authorization_denied', $userId, [
                'action' => $action,
                'user_id' => $userId,
            ]);
            throw new \Core\Exceptions\SecurityException("دسترسی غیرمجاز به عملیات: $action");
        }
    }

    private function canOnResource(string $action, object $user, object $resource): bool
    {
        $userId = (int)($user->id ?? 0);
        if (isset($resource->user_id) && (int)$resource->user_id === $userId) return true;

        return $this->hasPermission($user, $action);
    }

    private function cacheSet(string $key, bool $value): void
    {
        if (count($this->permissionCache) >= 1000) {
            // Clear entire cache to prevent memory creep in long-lived processes
            $this->permissionCache = [];
        }
        $this->permissionCache[$key] = $value;
    }

    private function hasPermission(object $user, string $action): bool
    {
        if ($this->isSuperAdmin($user)) return true;
        $userId = (int)($user->id ?? 0);
        $cacheKey = "user_{$userId}_action_{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        // Architectural Decoupling: Delegating lookups directly into model logic.
        $result = $this->userModel->hasPermission($userId, $action);
        if (!is_bool($result)) throw new \UnexpectedValueException('User permission model must return boolean.');

        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    public function isAdmin(object $user): bool
    {
        return RolePolicy::isFullAdmin((string)($user->role ?? ''));
    }

    public function isAdminArea(object $user): bool
    {
        return RolePolicy::isAdmin((string)($user->role ?? ''));
    }

    public function isSuperAdmin(object $user): bool
    {
        return ($user->role ?? '') === 'super_admin';
    }

    public function isModerator(\App\Models\User $user): bool
    {
        return in_array($user->role ?? '', ['admin', 'super_admin', 'moderator'], true);
    }

    /**
     * بررسی admin بودن با ID — برای استفاده در BaseController
     */
    public function isAdminById(int $userId): bool
    {
        $user = $this->userModel->findById($userId);
        // L-04: فقط ادمین کامل (admin/super_admin). منبع واحد حقیقت: RolePolicy.
        return $user !== null && RolePolicy::isFullAdmin((string)($user->role ?? ''));
    }

    /**
     * L-04: نسخهٔ ID-محورِ isAdminArea — گیتِ ورودِ کنترلرهای مجاز به support.
     */
    public function isAdminAreaById(int $userId): bool
    {
        $user = $this->userModel->findById($userId);
        return $user !== null && RolePolicy::isAdmin((string)($user->role ?? ''));
    }

    /**
     * بررسی super_admin بودن با ID — برای bypass در AdminPermissionGuard و authorizeById
     */
    public function isSuperAdminById(int $userId): bool
    {
        $user = $this->userModel->findById($userId);
        return $user !== null && ($user->role ?? '') === 'super_admin';
    }

    /**
     * بررسی permission با ID — برای استفاده در BaseController
     */
    public function authorizeById(string $action, int $userId): bool
    {
        // M-35: avoid hard-coded id=1 bypass; use the canonical role from DB.
        if ($this->isSuperAdminById($userId)) return true;
        $cacheKey = "uid_{$userId}_{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $result = $this->userModel->hasPermission($userId, $action);
        if (!is_bool($result)) throw new \UnexpectedValueException('User permission model must return boolean.');

        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    /**
     * @return list<string>
     */
    public function getPermissions(\App\Models\User $user): array
    {
        return $this->userModel->getUserPermissions((int)$user->id);
    }

    public function grantRole(\App\Models\User $user, string $roleSlug, ?int $grantedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) throw new \Core\Exceptions\NotFoundException("نقش '$roleSlug' یافت نشد");

            $this->userModel->assignRole((int)$user->id, (int)$role->id, $grantedBy);

            $this->auditTrail->record('role_granted', (int)$user->id, ['role' => $roleSlug]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('grant_role_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function revokeRole(\App\Models\User $user, string $roleSlug, ?int $revokedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) throw new \Core\Exceptions\NotFoundException("نقش '$roleSlug' یافت نشد");

            $this->userModel->removeRole((int)$user->id, (int)$role->id);
            $this->auditTrail->record('role_revoked', (int)$user->id, ['role' => $roleSlug]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('revoke_role_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function clearCache(int $userId): void
    {
        $this->permissionCache = array_filter(
            $this->permissionCache,
            fn($key) => !str_starts_with($key, "user_{$userId}_") && !str_starts_with($key, "uid_{$userId}_"),
            ARRAY_FILTER_USE_KEY
        );
    }
}

