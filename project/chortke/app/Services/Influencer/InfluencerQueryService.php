<?php

declare(strict_types=1);

namespace App\Services\Influencer;

use App\Models\InfluencerModel;

/**
 * سرویس Query اینفلوئنسر — فقط خواندن (جستجو، لیست)
 */
class InfluencerQueryService
{
    private InfluencerModel $model;

    public function __construct(InfluencerModel $model) {
        $this->model = $model;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        $filters['status'] = 'verified';
        $q = str_value($filters['q'] ?? '');
        $sort = $filters['sort'] ?? 'newest';
        [$sortCol, $sortDir] = match ($sort) {
            'oldest'    => ['created_at', 'ASC'],
            'followers' => ['follower_count', 'DESC'],
            'rating'    => ['average_rating', 'DESC'],
            default     => ['created_at', 'DESC'],
        };

        return $this->model->searchNative($q, $filters, $limit, $offset, $sortCol, $sortDir);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->model->searchNative($q, $filters, $limit, $offset);
    }
}
