<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use Core\Database;

/**
 * Schema integrity tests — validates that all expected tables exist.
 */
class SchemaIntegrityTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->db->ensureConnected();
    }

    /** @test */
    public function all_core_tables_exist(): void
    {
        $requiredTables = [
            'users', 'roles', 'system_settings', 'feature_flags',
            'transactions', 'wallets', 'payment_logs', 'bank_cards',
            'withdrawals', 'manual_deposits', 'escrow_transactions',
            'tickets', 'ticket_messages', 'direct_messages',
            'ads', 'social_task_executions', 'task_executions',
            'ratings', 'notifications', 'email_queue',
            'user_sessions', 'activity_logs', 'api_tokens',
            'kyc_verifications', 'referral_commissions',
            'common_passwords', 'lottery_rounds', 'prediction_games',
            'user_sessions', 'manual_deposits', 'crypto_deposits',
        ];

        $existing = $this->db->fetchAll(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()"
        );
        $existingNames = array_map(fn($r) => strtolower($r->TABLE_NAME), $existing);

        foreach ($requiredTables as $table) {
            $this->assertContains(
                strtolower($table),
                $existingNames,
                "Required table '$table' is missing from database"
            );
        }
    }

    /** @test */
    public function users_table_has_all_required_columns(): void
    {
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.columns 
             WHERE table_schema = DATABASE() AND table_name = 'users'"
        );
        $colNames = array_map(fn($r) => $r->COLUMN_NAME, $cols);

        $required = ['id', 'email', 'password', 'status', 'full_name', 
                     'created_at', 'updated_at', 'mobile'];
        foreach ($required as $col) {
            $this->assertContains($col, $colNames, "Missing column 'users.$col'");
        }
    }

    /** @test */
    public function transactions_table_has_all_required_columns(): void
    {
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.columns 
             WHERE table_schema = DATABASE() AND table_name = 'transactions'"
        );
        $colNames = array_map(fn($r) => $r->COLUMN_NAME, $cols);

        $required = ['id', 'user_id', 'type', 'amount', 'currency', 'status', 'created_at'];
        foreach ($required as $col) {
            $this->assertContains($col, $colNames, "Missing column 'transactions.$col'");
        }
    }

    /** @test */
    public function wallets_table_has_all_required_columns(): void
    {
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.columns 
             WHERE table_schema = DATABASE() AND table_name = 'wallets'"
        );
        $colNames = array_map(fn($r) => $r->COLUMN_NAME, $cols);

        $this->assertContains('user_id', $colNames, 'wallets missing user_id');
        $this->assertContains('balance_irt', $colNames, 'wallets missing balance_irt');
    }

    /** @test */
    public function feature_flags_table_has_expected_columns(): void
    {
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.columns 
             WHERE table_schema = DATABASE() AND table_name = 'feature_flags'"
        );
        $colNames = array_map(fn($r) => $r->COLUMN_NAME, $cols);

        $required = ['id', 'name', 'enabled'];
        foreach ($required as $col) {
            $this->assertContains($col, $colNames, "Missing column 'feature_flags.$col'");
        }
    }

    /** @test */
    public function no_table_has_zero_rows_if_seed_data_expected(): void
    {
        $tablesWithSeeds = [
            'users' => 1,
            'roles' => 1,
            'feature_flags' => 1,
            'system_settings' => 1,
        ];

        foreach ($tablesWithSeeds as $table => $minRows) {
            $count = $this->db->fetchColumn("SELECT COUNT(*) FROM `{$table}`");
            $this->assertGreaterThanOrEqual(
                $minRows,
                (int)$count,
                "Table '$table' should have at least $minRows rows (seed data)"
            );
        }
    }
}
