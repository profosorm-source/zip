<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Domain\Gamification\Strategies\InactivityDecayStrategy;
use App\Domain\Gamification\Strategies\TrustEvaluationStrategy;
use App\Services\MigrationService;
use App\Enums\ModuleContext;
use App\Models\User;
use Mockery as m;

class RemainingStrategiesAndMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function inactivity_decay_strategy_calculates_correct_penalties(): void
    {
        $strategy = new InactivityDecayStrategy();

        $user = m::mock(User::class);
        $context = ModuleContext::SOCIAL_TASKS;

        // Test non-VIP user with 1 inactive day (20% decay)
        $penaltyNonVip = $strategy->calculate($user, $context, [
            'inactive_days' => 1,
            'current_score' => 1000.0,
            'is_vip' => false
        ]);
        $this->assertEquals(-200.0, $penaltyNonVip); // -20% of 1000

        // Test VIP user with 1 inactive day (50% decay)
        $penaltyVip = $strategy->calculate($user, $context, [
            'inactive_days' => 1,
            'current_score' => 1000.0,
            'is_vip' => true
        ]);
        $this->assertEquals(-500.0, $penaltyVip); // -50% of 1000
    }

    /** @test */
    public function trust_evaluation_strategy_maps_correct_actions(): void
    {
        $strategy = new TrustEvaluationStrategy();

        $user = m::mock(User::class);
        $context = ModuleContext::SOCIAL_TASKS;

        $this->assertEquals(-50.0, $strategy->calculate($user, $context, ['action' => 'critical_violation']));
        $this->assertEquals(-10.0, $strategy->calculate($user, $context, ['action' => 'task_rejected']));
        $this->assertEquals(2.0, $strategy->calculate($user, $context, ['action' => 'task_approved']));
        $this->assertEquals(0.0, $strategy->calculate($user, $context, ['action' => 'unknown_action']));
    }

    /** @test */
    public function migration_service_detects_already_up_to_date_schemas(): void
    {
        $redis = m::mock('Core\Redis');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();
        $redis->shouldReceive('isAvailable')->andReturn(false); // Bypass redis lock for simple testing

        // Mock initialization table query and DB lock
        $db->shouldReceive('query')->with("CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) UNIQUE NOT NULL,
                batch INT NOT NULL,
                checksum VARCHAR(64) NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )")->once();

        $dbStmt = m::mock(\PDOStatement::class);
        $dbStmt->shouldReceive('fetch')->andReturn((object)['lock_status' => 1]);
        $db->shouldReceive('query')->with("SELECT GET_LOCK('schema_migration_lock', 10) as lock_status")->andReturn($dbStmt);
        $db->shouldReceive('query')->with("SELECT RELEASE_LOCK('schema_migration_lock')")->byDefault();

        // Construct list of all actual migrations to bypass executions
        $migrationsDir = __DIR__ . '/../../../database/migrations';
        $allFiles = array_merge(
            glob($migrationsDir . '/*.sql') ?: [],
            glob($migrationsDir . '/*.php') ?: []
        );
        $executed = [];
        foreach ($allFiles as $file) {
            $executed[] = (object)['migration' => basename($file), 'batch' => 1, 'checksum' => hash_file('sha256', $file)];
        }

        // Mock already executed migrations
        $db->shouldReceive('fetchAll')
            ->with("SELECT migration, batch, checksum, executed_at FROM schema_migrations ORDER BY batch, id")
            ->once()
            ->andReturn($executed);

        $service = new MigrationService($redis, $db, $logger);

        $result = $service->runMigrations();

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['executed']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('Database schema is already up to date', $result['message']);
    }
}
