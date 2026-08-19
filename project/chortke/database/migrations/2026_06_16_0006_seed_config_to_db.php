<?php

/**
 * Seed Config Files into Database (One-time on fresh install)
 * 
 * This reads from:
 *   - config/feature_flags.php
 *   - config/config.php (selected sections)
 *   - config/payment.php, config/rate_limits.php, etc.
 * 
 * And writes them into `system_settings` and `feature_flags` tables.
 * 
 * After this migration, the DB becomes the "runtime source of truth"
 * while config/ files remain the "development source of truth".
 */

require_once __DIR__ . '/../../bootstrap/app.php';

use Core\Application;

$app = Application::getInstance();
$db = $app->db()->getPdo();

echo "=== Seeding Config Files into Database ===\n";

try {
    $db->beginTransaction();

    // 1. Feature Flags (already partially done in previous migration, but we re-sync)
    $flagsConfig = config('feature_flags', []);
    $stmtFlag = $db->prepare("
        INSERT INTO feature_flags (name, description, enabled, enabled_percentage, priority, metadata, updated_at)
        VALUES (?, ?, ?, 100, 0, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            description = VALUES(description),
            enabled = VALUES(enabled),
            metadata = VALUES(metadata)
    ");

    $flagCount = 0;
    foreach ($flagsConfig as $name => $cfg) {
        if ($name === 'api_key') continue;
        $enabled = (int)($cfg['enabled'] ?? 0);
        $desc = $cfg['description'] ?? $name;
        $meta = json_encode(array_diff_key($cfg, ['enabled'=>1, 'description'=>1]), JSON_UNESCAPED_UNICODE);
        
        $stmtFlag->execute([$name, $desc, $enabled, $meta]);
        $flagCount++;
    }
    echo "  • Synced $flagCount feature flags from config/feature_flags.php\n";

    // 2. Important settings from main config
    $mainConfig = config('config', []);
    $stmtSetting = $db->prepare("
        INSERT INTO system_settings (`key`, `value`, `group`, `type`, `description`, is_public, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");

    $settingsToSeed = [
        ['key' => 'app.name',           'value' => $mainConfig['app']['name'] ?? 'چرتکه', 'group' => 'app', 'type' => 'string'],
        ['key' => 'app.timezone',       'value' => $mainConfig['app']['timezone'] ?? 'Asia/Tehran', 'group' => 'app', 'type' => 'string'],
        ['key' => 'session.lifetime',   'value' => $mainConfig['session']['lifetime'] ?? 7200, 'group' => 'session', 'type' => 'int'],
        ['key' => 'currency.default',   'value' => $mainConfig['currency']['default'] ?? 'IRT', 'group' => 'currency', 'type' => 'string'],
    ];

    foreach ($settingsToSeed as $s) {
        $stmtSetting->execute([$s['key'], (string)$s['value'], $s['group'], $s['type'], $s['key']]);
    }
    echo "  • Seeded " . count($settingsToSeed) . " core settings from config\n";

    // 3. Payment / Commission defaults (if payment.php exists)
    $paymentConfig = config('payment', []);
    if (!empty($paymentConfig)) {
        $paymentSeeds = [
            ['key' => 'payment.min_deposit', 'value' => $paymentConfig['min_deposit'] ?? 10000, 'group' => 'payment', 'type' => 'int'],
            ['key' => 'payment.max_withdrawal_daily', 'value' => $paymentConfig['max_withdrawal_daily'] ?? 50000000, 'group' => 'payment', 'type' => 'int'],
        ];
        foreach ($paymentSeeds as $s) {
            $stmtSetting->execute([$s['key'], (string)$s['value'], $s['group'], $s['type'], $s['key']]);
        }
        echo "  • Seeded payment-related settings\n";
    }

    $db->commit();
    echo "✅ Config files successfully pushed into database tables.\n";
    echo "   From now on, runtime reads from DB first, then falls back to config/ files.\n";

} catch (Throwable $e) {
    $db->rollBack();
    echo "❌ Failed: " . $e->getMessage() . "\n";
    exit(1);
}
