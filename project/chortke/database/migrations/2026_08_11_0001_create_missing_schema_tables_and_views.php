<?php

/**
 * Migration: Create Missing Schema Tables and Views (Issue #8 Resolution)
 */

return new class {
    public function up(\Core\Database $db): void
    {
        // 1. admin_alerts
        $db->exec("CREATE TABLE IF NOT EXISTS admin_alerts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            title VARCHAR(255) NULL,
            message TEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_read (is_read),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. analytics_daily_summary
        $db->exec("CREATE TABLE IF NOT EXISTS analytics_daily_summary (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            metric_key VARCHAR(100) NOT NULL,
            metric_value DECIMAL(20,8) NOT NULL DEFAULT 0,
            context_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_date_metric (date, metric_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. audit_logs
        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. chance_logs
        $db->exec("CREATE TABLE IF NOT EXISTS chance_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            amount INT NOT NULL DEFAULT 1,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. device_analyses
        $db->exec("CREATE TABLE IF NOT EXISTS device_analyses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device_fingerprint VARCHAR(128) NOT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_device (user_id, device_fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. device_intelligence
        $db->exec("CREATE TABLE IF NOT EXISTS device_intelligence (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device_id VARCHAR(128) NOT NULL,
            platform VARCHAR(50) NULL,
            risk_score INT NOT NULL DEFAULT 0,
            metadata TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_device (user_id, device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. impossible_travel_logs
        $db->exec("CREATE TABLE IF NOT EXISTS impossible_travel_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ip_from VARCHAR(45) NULL,
            ip_to VARCHAR(45) NULL,
            distance_km DECIMAL(10,2) NULL,
            time_diff_sec INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. ip_cache
        $db->exec("CREATE TABLE IF NOT EXISTS ip_cache (
            ip VARCHAR(45) PRIMARY KEY,
            country_code CHAR(2) NULL,
            is_proxy TINYINT(1) NOT NULL DEFAULT 0,
            is_tor TINYINT(1) NOT NULL DEFAULT 0,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 9. message_reactions
        $db->exec("CREATE TABLE IF NOT EXISTS message_reactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            reaction VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_msg_user_reaction (message_id, user_id, reaction)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 10. sent_emails
        $db->exec("CREATE TABLE IF NOT EXISTS sent_emails (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'sent',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 11. social_trust_adjustments
        $db->exec("CREATE TABLE IF NOT EXISTS social_trust_adjustments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            delta INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 12. social_trust_snapshots
        $db->exec("CREATE TABLE IF NOT EXISTS social_trust_snapshots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            score INT NOT NULL DEFAULT 50,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 13. suspect_flags
        $db->exec("CREATE TABLE IF NOT EXISTS suspect_flags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            flag_type VARCHAR(100) NOT NULL,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 14. user_badges
        $db->exec("CREATE TABLE IF NOT EXISTS user_badges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            badge_slug VARCHAR(100) NOT NULL,
            awarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_badge (user_id, badge_slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 15. user_blacklist
        $db->exec("CREATE TABLE IF NOT EXISTS user_blacklist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 16. user_favorites
        $db->exec("CREATE TABLE IF NOT EXISTS user_favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_fav (user_id, target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 17. user_stats
        $db->exec("CREATE TABLE IF NOT EXISTS user_stats (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL UNIQUE,
            total_earned DECIMAL(20,8) NOT NULL DEFAULT 0,
            total_tasks INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 18. user_trust_snapshots
        $db->exec("CREATE TABLE IF NOT EXISTS user_trust_snapshots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            score INT NOT NULL DEFAULT 50,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 19. task_stats_view (VIEW)
        $db->exec("CREATE OR REPLACE VIEW task_stats_view AS
            SELECT 
                id AS ad_id,
                user_id,
                type,
                status,
                total_budget,
                remaining_budget,
                total_count,
                completed_count,
                created_at,
                updated_at
            FROM ads;");
    }

    public function down(\Core\Database $db): void
    {
        $db->exec("DROP VIEW IF EXISTS task_stats_view;");
        $tables = [
            'user_trust_snapshots', 'user_stats', 'user_favorites', 'user_blacklist',
            'user_badges', 'suspect_flags', 'social_trust_snapshots', 'social_trust_adjustments',
            'sent_emails', 'message_reactions', 'ip_cache', 'impossible_travel_logs',
            'device_intelligence', 'device_analyses', 'chance_logs', 'audit_logs',
            'analytics_daily_summary', 'admin_alerts'
        ];
        foreach ($tables as $tbl) {
            $db->exec("DROP TABLE IF EXISTS {$tbl};");
        }
    }
};
