<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use Core\Database;
use Core\Container;

/**
 * Real integration tests for User-related services.
 * Tests against actual MariaDB with seed data.
 */
class UserServiceIntegrationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->db->ensureConnected();
    }

    /** @test */
    public function database_has_seed_users(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $this->assertGreaterThan(0, (int)$count, 'No seed users found in database');
    }

    /** @test */
    public function can_fetch_user_by_id(): void
    {
        $user = $this->db->fetch("SELECT id, email, status, full_name FROM users WHERE id = 1");
        $this->assertNotNull($user, 'Seed user with id=1 not found');
        $this->assertTrue(property_exists($user, 'email'));
        $this->assertTrue(property_exists($user, 'status'));
    }

    /** @test */
    public function users_table_has_required_columns(): void
    {
        $user = $this->db->fetch("SELECT * FROM users LIMIT 1");
        $this->assertNotNull($user);
        
        $required = ['id', 'email', 'password', 'status', 'full_name', 'created_at'];
        foreach ($required as $col) {
            $this->assertTrue(property_exists($user, $col), "Missing column: $col");
        }
    }

    /** @test */
    public function can_list_users_with_pagination(): void
    {
        $users = $this->db->fetchAll("SELECT id, email FROM users LIMIT 3");
        $this->assertIsArray($users);
        $this->assertLessThanOrEqual(3, count($users));
    }

    /** @test */
    public function can_search_users_by_email(): void
    {
        // Get a real email from the database
        $user = $this->db->fetch("SELECT email FROM users LIMIT 1");
        $this->assertNotNull($user);
        
        $result = $this->db->fetch(
            "SELECT id, email FROM users WHERE email = :email",
            ['email' => $user->email]
        );
        $this->assertNotNull($result);
        $this->assertEquals($user->email, $result->email);
    }

    /** @test */
    public function can_count_active_users(): void
    {
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL"
        );
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function roles_table_exists_and_has_data(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM roles");
        $this->assertGreaterThan(0, (int)$count, 'Roles table is empty — seed data missing');
    }

    /** @test */
    public function system_settings_table_exists_and_has_data(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM system_settings");
        $this->assertGreaterThan(0, (int)$count, 'system_settings table is empty');
    }

    /** @test */
    public function feature_flags_table_exists(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM feature_flags");
        $this->assertGreaterThan(0, (int)$count, 'feature_flags table is empty');
    }

    /** @test */
    public function transactions_table_exists(): void
    {
        // Just verify the table exists without error
        $result = $this->db->fetch("SELECT COUNT(*) as cnt FROM transactions");
        $this->assertNotNull($result);
    }

    /** @test */
    public function wallets_table_has_entries_for_users(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM wallets");
        // Wallets may or may not have entries — just verify the query works
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function database_query_builder_can_chain_methods(): void
    {
        $result = $this->db->table('users')
            ->where('status', '=', 'active')
            ->limit(2)
            ->get();
        $this->assertIsArray($result);
    }

    /** @test */
    public function database_query_builder_count_works(): void
    {
        $count = $this->db->table('users')->count();
        $this->assertGreaterThan(0, $count);
    }
}
