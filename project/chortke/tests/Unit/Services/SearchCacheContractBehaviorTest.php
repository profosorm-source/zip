<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\LoggerInterface;
use Tests\Fixtures\CorruptSearchCache;
use App\Services\Search\AdminSearchGateway;
use App\Services\Search\AdminSearchProvider;
use App\Services\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchCacheContractBehaviorTest extends TestCase
{
    public function test_admin_search_rejects_malformed_cache_before_database_search(): void
    {
        $cache = new CorruptSearchCache();
        $gateway = $this->createMock(AdminSearchGateway::class);
        $gateway->expects($this->never())->method('quickSearchUsers');
        $provider = new AdminSearchProvider(
            $cache,
            $this->createMock(LoggerInterface::class),
            $gateway
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Search cache entries must be arrays');
        $provider->searchAdmin(new SearchQuery('valid term', [], 20, 0));
    }
}
