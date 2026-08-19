<?php

declare(strict_types=1);

/**
 * Repair databases on which the returned migration object in
 * 2026_08_11_0001 was recorded without its up() method being invoked.
 * All statements in the original migration are idempotent.
 */
return new class {
    public function up(\Core\Database $db): void
    {
        $migration = require __DIR__ . '/2026_08_11_0001_create_missing_schema_tables_and_views.php';
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            throw new \RuntimeException('Returned schema migration contract is invalid.');
        }
        $migration->up($db);
    }
};
