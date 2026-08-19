<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Core\Database;

/**
 * Real database integration tests — NO MOCKS.
 * Tests Database.php against actual MariaDB with real tables.
 */
class DatabaseIntegrationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->db->ensureConnected();
    }

    /** @test */
    public function database_can_connect_and_ping(): void
    {
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
        $result = $pdo->query('SELECT 1');
        $this->assertNotFalse($result);
    }

    /** @test */
    public function fetch_returns_object_for_existing_table(): void
    {
        $row = $this->db->fetch("SELECT id, email FROM users LIMIT 1");
        $this->assertIsObject($row);
        $this->assertTrue(property_exists($row, 'id'));
        $this->assertTrue(property_exists($row, 'email'));
    }

    /** @test */
    public function fetch_returns_null_for_no_rows(): void
    {
        $row = $this->db->fetch("SELECT * FROM users WHERE id = 999999999");
        $this->assertNull($row);
    }

    /** @test */
    public function fetchAll_returns_array(): void
    {
        $rows = $this->db->fetchAll("SELECT id, email FROM users LIMIT 5");
        $this->assertIsArray($rows);
        if (count($rows) > 0) {
            $this->assertIsObject($rows[0]);
        }
    }

    /** @test */
    public function fetchColumn_returns_value(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $this->assertIsNumeric($count);
        $this->assertGreaterThan(0, (int)$count);
    }

    /** @test */
    public function prepare_returns_statement(): void
    {
        $stmt = $this->db->prepare("SELECT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    /** @test */
    public function query_returns_statement(): void
    {
        $stmt = $this->db->query("SELECT id FROM users LIMIT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    /** @test */
    public function transaction_commit_works(): void
    {
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $this->assertIsNumeric($count);
        $this->db->commit();
        $this->assertFalse($this->db->inTransaction());
    }

    /** @test */
    public function transaction_rollback_works(): void
    {
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->rollback();
        $this->assertFalse($this->db->inTransaction());
    }

    /** @test */
    public function table_returns_query_builder_that_works(): void
    {
        $qb = $this->db->table('users');
        $this->assertInstanceOf(\Core\QueryBuilder::class, $qb);
        $result = $qb->limit(1)->first();
        $this->assertIsObject($result);
    }

    /** @test */
    public function select_returns_array(): void
    {
        $rows = $this->db->select("SELECT id, email FROM users LIMIT 3");
        $this->assertIsArray($rows);
    }

    /** @test */
    public function selectOne_returns_object_or_null(): void
    {
        $row = $this->db->selectOne("SELECT id, email FROM users LIMIT 1");
        $this->assertIsObject($row);
        $none = $this->db->selectOne("SELECT id FROM users WHERE id = 999999999");
        $this->assertNull($none);
    }

    /** @test */
    public function parameterized_queries_work(): void
    {
        $user = $this->db->fetch("SELECT id, email FROM users LIMIT 1");
        $this->assertNotNull($user);
        $verify = $this->db->fetch("SELECT * FROM users WHERE id = ?", [(int)$user->id]);
        $this->assertNotNull($verify);
        $this->assertEquals($user->id, $verify->id);
    }

    /** @test */
    public function named_parameter_queries_work(): void
    {
        $user = $this->db->fetch("SELECT id, email FROM users LIMIT 1");
        $this->assertNotNull($user);
        $verify = $this->db->fetch("SELECT * FROM users WHERE id = :uid", ['uid' => (int)$user->id]);
        $this->assertNotNull($verify);
    }

    /** @test */
    public function nested_transaction_with_savepoint_works(): void
    {
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $this->assertIsNumeric($count);
        $this->db->rollback();
        $this->db->rollback();
        $this->assertFalse($this->db->inTransaction());
    }
}
