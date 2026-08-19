<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\NotificationPreference;
use App\Services\Cache\CacheInvalidationService;

class NotificationPreferenceService
{
    /** @var array<int, object> */
    protected array $localCache = [];

    private NotificationPreference $prefModel;
    private ?CacheInvalidationService $cacheInvalidation;
    /** @var \App\Contracts\CacheInterface|\Core\Cache */
    private object $cacheService;

    public function __construct(
        NotificationPreference $prefModel,
        ?CacheInvalidationService $cacheInvalidation = null,
        \App\Contracts\CacheInterface|\Core\Cache|null $cacheService = null
    ) {
        $this->prefModel = $prefModel;
        $this->cacheInvalidation = $cacheInvalidation;
        $this->cacheService = $cacheService ?? cache();
    }

    /**
     * @param list<int> $userIds
     */
    public function prefetchPreferences(array $userIds): void
    {
        if (empty($userIds)) return;

        $prefs = $this->prefModel->getByUsers($userIds);
        foreach ($prefs as $pref) {
            $this->localCache[(int)$pref->user_id] = $pref;
            $this->cacheService->put("user_prefs:{$pref->user_id}", json_encode($pref), 300);
        }
    }

    public function getPreferences(int $userId): object
    {
        if (isset($this->localCache[$userId])) {
            return $this->localCache[$userId];
        }

        try {
            $cached = $this->cacheService->get("user_prefs:{$userId}");
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationPreferenceService.getPreferences']);
            $cached = null;
        }
        if ($cached !== null) {
            if (!is_string($cached)) throw new \UnexpectedValueException('Notification preference cache must contain JSON text.');
            $decoded = json_decode($cached, false, 512, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) throw new \UnexpectedValueException('Notification preference cache must decode to an object.');
            $this->localCache[$userId] = $decoded;
            return $decoded;
        }

        $pref = $this->prefModel->getOrCreate($userId);
        $this->localCache[$userId] = $pref;

        try {
            $this->cacheService->put("user_prefs:{$userId}", json_encode($pref), 300);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationPreferenceService.getPreferences']);
        }

        return $pref;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePreferences(int $userId, array $data): bool
    {
        $allowedFields = $this->prefModel->getAllowedFields();
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $this->normalizeFieldValue($field, $data[$field]);
            }
        }

        unset($this->localCache[$userId]);
        try {
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateUser($userId);
            } else {
                $this->cacheService->forget("user_prefs:{$userId}");
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationPreferenceService.updatePreferences']);
        }

        return $this->prefModel->updateForUser($userId, $updateData);
    }

    public function isNotificationEnabled(int $userId, string $type): bool
    {
        return !empty($this->prefModel->getEnabledChannelsForType($userId, $type));
    }

    public function isInAppEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isChannelEnabledForType($userId, 'in_app', $type);
    }

    public function isPushEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isChannelEnabledForType($userId, 'push', $type);
    }

    public function isSmsEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isChannelEnabledForType($userId, 'sms', $type);
    }

    public function isEmailEnabled(int $userId, string $type): bool
    {
        return $this->prefModel->isChannelEnabledForType($userId, 'email', $type);
    }

    public function isInDndMode(int $userId): bool
    {
        $pref = $this->getPreferences($userId);
        if (empty($pref->dnd_enabled)) {
            return false;
        }

        if (!empty($pref->dnd_start) && !empty($pref->dnd_end)) {
            $now = date('H:i:s');
            if ($pref->dnd_start < $pref->dnd_end) {
                return $now >= $pref->dnd_start && $now <= $pref->dnd_end;
            }
            return $now >= $pref->dnd_start || $now <= $pref->dnd_end;
        }

        return false;
    }

    public function getNextDndEndTime(int $userId): ?string
    {
        $pref = $this->getPreferences($userId);
        $end = (string)($pref->dnd_end ?? '07:00:00');
        $todayEnd = date('Y-m-d') . ' ' . $end;
        if (strtotime($todayEnd) > time()) {
            return $todayEnd;
        }
        return date('Y-m-d H:i:s', strtotime('+1 day', (strtotime($todayEnd) ?: time())));
    }

    private function normalizeFieldValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['dnd_start', 'dnd_end'], true)) {
            $value = trim(str_value($value));
            if (preg_match('/^\d{2}:\d{2}$/', $value)) {
                return $value . ':00';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                return $value;
            }
            return $field === 'dnd_start' ? '23:00:00' : '07:00:00';
        }

        return (int)((bool)$value);
    }
}
