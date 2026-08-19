<?php
declare(strict_types=1);

/**
 * Integration test: QueryBuilder's *Raw() methods MUST go through the parser,
 * and the SQL stored in $this->selectRaw / $this->where MUST be the
 * canonical emit() output, NOT the raw user input.
 *
 * This is the structural guarantee that closes the SQL-injection door.
 */

require_once __DIR__ . '/../core/Sql/SqlExpressionException.php';
require_once __DIR__ . '/../core/Sql/SafeExpression.php';
require_once __DIR__ . '/../core/QueryBuilder.php';

use Core\QueryBuilder;
use Core\Sql\SqlExpressionException;

// QueryBuilder needs a PDO; we never run a query so a SQLite memory PDO is fine.
$pdo = new PDO('sqlite::memory:');
$qb = new QueryBuilder($pdo);
$qb->table('users');

$rc = new ReflectionClass($qb);
$prop = $rc->getProperty('selectRaw'); $prop->setAccessible(true);
$wprop = $rc->getProperty('where');    $wprop->setAccessible(true);

$ok = true;

// 1. selectRaw stores the CANONICAL form, not raw input
$qb->selectRaw("sum(case when status='deleted' then 1 else 0 end)");
$selectRawStored = $prop->getValue($qb);
$stored = is_array($selectRawStored) && isset($selectRawStored[0]) && is_string($selectRawStored[0])
    ? $selectRawStored[0]
    : null;
$expected = "SUM(CASE WHEN `status` = 'deleted' THEN 1 ELSE 0 END)";
if ($stored !== $expected) {
    echo "✗ canonicalisation broken:\n   got: " . ($stored ?? 'null') . "\n   exp: $expected\n";
    $ok = false;
} else {
    echo "✓ selectRaw stores canonical SQL (not user input)\n";
}

// 2. SQL-injection attempt MUST throw and NEVER reach $selectRaw
$qb2 = new QueryBuilder($pdo); $qb2->table('users');
try {
    $qb2->selectRaw("1; DROP TABLE users");
    echo "✗ injection passed through!\n"; $ok = false;
} catch (SqlExpressionException $e) {
    echo "✓ injection rejected: " . substr($e->getMessage(), 0, 70) . "\n";
}

// 3. Unsafe escape-hatch works but blocks ';'
$qb3 = new QueryBuilder($pdo); $qb3->table('users');
$qb3->selectRawUnsafe("VENDOR_SPECIFIC_FUNC(col)");  // accepted (caller's responsibility)
echo "✓ selectRawUnsafe accepts vendor syntax\n";
try {
    $qb3->selectRawUnsafe("col; DROP TABLE users");
    echo "✗ unsafe accepted ';'!\n"; $ok = false;
} catch (\InvalidArgumentException $e) {
    echo "✓ selectRawUnsafe still blocks ';'\n";
}

// 4. whereRaw + bindings round-trip
$qb4 = new QueryBuilder($pdo); $qb4->table('users');
$qb4->whereRaw("end_date IS NULL OR end_date > ?", ['2026-01-01']);
$whereStored = $wprop->getValue($qb4);
$w = is_array($whereStored) && isset($whereStored[0]) && is_array($whereStored[0])
    ? $whereStored[0]
    : [];
$whereSql = isset($w['sql']) && is_string($w['sql']) ? $w['sql'] : '';
$whereBindings = isset($w['bindings']) && is_array($w['bindings']) ? $w['bindings'] : [];
if ($whereSql === "`end_date` IS NULL OR `end_date` > ?" && $whereBindings === ['2026-01-01']) {
    echo "✓ whereRaw canonicalised + bindings preserved\n";
} else {
    echo "✗ whereRaw mismatch: " . json_encode($w) . "\n"; $ok = false;
}

// 5. groupByRaw with HOUR(...)
$qb5 = new QueryBuilder($pdo); $qb5->table('events');
$qb5->groupByRaw("HOUR(created_at)");
$gprop = $rc->getProperty('groupByRaw'); $gprop->setAccessible(true);
$groupStored = $gprop->getValue($qb5);
$g = is_array($groupStored) && isset($groupStored[0]) && is_string($groupStored[0])
    ? $groupStored[0]
    : '';
if ($g === "HOUR(`created_at`)") {
    echo "✓ groupByRaw canonicalises HOUR(col)\n";
} else {
    echo "✗ groupByRaw mismatch: $g\n"; $ok = false;
}

// 6. cursorPaginate mobile optimization test
$pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
for ($i = 1; $i <= 5; $i++) {
    $pdo->exec("INSERT INTO items (id, name) VALUES ($i, 'Item $i')");
}
$qb6 = new QueryBuilder($pdo); $qb6->table('items');
$res = $qb6->cursorPaginate('id', 2, null, 'desc');
if (count($res['items']) === 2 && $res['has_more'] === true && $res['next_cursor'] === '4') {
    echo "✓ cursorPaginate successfully queries limit+1 and extracts next_cursor without OFFSET\n";
} else {
    echo "✗ cursorPaginate mismatch: " . json_encode($res) . "\n"; $ok = false;
}

exit($ok ? 0 : 1);
