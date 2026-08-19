<?php
declare(strict_types=1);

/**
 * Comprehensive test for the new Allowlist Parser (Core\Sql\SafeExpression).
 *
 * This replaces the old heuristic test. Coverage:
 *
 *   A. False-positive regression (previously rejected, must now pass)
 *   B. Real-world expressions from the codebase (must pass)
 *   C. Classic SQL-injection payloads (must fail)
 *   D. Function-allowlist bypasses (must fail)
 *   E. Lexer & grammar edge cases (must fail)
 *   F. Output canonicalisation (must produce expected emitted SQL)
 */

require_once __DIR__ . '/../core/Sql/SqlExpressionException.php';
require_once __DIR__ . '/../core/Sql/SafeExpression.php';

use Core\Sql\SafeExpression;
use Core\Sql\SqlExpressionException;

$totals = ['pass' => 0, 'fail' => 0, 'unexpected' => []];

/**
 * @param array{pass: int, fail: int, unexpected: list<string>} $totals
 * @param list<string> $allowedCols
 */
function check(array &$totals, string $kind, string $sql, ?string $expectedEmit = null, array $allowedCols = []): void {
    try {
        $expr = SafeExpression::parse($sql, $allowedCols);
        $emit = $expr->emit();
        if ($kind !== 'accept') {
            $totals['unexpected'][] = "EXPECTED reject, but ACCEPTED: {$sql}  →  {$emit}";
            echo "  ✗ ACCEPTED (should reject): " . substr($sql, 0, 70) . "\n";
            return;
        }
        if ($expectedEmit !== null && $emit !== $expectedEmit) {
            $totals['unexpected'][] = "EMIT mismatch for: {$sql}\n      got:      {$emit}\n      expected: {$expectedEmit}";
            echo "  ✗ EMIT mismatch: " . substr($sql, 0, 60) . "\n      → {$emit}\n      ✘ expected: {$expectedEmit}\n";
            return;
        }
        $totals['pass']++;
        echo "  ✓ accept: " . substr($sql, 0, 70) . "\n";
    } catch (SqlExpressionException $e) {
        if ($kind === 'accept') {
            $totals['unexpected'][] = "EXPECTED accept, but REJECTED: {$sql}\n      reason: " . $e->getMessage();
            echo "  ✗ REJECTED (should accept): " . substr($sql, 0, 60) . "\n      → {$e->getMessage()}\n";
            return;
        }
        $totals['fail']++;
        echo "  ✓ reject: " . substr($sql, 0, 70) . "  (" . substr($e->getMessage(), 0, 60) . ")\n";
    }
}

echo "═══ A. False-positive regression ═══════════════════════════════════\n";
check($totals, 'accept', "SUM(CASE WHEN status = 'deleted' THEN 1 ELSE 0 END)");
check($totals, 'accept', "MAX(updated_at)");
check($totals, 'accept', "COUNT(CASE WHEN action = 'delete' THEN 1 END)");
check($totals, 'accept', "DATE_FORMAT(created_at, '%Y-%m')");
check($totals, 'accept', "COALESCE(deleted_at, '1970-01-01')");
check($totals, 'accept', "IFNULL(updated_at, NOW())");
check($totals, 'accept', "CONCAT(first_name, ' ', last_name)");

echo "\n═══ B. Real-world expressions from the codebase ════════════════════\n";
check($totals, 'accept', "HOUR(created_at)");
check($totals, 'accept', "COUNT(DISTINCT user_id)");
check($totals, 'accept', "ROUND(COUNT(*) / 10, 2)");
check($totals, 'accept', "AVG(risk_score)");
check($totals, 'accept', "COALESCE(trust_score, 50)");
check($totals, 'accept', "usage_count >= usage_limit");
check($totals, 'accept', "end_date IS NULL OR end_date > ?");
check($totals, 'accept', "deadline IS NULL OR deadline > ?");
check($totals, 'accept', "total_count - completed_count - pending_count");
check($totals, 'accept', "created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)", null); // INTERVAL → may need extension
check($totals, 'accept', "JSON_EXTRACT(payload, '\$.k')");
check($totals, 'accept', "id IN (?, ?, ?)");
check($totals, 'accept', "name LIKE ?");
check($totals, 'accept', "score BETWEEN ? AND ?");
check($totals, 'accept', "NOT (deleted_at IS NULL)");
check($totals, 'accept', "1");

echo "\n═══ C. Classic SQL-injection payloads (must reject) ════════════════\n";
check($totals, 'reject', "1; DROP TABLE users");
check($totals, 'reject', "1) UNION SELECT password FROM users--");
check($totals, 'reject', "id /* comment */ = 1");
check($totals, 'reject', "id # inline comment\n=1");
check($totals, 'reject', "id = 1 -- trailing");
check($totals, 'reject', "1=1; UPDATE users SET admin=1");
check($totals, 'reject', "name = 'a' AND (DELETE FROM users)");
check($totals, 'reject', "id = 1 UNION ALL SELECT 1,2,3");
check($totals, 'reject', "name = 'a' OR 'a'='a'; --");           // stacked + comment
check($totals, 'reject', "id IN (SELECT id FROM admins)");      // subquery

echo "\n═══ D. Function-allowlist bypasses (must reject) ═══════════════════\n";
check($totals, 'reject', "SLEEP(5)");
check($totals, 'reject', "BENCHMARK(1000000, MD5('x'))");
check($totals, 'reject', "LOAD_FILE('/etc/passwd')");
check($totals, 'reject', "EXTRACTVALUE(1, CONCAT(0x7e, USER()))");
check($totals, 'reject', "UPDATEXML(1,CONCAT(0x7e,(SELECT user())),1)");
check($totals, 'reject', "GET_LOCK('x', 10)");
check($totals, 'reject', "USER()");
check($totals, 'reject', "DATABASE()");
check($totals, 'reject', "VERSION()");
check($totals, 'reject', "id REGEXP '^.*\$'");                   // RLIKE family not allowed
check($totals, 'reject', "pg_sleep(5)");

echo "\n═══ E. Lexer & grammar edge cases (must reject) ════════════════════\n";
check($totals, 'reject', "");
check($totals, 'reject', "id = 'unterminated");
check($totals, 'reject', "(((1=1");
check($totals, 'reject', "1 + + 2");                             // double operator
check($totals, 'reject', "name = \"a\"");                        // double-quoted string not allowed (avoids ANSI/MySQL ambiguity)
check($totals, 'reject', "id = 0x41");                           // hex literal
check($totals, 'reject', "id = 1e10");                           // scientific notation
check($totals, 'reject', "`id` = 1");                            // backtick identifiers rejected at lexer
check($totals, 'reject', "@@version");
check($totals, 'reject', "id = 1 \\G");
check($totals, 'reject', "name = 'O\\'Brien'");                  // backslash escape forbidden
check($totals, 'reject', "INTO OUTFILE '/tmp/x'");
check($totals, 'reject', str_repeat('a', 5000));

echo "\n═══ F. Output canonicalisation (must emit canonical SQL) ═══════════\n";
check($totals, 'accept', "SUM(CASE WHEN status='deleted' THEN 1 ELSE 0 END)",
                         "SUM(CASE WHEN `status` = 'deleted' THEN 1 ELSE 0 END)");
check($totals, 'accept', "max(updated_at)",
                         "MAX(`updated_at`)");
check($totals, 'accept', "count(distinct user_id)",
                         "COUNT(DISTINCT `user_id`)");
check($totals, 'accept', "count(*)",
                         "COUNT(*)");
check($totals, 'accept', "u.id",
                         "`u`.`id`");
check($totals, 'accept', "name LIKE ?",
                         "`name` LIKE ?");
check($totals, 'accept', "id IN (?, ?, ?)",
                         "`id` IN (?, ?, ?)");
check($totals, 'accept', "score BETWEEN ? AND ?",
                         "`score` BETWEEN ? AND ?");
check($totals, 'accept', "name = 'O''Brien'",
                         "`name` = 'O''Brien'");                 // escape preserved
check($totals, 'accept', "NOT (deleted_at IS NULL)",
                         "NOT (`deleted_at` IS NULL)");

echo "\n═══ G. Column allowlist enforcement ════════════════════════════════\n";
check($totals, 'accept', "id = ?", null, ['id']);
check($totals, 'reject', "secret_token = ?", null, ['id', 'name']); // not in allowlist
check($totals, 'accept', "u.id = ?", null, ['u.id']);

echo "\n══════════════════════════════════════════════════════════════════\n";
echo "Passed (accept OK): {$totals['pass']}\n";
echo "Passed (reject OK): {$totals['fail']}\n";
echo "Unexpected:         " . count($totals['unexpected']) . "\n";
if ($totals['unexpected']) {
    echo "\nFailures:\n";
    foreach ($totals['unexpected'] as $u) echo "  • $u\n";
    exit(1);
}
exit(0);
