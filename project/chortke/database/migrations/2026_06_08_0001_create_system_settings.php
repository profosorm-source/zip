<?php
// Repair migration: ensure `system_settings` table exists
// Generated: 2026-06-08

require_once __DIR__ . '/../../bootstrap/app.php';

$app = \Core\Application::getInstance();
$db = $app->db()->getPdo();

$sql = <<<'SQL'
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `key` varchar(191) NOT NULL,
    `value` longtext DEFAULT NULL,
    `group` varchar(100) DEFAULT 'general',
    `type` varchar(50) DEFAULT 'string' COMMENT 'string, int, bool, json, float',
    `description` text DEFAULT NULL,
    `is_public` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `system_settings_key_unique` (`key`),
    KEY `system_settings_group_index` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
SQL;

try {
    // PDO may not support multiple statements in one exec depending on driver,
    // so split and execute each non-empty statement separately.
    $stmts = array_filter(array_map('trim', explode(";", $sql)));
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        $db->exec($stmt . ';');
    }
    echo "migration: system_settings applied successfully\n";
} catch (Throwable $e) {
    echo "migration error: " . $e->getMessage() . "\n";
    exit(1);
}
