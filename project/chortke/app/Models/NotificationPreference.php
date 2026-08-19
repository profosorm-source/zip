<?php

namespace App\Models;

use Core\Model;

class NotificationPreference extends Model
{
    protected static string $table = 'notification_preferences_v2';

    private const CHANNELS = ['in_app', 'email', 'push', 'sms'];
    private const TYPES = ['deposit', 'withdrawal', 'task', 'investment', 'lottery', 'referral', 'security', 'kyc', 'system', 'marketing'];

    public function __construct(\Core\Database $db) {
        parent::__construct($db);
    }

    public function getOrCreate(int $userId): object
    {
        $find = function () use ($userId): ?\stdClass {
            $stmt = $this->db->query(
                "SELECT * FROM `" . static::$table . "` WHERE user_id = ? LIMIT 1",
                [$userId]
            );
            return $this->fetchObject($stmt);
        };

        $prefs = $find();
        if ($prefs === null) {
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO `" . static::$table . "` (user_id, created_at, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)",
                [$userId, $now, $now]
            );
            $prefs = $find();
        }

        if ($prefs === null) {
            throw new \RuntimeException('Notification preferences could not be persisted or loaded.');
        }

        foreach ($this->getGranularFieldsWithDefaults() as $field => $default) {
            if (!property_exists($prefs, $field)) {
                $prefs->{$field} = $default;
            }
        }

        return $prefs;
    }

    /** @param array<string, mixed> $data */
    public function updateForUser(int $userId, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        $values = array_values($data);
        $values[] = $userId;

        $stmt = $this->db->query(
            "UPDATE `" . static::$table . "` SET {$sets}, updated_at = NOW() WHERE user_id = ?",
            $values
        );

        return true;
    }

    /**
     * @param list<int> $userIds
     * @return list<\stdClass>
     */
    public function getByUsers(array $userIds): array
    {
        if (empty($userIds)) return [];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        return $this->db->fetchAll(
            "SELECT * FROM `" . static::$table . "` WHERE user_id IN ($placeholders)",
            $userIds
        ) ?: [];
    }

    /** @return list<string> */
    public function getAllowedFields(): array
    {
        return array_merge(
            ['in_app_enabled', 'email_enabled', 'push_enabled', 'sms_enabled', 'dnd_enabled', 'dnd_start', 'dnd_end'],
            array_keys($this->getGranularFieldsWithDefaults())
        );
    }

    /** @return list<string> */
    public function getNotificationTypes(): array
    {
        return self::TYPES;
    }

    /** @return list<string> */
    public function getChannels(): array
    {
        return self::CHANNELS;
    }

    /** @return list<string> */
    public function getEnabledChannelsForType(int $userId, string $type): array
    {
        $pref = $this->getOrCreate($userId);
        $enabled = [];
        foreach (self::CHANNELS as $channel) {
            if ($this->isChannelEnabledForPreference($pref, $channel, $type)) {
                $enabled[] = $channel;
            }
        }
        return $enabled;
    }

    public function isChannelEnabledForType(int $userId, string $channel, string $type): bool
    {
        return $this->isChannelEnabledForPreference($this->getOrCreate($userId), $channel, $type);
    }

    private function isChannelEnabledForPreference(object $pref, string $channel, string $type): bool
    {
        $channel = strtolower((string)$channel);
        $type = $this->normalizeType($type);

        $masterField = $channel . '_enabled';
        if (!(bool)($pref->{$masterField} ?? false)) {
            return false;
        }

        $typeField = $channel . '_' . $type;
        if (property_exists($pref, $typeField)) {
            return (bool)$pref->{$typeField};
        }

        return $this->defaultFor($channel, $type);
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim((string)$type));
        return match ($type) {
            'payment', 'wallet', 'manual_deposit', 'crypto_deposit' => 'deposit',
            'withdraw', 'withdrawals' => 'withdrawal',
            'tasks', 'custom_task', 'social_task', 'task_reward' => 'task',
            'invest', 'investment_profit', 'investment_loss' => 'investment',
            'identity', 'verification' => 'kyc',
            'alert', 'login', 'fraud' => 'security',
            'info', 'notice', 'general' => 'system',
            default => in_array($type, self::TYPES, true) ? $type : 'system',
        };
    }

    /** @return array<string, mixed> */
    private function getGranularFieldsWithDefaults(): array
    {
        $fields = [];
        foreach (self::CHANNELS as $channel) {
            foreach (self::TYPES as $type) {
                $fields[$channel . '_' . $type] = $this->defaultFor($channel, $type) ? 1 : 0;
            }
        }
        return $fields;
    }

    private function defaultFor(string $channel, string $type): bool
    {
        if ($type === 'marketing') {
            return false;
        }

        if ($channel === 'sms') {
            return in_array($type, ['security', 'withdrawal', 'kyc'], true);
        }

        return true;
    }
}
