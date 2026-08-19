<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\PerformanceOptimizationService;
use Mockery as m;

class PerformanceAndOptimizationTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    private PerformanceOptimizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');

        $this->logger->shouldIgnoreMissing();
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();
        $this->db->shouldReceive('rollBack')->byDefault();

        $this->service = new PerformanceOptimizationService(
            $this->db,
            $this->logger,
            new \Core\PathResolver(dirname(__DIR__, 3))
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(PerformanceOptimizationService::class, $this->service);
    }

    /** @test */
    public function batch_insert_builds_and_executes_valid_sql_and_transactions(): void
    {
        $table = 'users';
        $records = [
            ['username' => 'user1', 'email' => 'user1@example.com'],
            ['username' => 'user2', 'email' => 'user2@example.com']
        ];

        // Database expectation for transaction and batch insert
        $this->db->shouldReceive('beginTransaction')->once();
        
        $expectedSql = "INSERT INTO `users` (`username`, `email`) VALUES (?, ?), (?, ?)";
        $expectedValues = ['user1', 'user1@example.com', 'user2', 'user2@example.com'];

        $this->db->shouldReceive('query')
            ->with($expectedSql, $expectedValues)
            ->once();

        $this->db->shouldReceive('commit')->once();

        $result = $this->service->batchInsert($table, $records);

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['inserted']);
    }

    /** @test */
    public function bulk_update_with_case_builds_and_executes_case_clauses(): void
    {
        $table = 'users';
        $idColumn = 'id';
        $updates = [
            12 => ['status' => 'active'],
            13 => ['status' => 'suspended']
        ];

        $this->db->shouldReceive('beginTransaction')->once();

        $expectedSql = "UPDATE `users` SET `status` = CASE `id` WHEN ? THEN ? WHEN ? THEN ? END WHERE `id` IN (?, ?)";
        $expectedParams = [12, 'active', 13, 'suspended', 12, 13];

        $this->db->shouldReceive('query')
            ->with($expectedSql, $expectedParams)
            ->once();

        $this->db->shouldReceive('commit')->once();

        $result = $this->service->bulkUpdateWithCase($table, $idColumn, $updates);

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['updated']);
    }

    /** @test */
    public function query_tracking_keeps_accurate_times_and_computes_stats(): void
    {
        $this->service->trackQueryTime('SELECT * FROM users', 12.5);
        $this->service->trackQueryTime('UPDATE users SET status = ?', 34.5);

        $stats = $this->service->getQueryStats();

        $this->assertEquals(2, $stats['total_queries']);
        $this->assertEquals(23.5, $stats['average_time_ms']); // (12.5 + 34.5) / 2 = 23.5
        $this->assertEquals(34.5, $stats['slowest_query_ms']);
        $this->assertEquals('UPDATE users SET status = ?', $stats['slowest_query']);
    }

    /** @test */
    public function batch_insert_rejects_empty_records_without_querying_db(): void
    {
        $this->db->shouldNotReceive('beginTransaction');
        $this->db->shouldNotReceive('query');

        $result = $this->service->batchInsert('users', []);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    /** @test */
    public function batch_insert_rejects_invalid_table_identifier(): void
    {
        $this->db->shouldNotReceive('beginTransaction');
        $this->db->shouldNotReceive('query');

        // نام جدول با کاراکتر غیرمجاز → باید بدون query رد شود (حفاظت SQL injection)
        $result = $this->service->batchInsert('users; DROP TABLE x', [['a' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    /** @test */
    public function batch_update_rejects_mismatched_parameters_without_querying(): void
    {
        $this->db->shouldNotReceive('beginTransaction');
        $this->db->shouldNotReceive('query');

        // updates غیرخالی اما whereValues خالی → رد شدن (Fail-Closed)
        $result = $this->service->batchUpdate('users', [['status' => 'active']], 'id', []);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }
}
