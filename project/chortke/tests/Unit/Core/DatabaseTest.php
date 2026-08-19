<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Database;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Every singleton test must start before the real project Database has
        // been resolved by another test or by the container bootstrap.
        Database::reset();
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->resetDatabaseSingleton();
    }

    public function testResetClearsSingletonInstance(): void
    {
        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        // The singleton is now type-safe; use an actual Database instance
        // without connecting rather than assigning an unrelated object.
        $property->setValue(null, $reflection->newInstanceWithoutConstructor());

        $this->assertNotNull($property->getValue());

        Database::reset();

        $this->assertNull($property->getValue());
    }

    public function testGetInstanceWithInvalidConfigThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        $db = Database::getInstance([
            'host' => '127.0.0.1',
            'port' => 9999,
            'name' => 'invalid_db_name',
            'user' => 'invalid_user',
            'pass' => 'wrong_password',
            'charset' => 'utf8mb4',
        ]);

        // Connection is lazy — trigger it explicitly
        $db->ensureConnected();
    }

    /** @test */
    public function testPrepareRecordsLastSqlErrorContextWhenPrepareFails(): void
    {
        $database = $this->createDatabaseWithFailingPdo();

        ob_start();
        try {
            $database->prepare('SELECT * FROM invalid_table');
            $this->fail('Expected PDOException not thrown');
        } catch (PDOException $e) {
            ob_end_clean();
            $context = Database::getLastSqlErrorContext();

            $this->assertIsArray($context);
            $this->assertSame('SELECT * FROM invalid_table', $context['sql']);
            $this->assertSame(0, $context['params_count']);
            $this->assertArrayHasKey('stack', $context);
            $this->assertSame(PDOException::class, $context['error_type']);
            $this->assertIsString($context['error']);
        $this->assertStringContainsString('SQL syntax error', $context['error']);
        }
    }

    private function createDatabaseWithFailingPdo(): Database
    {
        $reflection = new \ReflectionClass(Database::class);
        $database = $reflection->newInstanceWithoutConstructor();

        // Keep Database::$pdo type-safe in production and tests. PDO is
        // subclassable, so this test double preserves the real PDO contract
        // while deterministically exercising the prepare-error path.
        $pdoStub = new class extends \PDO {
            public function __construct() {}

            /** @param array<int,mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new \PDOException('SQL syntax error');
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new \PDOException('Unexpected ping query');
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };

        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $property->setValue($database, $pdoStub);

        // Set lastPingTime to now so ensureConnected skips reconnection
        $pingProp = $reflection->getProperty('lastPingTime');
        $pingProp->setAccessible(true);
        $pingProp->setValue($database, time());

        return $database;
    }

    private function resetDatabaseSingleton(): void
    {
        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
