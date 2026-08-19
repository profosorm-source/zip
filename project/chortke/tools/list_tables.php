<?php
require_once __DIR__ . '/../bootstrap/app.php';

$app = \Core\Application::getInstance();
$db = $app->db();

try {
    $rows = $db->fetchAll('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    echo "=== Tables in database ===\n";
    foreach ($rows as $r) {
        $name = is_object($r) ? $r->TABLE_NAME : (is_array($r) ? $r['TABLE_NAME'] : '?');
        echo "- $name\n";
    }
    echo "\n";
    $check = ['system_settings','sentry_issues','sentry_events','sentry_issue_events'];
    echo "=== Critical presence ===\n";
    $existing = array_map(function($r){ return is_object($r)?$r->TABLE_NAME:(is_array($r)?$r['TABLE_NAME']:null); }, $rows);
    foreach ($check as $t) {
        echo (in_array($t, $existing) ? "✓" : "✗") . " $t\n";
    }
} catch (Throwable $e) {
    echo "error: " . $e->getMessage() . "\n";
    exit(1);
}
