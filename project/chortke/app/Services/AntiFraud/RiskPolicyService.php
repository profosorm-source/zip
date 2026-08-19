<?php

namespace App\Services\AntiFraud;

use Core\Database;

use App\Contracts\LoggerInterface;
class RiskPolicyService
{
    /** @var array<string, mixed> */
    protected array $localCache = [];
    private Database $db;


    public function __construct(Database $db) {
        $this->db = $db;
    }

    private function cacheKey(string $domain, string $key): string
    {
        return $domain . '::' . $key;
    }

    /**
     * مقدار خام policy را برمی‌گرداند (یا default)
     */
    public function get(string $domain, string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($domain, $key);

        if (array_key_exists($cacheKey, $this->localCache)) {
            return $this->localCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT value, value_type
            FROM risk_policies
            WHERE domain = ? AND key_name = ?
            LIMIT 1
        ");
        $stmt->execute([$domain, $key]);
        $rowValue = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row = is_array($rowValue) ? $rowValue : null;

        if (!$row) {
            $this->localCache[$cacheKey] = $default;
            return $default;
        }

        $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : 'string';
        $value = $this->castValue($row['value'] ?? null, $type);
        $this->localCache[$cacheKey] = $value;

        return $value;
    }

    public function getInt(string $domain, string $key, int $default = 0): int
    {
        return int_value($this->get($domain, $key, $default));
    }

    public function getFloat(string $domain, string $key, float $default = 0.0): float
    {
        return float_value($this->get($domain, $key, $default));
    }

    public function getBool(string $domain, string $key, bool $default = false): bool
    {
        $value = $this->get($domain, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(str_value($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function getArray(string $domain, string $key, array $default = []): array
    {
        $value = $this->get($domain, $key, $default);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = (array)(json_decode($value, true) ?? []);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return $default;
    }

    /**
     * ثبت/آپدیت policy (برای پنل مدیریت)
     */
    public function set(
        string $domain,
        string $key,
        mixed $value,
        string $valueType = 'string',
        ?int $adminId = null,
        ?string $description = null
    ): bool {
        $valueType = $this->normalizeValueType($valueType);
        $storedValue = $this->stringifyValue($value, $valueType);

        $stmt = $this->db->prepare("
            INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                value_type = VALUES(value_type),
                description = VALUES(description),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        $ok = $stmt->execute([
            $domain,
            $key,
            $storedValue,
            $valueType,
            $description,
            $adminId,
        ]);

        unset($this->localCache[$this->cacheKey($domain, $key)]);

        return $ok;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPoliciesWithDefaults(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, domain, key_name, value, value_type, description, updated_by, updated_at
            FROM risk_policies
            ORDER BY domain ASC, key_name ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['domain'] . '::' . $row['key_name']] = $row;
        }

        $display = [];
        foreach ($this->defaultPolicies() as $item) {
            $key = $item['domain'] . '::' . $item['key_name'];
            if (isset($indexed[$key])) {
                $display[] = $indexed[$key];
                unset($indexed[$key]);
            } else {
                $display[] = $item + [
                    'id' => null,
                    'updated_by' => null,
                    'updated_at' => null,
                ];
            }
        }

        foreach ($indexed as $remaining) {
            $display[] = $remaining;
        }

        return $display;
    }


    /**
     * Built-in editable defaults shown in the admin policy UI.
     * These mirror the keys consumed by anti-fraud services so missing DB rows
     * do not make the management page incomplete.
     *
     * @return array<int,array<string,mixed>>
     */
    private function defaultPolicies(): array
    {
        $json = static function (array $v): string {
            $encoded = json_encode($v, JSON_UNESCAPED_UNICODE);
            return is_string($encoded) && $encoded !== '' ? $encoded : '[]';
        };
        return [
            ['domain' => 'fraud', 'key_name' => 'block_threshold', 'value' => '80', 'value_type' => 'int', 'description' => 'Risk score threshold for blocking'],
            ['domain' => 'fraud', 'key_name' => 'challenge_threshold', 'value' => '60', 'value_type' => 'int', 'description' => 'Risk score threshold for challenge'],
            ['domain' => 'fraud', 'key_name' => 'limit_threshold', 'value' => '40', 'value_type' => 'int', 'description' => 'Risk score threshold for limiting'],
            ['domain' => 'kyc', 'key_name' => 'rejected_veto_financial', 'value' => '1', 'value_type' => 'bool', 'description' => 'Block financial actions for rejected KYC'],
            ['domain' => 'fingerprint', 'key_name' => 'shared_threshold', 'value' => '5', 'value_type' => 'int', 'description' => 'Shared fingerprint threshold'],
            ['domain' => 'fingerprint', 'key_name' => 'change_suspicious_hours', 'value' => '24', 'value_type' => 'int', 'description' => 'Device fingerprint change window'],
            ['domain' => 'fraud', 'key_name' => 'risk_thresholds', 'value' => $json(['flag'=>50,'kyc'=>70,'review'=>85,'suspend'=>95]), 'value_type' => 'json', 'description' => 'Fraud score thresholds'],
            ['domain' => 'fraud', 'key_name' => 'score_weights', 'value' => $json(['account_age'=>0.2,'reputation'=>0.3,'velocity'=>0.3,'geographic'=>0.2]), 'value_type' => 'json', 'description' => 'Fraud score weights'],
            ['domain' => 'fraud', 'key_name' => 'velocity_settings', 'value' => $json(['daily_high'=>10,'daily_medium'=>5,'weekly_high'=>50,'weekly_medium'=>20,'spike_ratio'=>2.0,'spike_min'=>10]), 'value_type' => 'json', 'description' => 'Velocity fraud settings'],
            ['domain' => 'fraud', 'key_name' => 'geo.max_travel_speed_kmh', 'value' => '900', 'value_type' => 'int', 'description' => 'Impossible travel speed threshold'],
            ['domain' => 'fraud', 'key_name' => 'geo.timezone_discrepancy_limit', 'value' => '2.0', 'value_type' => 'float', 'description' => 'Allowed timezone drift'],
            ['domain' => 'fraud', 'key_name' => 'ip.shared_ip_user_threshold', 'value' => '5', 'value_type' => 'int', 'description' => 'Shared IP user threshold'],
            ['domain' => 'fraud', 'key_name' => 'session.concurrent_threshold', 'value' => '3', 'value_type' => 'int', 'description' => 'Concurrent session threshold'],
        ];
    }


    private function normalizeValueType(string $valueType): string
    {
        $valueType = strtolower(trim((string)$valueType));
        return in_array($valueType, ['int', 'float', 'bool', 'string', 'json'], true) ? $valueType : 'string';
    }

    public function clearCache(): void
    {
        $this->localCache = [];
    }

    private function castValue(mixed $value, string $type): mixed
    {
        switch (strtolower((string)$type)) {
            case 'int':
            case 'integer':
                return int_value($value);

            case 'float':
            case 'double':
                return float_value($value);

            case 'bool':
            case 'boolean':
                $normalized = strtolower(str_value($value));
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);

            case 'json':
                $decoded = json_decode(str_value($value), true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            default:
                return $value;
        }
    }

    private function stringifyValue(mixed $value, string $type): string
    {
        switch (strtolower((string)$type)) {
            case 'json':
                if (is_string($value)) {
                    $decoded = (array)(json_decode($value, true) ?? []);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    }
                }

                return (string)json_encode($value, JSON_UNESCAPED_UNICODE);

            case 'bool':
            case 'boolean':
                return $value ? '1' : '0';

            default:
                return str_value($value);
        }
    }
}

