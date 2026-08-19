<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use App\Services\Search\SchemaInspector;
use Core\Application;
use Core\Cache;
use Core\Database;
use PHPUnit\Framework\TestCase;

final class SchemaInspectorContractRuntimeTest extends TestCase
{
    private Cache $cache;
    private SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Application::getInstance()->container;
        $this->cache = $container->make(Cache::class);
        $this->cache->tags(['schema:introspection'])->flush();
        $this->inspector = new SchemaInspector(
            $container->make(Database::class),
            $this->cache
        );
    }

    protected function tearDown(): void
    {
        $this->cache->tags(['schema:introspection'])->flush();
        parent::tearDown();
    }

    public function test_real_schema_returns_exact_boolean_and_string_list_contracts(): void
    {
        $this->assertTrue($this->inspector->tableExists('users'));
        $columns = $this->inspector->getColumns('users');
        $this->assertContains('id', $columns);
        foreach ($columns as $column) {
            $this->assertIsString($column);
        }
        $this->assertFalse($this->inspector->tableExists('phase20_table_that_does_not_exist'));
    }

    public function test_corrupt_schema_cache_fails_fast_instead_of_becoming_truthy(): void
    {
        $this->cache->tags(['schema:introspection'])->put(
            'schema:table_exists:users',
            'corrupt-cache-payload',
            60
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('table-exists cache must contain a boolean');
        $this->inspector->tableExists('users');
    }
}
