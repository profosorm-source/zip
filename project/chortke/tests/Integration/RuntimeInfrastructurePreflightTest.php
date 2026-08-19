<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Hard preflight for the runtime integration suite.
 * Missing infrastructure is a build failure, never a skipped test.
 */
final class RuntimeInfrastructurePreflightTest extends TestCase
{
    public function test_application_uses_the_canonical_container(): void
    {
        $application = Application::getInstance();

        $this->assertSame(
            $application->container,
            \Core\Container::getInstance(),
            'Application and static accessor must reference one canonical Container.'
        );
    }

    public function test_mariadb_is_reachable_and_schema_is_complete(): void
    {
        $database = Application::getInstance()->container->make(Database::class);
        $pdo = $database->getPdo();

        $this->assertSame('1', $this->pdoScalar($pdo, 'SELECT 1'));
        $migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/*.{php,sql}', GLOB_BRACE);
        $this->assertIsArray($migrationFiles);
        $this->assertNotEmpty($migrationFiles, 'Production migration inventory is empty.');
        $this->assertSame(
            count($migrationFiles),
            int_value($this->pdoScalar($pdo, 'SELECT COUNT(*) FROM schema_migrations')),
            'The test database must be created by the complete migration chain.'
        );
        $this->assertSame(
            256,
            int_value($this->pdoScalar($pdo,
                "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )),
            'Fresh test schema table count is incomplete.'
        );

        foreach (['users', 'wallets', 'transactions', 'ledger_entries', 'outbox_events', 'failed_jobs', 'idempotency_keys'] as $table) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $statement->execute([$table]);
            $this->assertSame(1, (int) $statement->fetchColumn(), "Required runtime table '{$table}' is missing.");
        }

        $this->assertGreaterThanOrEqual(
            4,
            int_value($this->pdoScalar($pdo, 'SELECT COUNT(*) FROM users')),
            'Deterministic seed users are missing.'
        );
        $this->assertGreaterThanOrEqual(
            4,
            int_value($this->pdoScalar($pdo, 'SELECT COUNT(*) FROM wallets')),
            'Seed wallets are missing.'
        );
    }

    public function test_redis_is_reachable_authenticated_and_writable(): void
    {
        $redisConfig = config('redis', []);
        $this->assertIsArray($redisConfig);
        $host = is_string($redisConfig['host'] ?? null) ? $redisConfig['host'] : '127.0.0.1';
        $port = is_numeric($redisConfig['port'] ?? null) ? (int) $redisConfig['port'] : 6379;
        $password = is_string($redisConfig['password'] ?? null) ? $redisConfig['password'] : '';
        $database = is_numeric($redisConfig['database'] ?? null) ? (int) $redisConfig['database'] : 0;

        $redis = new \Redis();
        $this->assertTrue($redis->connect($host, $port, 2.0), 'Redis connection failed.');
        if ($password !== '') {
            $this->assertTrue($redis->auth($password), 'Redis authentication failed.');
        }
        $this->assertTrue($redis->select($database), 'Redis database selection failed.');
        $this->assertTrue((bool) $redis->ping(), 'Redis PING failed.');

        $key = 'phpunit:preflight:' . bin2hex(random_bytes(8));
        try {
            $this->assertTrue($redis->setEx($key, 10, 'ok'), 'Redis write failed.');
            $this->assertSame('ok', $redis->get($key), 'Redis read-after-write failed.');
        } finally {
            $redis->del($key);
            $redis->close();
        }
    }
    private function pdoScalar(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof \PDOStatement) $this->fail("PDO query failed: {$sql}");
        return str_value($statement->fetchColumn());
    }

}
