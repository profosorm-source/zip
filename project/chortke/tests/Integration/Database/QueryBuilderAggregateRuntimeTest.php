<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;

final class QueryBuilderAggregateRuntimeTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Application::getInstance()->container->make(Database::class);
    }

    public function test_avg_executes_as_aggregate_and_restores_original_query_state(): void
    {
        $query = $this->db->table('users')
            ->select('id', 'email')
            ->orderBy('id', 'ASC')
            ->limit(2);

        $expected = (float)$this->db->fetchColumn('SELECT AVG(`id`) FROM `users`');
        $this->assertSame($expected, $query->avg('id'));

        $rows = $query->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue(property_exists($row, 'id'));
            $this->assertTrue(property_exists($row, 'email'));
            $this->assertFalse(property_exists($row, 'avg'));
        }
    }

    public function test_avg_of_empty_result_is_zero_without_losing_where_state(): void
    {
        $query = $this->db->table('users')->where('id', '=', -1)->limit(10);

        $this->assertSame(0.0, $query->avg('id'));
        $this->assertSame([], $query->get());
    }

    public function test_count_ignores_limit_for_aggregate_and_restores_it_for_followup_get(): void
    {
        $expected = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM `users`');
        $query = $this->db->table('users')->select('id')->orderBy('id', 'ASC')->limit(2);

        $this->assertSame($expected, $query->count());
        $this->assertCount(2, $query->get());
    }

    public function test_named_variadic_select_arguments_are_normalized_to_column_list(): void
    {
        $row = $this->db->table('users')
            ->select(identifier: 'id', address: 'email')
            ->orderBy('id', 'ASC')
            ->first();

        $this->assertNotNull($row);
        $this->assertTrue(property_exists($row, 'id'));
        $this->assertTrue(property_exists($row, 'email'));
    }
}
