<?php

namespace App\Models;

use Core\Model;

class PaymentGateway extends Model
{
    protected static string $table = 'payment_gateways';

    /**
     * دریافت درگاه فعال بر اساس نام
     */
    public function getActiveGateway(string $name): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `" . static::$table . "`
            WHERE name = :name AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['name' => $name]);

        $result = $this->fetchObject($stmt);
        if ($result === null) {
            return null;
        }

        $config = get_object_vars($result)['config'] ?? null;
        if ($config !== null && $config !== '') {
            if (!is_string($config)) {
                throw new \UnexpectedValueException('Payment gateway config must be a JSON string.');
            }
            $configData = json_decode($config, false, 512, JSON_THROW_ON_ERROR);
            if (!$configData instanceof \stdClass) {
                throw new \UnexpectedValueException('Payment gateway config must decode to a JSON object.');
            }
            foreach (get_object_vars($configData) as $key => $value) {
                $result->{$key} = $value;
            }
        }

        return $result;
    }

    /**
     * دریافت تمام درگاه‌های فعال
     */
    /** @return list<\stdClass> */
    public function getActiveGateways(): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `" . static::$table . "`
            WHERE is_active = 1
            ORDER BY name ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * بروزرسانی تنظیمات درگاه
     */
    /** @param array<string, mixed> $config */
    public function updateConfig(int $id, array $config): bool
    {
        $updates = [];
        $params = ['id' => $id];

        foreach ((array)$config as $key => $value) {
            if (\in_array($key, ['merchant_id', 'api_key', 'callback_url', 'is_active', 'is_test_mode'], true)) {
                $updates[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        if (isset($config['config']) && \is_array($config['config'])) {
            $updates[] = "config = :config";
            $params['config'] = \json_encode($config['config'], JSON_UNESCAPED_UNICODE);
        }

        if (empty($updates)) {
            return false;
        }

        $sql = "UPDATE `" . static::$table . "` SET " . \implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * فعال/غیرفعال کردن درگاه
     */
    public function toggleActive(int $id, bool $active): bool
    {
        $stmt = $this->db->prepare("
            UPDATE `" . static::$table . "`
            SET is_active = :active, updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => $id,
            'active' => $active ? 1 : 0
        ]);
    }
}