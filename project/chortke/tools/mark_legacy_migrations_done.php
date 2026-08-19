<?php
/**
 * One-shot maintenance script: mark all migration files older than the
 * first one tracked in schema_migrations as already executed, without
 * running them. This is needed because the original migration runner
 * apparently bootstrapped some tables before `schema_migrations` itself
 * was created, leaving early migrations recorded only as "applied via
 * effect" but not in the tracking table. Re-running them now would fail
 * (DROP TABLE → FK constraint violations against existing data).
 *
 * Safety: only inserts into schema_migrations; never touches schemas
 * or data. Idempotent.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;

$db = Container::getInstance()->make(\Core\Database::class);

$files = glob(__DIR__ . '/../database/migrations/*.sql');
sort($files);

// Determine which files are already tracked
$tracked = $db->fetchAll("SELECT migration FROM schema_migrations");
$trackedNames = array_map(fn($r) => $r->migration, $tracked);

$batch = (int)($db->fetch("SELECT COALESCE(MAX(batch),0)+1 AS b FROM schema_migrations")->b ?? 1);

echo "── Marking legacy migrations as executed ──\n";
$marked = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $trackedNames, true)) continue;

    // Heuristic: a migration is "legacy" if its filename date is < 2026-06-14
    // (the date this fix was authored). Anything newer should still be run
    // through the normal `php migrate.php` path so its statements actually
    // execute.
    if (!preg_match('/^(\d{4}_\d{2}_\d{2})_/', $name, $m)) continue;
    if ($m[1] >= '2026_06_14') continue; // run via normal migrate.php

    $db->query(
        "INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)",
        [$name, $batch]
    );
    echo "  ✓ {$name}\n";
    $marked++;
}
echo "\nMarked {$marked} legacy migrations as already executed.\n";
echo "Now run `php migrate.php` to apply the remaining new migrations.\n";
