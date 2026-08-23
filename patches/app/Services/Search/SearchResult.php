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
    ) {
        if (!array_is_list($items)) {
            throw new \UnexpectedValueException('Search result items must be a list.');
        }
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                throw new \UnexpectedValueException('Search result items must be stdClass values.');
            }
        }
        if ($total < 0) {
            throw new \InvalidArgumentException('Search result total must be non-negative.');
        }
        foreach ($metadata as $key => $_value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Search result metadata keys must be strings.');
            }
        }

        $this->items = $items;
        $this->total = $total;
        $this->metadata = $metadata;
    }

    /** @return list<\stdClass> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'metadata' => $this->metadata];
    }
}
