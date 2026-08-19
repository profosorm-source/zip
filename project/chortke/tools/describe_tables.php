<?php
require_once __DIR__ . '/../bootstrap/app.php';

$app = \Core\Application::getInstance();
$db = $app->db();

$tables = ['system_settings', 'sentry_issues', 'sentry_events', 'sentry_issue_events'];
foreach ($tables as $t) {
    try {
        $row = $db->fetch("SHOW CREATE TABLE `$t`");
        if ($row) {
            $key = array_keys((array)$row)[1] ?? 'Create Table';
            $create = is_object($row) ? ($row->{$key} ?? json_encode($row)) : (is_array($row) ? array_values($row)[1] : json_encode($row));
            echo "--- $t ---\n";
            echo $create . "\n\n";
        } else {
            echo "no create info for $t\n";
        }
    } catch (Throwable $e) {
        echo "error describing $t: " . $e->getMessage() . "\n";
    }
}
