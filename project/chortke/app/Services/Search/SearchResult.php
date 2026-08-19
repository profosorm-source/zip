<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * SearchResult - ساختار یکپارچه خروجی تمامی جستجوها
 */
final class SearchResult
{
    /** @var list<\stdClass> */
    private array $items;
    private int $total;
    /** @var array<string, mixed> */
    private array $metadata;
    /**
     * @param list<\stdClass> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        array $items,
        int $total,
        array $metadata = []
    ) {        $this->items = $items;
        $this->total = $total;
        $this->metadata = $metadata;
}

    /** @return list<\stdClass> */
    public function getItems(): array { return $this->items; }
    public function getTotal(): int { return $this->total; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
    /** @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>} */
    public function toArray(): array { return ['items' => $this->items, 'total' => $this->total, 'metadata' => $this->metadata]; }
}