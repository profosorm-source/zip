<?php

declare(strict_types=1);

namespace App\Contracts;

use Core\QueryBuilder;

interface AdsRepositoryInterface
{
    public function find(int $id): ?object;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function where(string $column, string $operatorOrValue = '=', mixed $value = null): QueryBuilder;

    public function whereNull(string $column): QueryBuilder;

    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilder;
}
