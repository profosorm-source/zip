<?php

/**
 * Seed Initial Data for Fresh Install
 * 
 * This migration seeds:
 * - Default roles (user, admin, super_admin, support)
 * - One normal user + multiple admin types
 * - Core feature flags from config/feature_flags.php
 * - Basic system settings from config (currency, commission rates, kyc levels, etc.)
 * 
 * It is designed to run only on fresh installs (when tables are empty).
 * It reads from config files (source of truth) and writes to DB (runtime source of truth).
 */

require_once __DIR__ . '/../../bootstrap/app.php';

use Core\Application;

$app = Application::getInstance();
$db = $app->db()->getPdo();

echo "=== Chortke Initial Data Seeder ===\n";

// Helper: only seed if table is empty.
// IMPORTANT: this migration is required from inside MigrationService::runMigrations(),
// so top-level variables live in that method's local scope. Using `global $db`
// here resolves to an unrelated/null global and silently makes every conditional
// seed return false. Capture the actual PDO connection instead.
$shouldSeed = static function (string $table) use ($db): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Unsafe seed table name: ' . $table);
    }

    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        return $count === 0;
    } catch (Throwable $e) {
        return false;
    }
};

try {
    $db->beginTransaction();

    // 1. Roles (idempotent; aligned with the current roles schema)
    echo "Ensuring core roles...\n";
    $roles = [
        ['slug' => 'user',        'name' => 'کاربر عادی',  'description' => 'Default user role',          'is_system' => 1, 'is_active' => 1],
        ['slug' => 'support',     'name' => 'پشتیبانی',    'description' => 'Support operator role',      'is_system' => 1, 'is_active' => 1],
        ['slug' => 'admin',       'name' => 'مدیر',        'description' => 'Administrator role',         'is_system' => 1, 'is_active' => 1],
        ['slug' => 'super_admin', 'name' => 'سوپر مدیر',   'description' => 'Super administrator role',   'is_system' => 1, 'is_active' => 1],
    ];
    $stmt = $db->prepare("
        INSERT INTO roles (`name`, `slug`, `description`, `is_system`, `is_active`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `description` = VALUES(`description`),
            `is_system` = VALUES(`is_system`),
            `is_active` = VALUES(`is_active`),
            `updated_at` = NOW()
    ");
    foreach ($roles as $r) {
        $stmt->execute([$r['name'], $r['slug'], $r['description'], $r['is_system'], $r['is_active']]);
    }
    echo "  ✓ core roles ensured\n";

    $roleIds = [];
    $roleRows = $db->query("SELECT id, slug FROM roles WHERE slug IN ('user','support','admin','super_admin')")->fetchAll(PDO::FETCH_OBJ);
    foreach ($roleRows as $row) {
        $roleIds[$row->slug] = (int)$row->id;
    }

    // 2. Default users (only if users table is empty)
    if ($shouldSeed('users')) {
        echo "Seeding initial user accounts...\n";
        
        $envAdminEmail = getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? ($_SESSION['admin']['email'] ?? null));
        $envAdminPass  = getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? ($_SESSION['admin']['pass'] ?? null));
        $appEnv = strtolower(str_value(config('app.env', 'local')));

        // If custom admin credentials supplied by operator/installer, use them securely
        if (!empty($envAdminEmail) && !empty($envAdminPass)) {
            $adminEmail = trim((string)$envAdminEmail);
            $adminPassHash = password_hash((string)$envAdminPass, PASSWORD_ARGON2ID);
            
            $users = [
                ['email' => $adminEmail, 'username' => 'admin', 'full_name' => 'مدیر ارشد سیستم', 'password' => $adminPassHash, 'role' => 'super_admin', 'is_admin' => 1, 'status' => 'active', 'kyc_status' => 'verified', 'email_verified_at' => date('Y-m-d H:i:s')],
            ];
            
            if ($appEnv !== 'production') {
                $defaultPass = password_hash('123456', PASSWORD_DEFAULT);
                $users[] = ['email' => 'user@chortke.ir', 'username' => 'seed_user', 'full_name' => 'کاربر تست', 'password' => $defaultPass, 'role' => 'user', 'is_admin' => 0, 'status' => 'active', 'kyc_status' => 'unverified', 'email_verified_at' => date('Y-m-d H:i:s')];
            }
        } else {
            // Default seed users for local/testing environment
            $defaultPass = password_hash('123456', PASSWORD_DEFAULT);
            $users = [
                ['email' => 'user@chortke.ir',       'username' => 'seed_user',       'full_name' => 'کاربر تست',       'password' => $defaultPass, 'role' => 'user',        'is_admin' => 0, 'status' => 'active', 'kyc_status' => 'unverified', 'email_verified_at' => date('Y-m-d H:i:s')],
                ['email' => 'support@chortke.ir',    'username' => 'seed_support',    'full_name' => 'پشتیبان تست',     'password' => $defaultPass, 'role' => 'support',     'is_admin' => 1, 'status' => 'active', 'kyc_status' => 'verified',   'email_verified_at' => date('Y-m-d H:i:s')],
                ['email' => 'admin@chortke.ir',      'username' => 'seed_admin',      'full_name' => 'مدیر تست',        'password' => $defaultPass, 'role' => 'admin',       'is_admin' => 1, 'status' => 'active', 'kyc_status' => 'verified',   'email_verified_at' => date('Y-m-d H:i:s')],
                ['email' => 'superadmin@chortke.ir', 'username' => 'seed_superadmin', 'full_name' => 'مدیر ارشد تست',   'password' => $defaultPass, 'role' => 'super_admin', 'is_admin' => 1, 'status' => 'active', 'kyc_status' => 'verified',   'email_verified_at' => date('Y-m-d H:i:s')],
            ];
        }

        $stmt = $db->prepare("
            INSERT INTO users (email, username, full_name, password, role, role_id, is_admin, status, kyc_status, email_verified_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $userRoleStmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        
        foreach ($users as $u) {
            $roleId = $roleIds[$u['role']] ?? null;
            $stmt->execute([$u['email'], $u['username'], $u['full_name'], $u['password'], $u['role'], $roleId, $u['is_admin'], $u['status'], $u['kyc_status'], $u['email_verified_at']]);
            $userId = (int)$db->lastInsertId();
            if ($roleId) {
                $userRoleStmt->execute([$userId, $roleId]);
                // Ensure super_admin role_id 3 is also granted if role is super_admin or admin
                if (in_array($u['role'], ['admin', 'super_admin'], true) && isset($roleIds['super_admin'])) {
                    $userRoleStmt->execute([$userId, $roleIds['super_admin']]);
                }
            }
        }
        echo "  ✓ initial user accounts seeded\n";
    }

    // 2.1 Wallets for seeded users (idempotent; required by browser/E2E smoke and first-login UX)
    try {
        $hasWallets = (bool)$db->query("SHOW TABLES LIKE 'wallets'")->fetchColumn();
        if ($hasWallets) {
            $walletUserRows = $db->query("SELECT id FROM users WHERE email IN ('user@chortke.ir','support@chortke.ir','admin@chortke.ir','superadmin@chortke.ir')")->fetchAll(PDO::FETCH_OBJ);
            $walletStmt = $db->prepare("
                INSERT INTO wallets (user_id, balance_irt, balance_usdt, locked_irt, locked_usdt, is_frozen, created_at, updated_at)
                VALUES (?, ?, 0, 0, 0, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            foreach ($walletUserRows as $row) {
                $initialBalance = ((int)$row->id === 1) ? '1000000.00000000' : '0.00000000';
                $walletStmt->execute([(int)$row->id, $initialBalance]);
            }
            echo "  ✓ wallets ensured for seeded users\n";
        }
    } catch (Throwable $e) {
        echo "  ! wallet seed skipped: " . $e->getMessage() . "\n";
    }

    // 2.2 Ticket categories for seeded support flows (idempotent)
    try {
        $hasTicketCategories = (bool)$db->query("SHOW TABLES LIKE 'ticket_categories'")->fetchColumn();
        if ($hasTicketCategories) {
            $ticketCategories = [
                ['name' => 'پشتیبانی فنی', 'slug' => 'technical', 'icon' => 'support_agent'],
                ['name' => 'مالی', 'slug' => 'billing', 'icon' => 'payments'],
                ['name' => 'حساب کاربری', 'slug' => 'account', 'icon' => 'person'],
                ['name' => 'سایر موارد', 'slug' => 'other', 'icon' => 'help'],
            ];
            $catStmt = $db->prepare("
                INSERT INTO ticket_categories (name, slug, icon, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), is_active = 1
            ");
            foreach ($ticketCategories as $cat) {
                $catStmt->execute([$cat['name'], $cat['slug'], $cat['icon']]);
            }
            echo "  ✓ ticket categories ensured\n";
        }
    } catch (Throwable $e) {
        echo "  ! ticket category seed skipped: " . $e->getMessage() . "\n";
    }

    // 3. Feature Flags from config (idempotent via INSERT IGNORE)
    if ($shouldSeed('feature_flags')) {
        echo "Seeding feature flags from config/feature_flags.php...\n";
        $flags = config('feature_flags', []);
        
        $stmt = $db->prepare("
            INSERT IGNORE INTO feature_flags (name, description, enabled, enabled_percentage, priority, metadata, updated_at)
            VALUES (?, ?, ?, 100, 0, ?, NOW())
        ");
        
        $count = 0;
        foreach ($flags as $name => $cfg) {
            if ($name === 'api_key') continue; // not a real flag
            
            $enabled = (int)($cfg['enabled'] ?? 0);
            $desc    = $cfg['description'] ?? $name;
            $meta    = json_encode(array_diff_key($cfg, ['enabled'=>1, 'description'=>1]), JSON_UNESCAPED_UNICODE);
            
            $stmt->execute([$name, $desc, $enabled, $meta]);
            $count++;
        }
        echo "  ✓ $count feature flags seeded\n";
    }

    // 4. Core System Settings from config (currency, commissions, kyc levels, etc.)
    if ($shouldSeed('system_settings')) {
        echo "Seeding core system settings...\n";
        
        $settings = [
            // Currency & Money
            ['key' => 'currency.default',          'value' => 'IRT',          'group' => 'currency', 'type' => 'string', 'description' => 'ارز پیش‌فرض سایت (IRT یا USDT)'],
            ['key' => 'currency.irt_to_usdt_rate', 'value' => '42000',        'group' => 'currency', 'type' => 'float',  'description' => 'نرخ تبدیل تومان به تتر (تقریبی)'],
            
            // Commission & Fees
            ['key' => 'commission.referral',       'value' => '10',           'group' => 'commission','type' => 'float', 'description' => 'درصد کمیسیون ارجاع (Referral)'],
            ['key' => 'commission.investment',     'value' => '5',            'group' => 'commission','type' => 'float', 'description' => 'درصد کمیسیون سرمایه‌گذاری'],
            ['key' => 'commission.task',           'value' => '15',           'group' => 'commission','type' => 'float', 'description' => 'درصد کمیسیون تسک‌ها'],
            
            // KYC Levels
            ['key' => 'kyc.levels',                'value' => json_encode([0 => 'unverified', 1 => 'basic', 2 => 'advanced', 3 => 'full']), 'group' => 'kyc', 'type' => 'json', 'description' => 'سطوح احراز هویت'],
            ['key' => 'kyc.daily_withdrawal_limit.level0', 'value' => '500000',   'group' => 'kyc', 'type' => 'int', 'description' => 'سقف برداشت روزانه سطح 0 (تومان)'],
            ['key' => 'kyc.daily_withdrawal_limit.level1', 'value' => '5000000',  'group' => 'kyc', 'type' => 'int', 'description' => 'سقف برداشت روزانه سطح 1'],
            ['key' => 'kyc.daily_withdrawal_limit.level2', 'value' => '50000000', 'group' => 'kyc', 'type' => 'int', 'description' => 'سقف برداشت روزانه سطح 2'],
            
            // General
            ['key' => 'general.site_name',         'value' => 'چرتکه',         'group' => 'general', 'type' => 'string', 'description' => 'نام سایت'],
            ['key' => 'general.maintenance_mode',  'value' => '0',            'group' => 'general', 'type' => 'bool',   'description' => 'حالت تعمیرات سایت'],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO system_settings (`key`, `value`, `group`, `type`, `description`, is_public, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        
        foreach ($settings as $s) {
            $stmt->execute([$s['key'], $s['value'], $s['group'], $s['type'], $s['description']]);
        }
        echo "  ✓ " . count($settings) . " core system settings seeded\n";
    }

    $db->commit();
    echo "\n✅ Initial data seeding completed successfully.\n";
    echo "   Source of truth: config/ files → written to DB (system_settings + feature_flags + users + roles)\n";

} catch (Throwable $e) {
    $db->rollBack();
    echo "❌ Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
