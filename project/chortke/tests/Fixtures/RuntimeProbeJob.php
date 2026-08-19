<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Core\Database;

final class RuntimeProbeJob
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        $key = is_string($data['marker'] ?? null) ? $data['marker'] : '';
        if ($key === '') {
            throw new \InvalidArgumentException('Runtime probe marker is required.');
        }

        $this->db->query(
            "INSERT INTO system_settings (`key`,`value`,`group`,`type`,`description`,is_public,created_at,updated_at)"
            . " VALUES (?, '1', 'phpunit_runtime', 'int', 'queue runtime probe', 0, NOW(), NOW())"
            . " ON DUPLICATE KEY UPDATE `value` = CAST(CAST(`value` AS UNSIGNED) + 1 AS CHAR), updated_at = NOW()",
            [$key]
        );
    }
}
