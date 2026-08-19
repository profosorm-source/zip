<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Blueprint;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BlueprintBehaviorTest extends TestCase
{
    public function test_string_default_is_sql_quoted_without_becoming_executable_sql(): void
    {
        $sql = (new Blueprint('settings'))
            ->string('label')
            ->default("O'Brien'); DROP TABLE users; --")
            ->toSql();

        $this->assertStringContainsString(
            "DEFAULT 'O''Brien''); DROP TABLE users; --'",
            $sql
        );
        $this->assertSame(1, substr_count($sql, 'CREATE TABLE'));
    }

    public function test_scalar_defaults_have_deterministic_sql_representation(): void
    {
        $sql = (new Blueprint('flags'))
            ->boolean('enabled')->default(true)
            ->integer('attempts')->default(0)
            ->decimal('ratio')->default(1.25)
            ->text('note')->default(null)
            ->toSql();

        $this->assertStringContainsString('`enabled` TINYINT(1) DEFAULT 1', $sql);
        $this->assertStringContainsString('`attempts` INT DEFAULT 0', $sql);
        $this->assertStringContainsString('`ratio` DECIMAL(8, 2) DEFAULT 1.25', $sql);
        $this->assertStringContainsString('`note` TEXT DEFAULT NULL', $sql);
    }

    public function test_default_without_column_fails_closed(): void
    {
        $this->expectException(LogicException::class);
        (new Blueprint('invalid'))->default('value');
    }

    public function test_non_finite_float_default_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Blueprint('invalid'))->decimal('amount')->default(INF);
    }
}
