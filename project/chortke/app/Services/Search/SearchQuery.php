<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * SearchQuery - شیء مقدار برای یکپارچه‌سازی پارامترهای جستجو، فیلتر و مرتب‌سازی
 */
final class SearchQuery
{
    private ?string $term;
    /** @var array<string, mixed> */
    private array $filters;
    private int $limit;
    private int $offset;
    private string $sort;
    /** @param array<string, mixed> $filters */
    public function __construct(
        ?string $term = null,
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        string $sort = 'created_at DESC'
    ) {        $this->term = $term;
        $this->filters = $filters;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->sort = $sort;
}

    public function getTerm(): ?string { return $this->term; }
    /** @return array<string, mixed> */
    public function getFilters(): array { return $this->filters; }
    public function getLimit(): int { return $this->limit; }
    public function getOffset(): int { return $this->offset; }
    public function getSort(): string { return $this->sort; }

    /**
     * ساخت سریع کوئری از ریکوئست
     */
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $term = $data['q'] ?? $data['term'] ?? null;
        return new self(
            $term === null ? null : str_value($term),
            is_array($data['filters'] ?? null) ? $data['filters'] : [],
            int_value($data['limit'] ?? 50),
            int_value($data['offset'] ?? 0),
            str_value($data['sort'] ?? 'created_at DESC'));
    }
}