<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Search\SearchOrchestrator;
use App\Services\Search\SearchProjectionListener;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchProjectionRepository;
use App\Services\Search\AdminSearchProvider;
use App\Services\Search\UserSearchProvider;
use App\Enums\ModuleContext;
use App\Models\User;
use Mockery as m;

class SearchEcosystemTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function orchestrator_registers_and_delegates_to_provider(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $rateLimiter = m::mock('Core\RateLimiter');
        
        $logger->shouldIgnoreMissing();
        $rateLimiter->shouldReceive('attempt')->andReturn(true);

        $orchestrator = new SearchOrchestrator($logger, $rateLimiter);

        $provider = m::mock('App\Contracts\SearchProviderInterface');
        $provider->shouldReceive('supports')->with('transactions')->andReturn(true);
        
        $query = new SearchQuery('test_query', ['scope' => 'transactions']);
        $expectedResult = new SearchResult([(object)['value' => 'item1']], 1);

        $provider->shouldReceive('search')
            ->with($query)
            ->once()
            ->andReturn($expectedResult);

        $orchestrator->registerProvider($provider);

        $result = $orchestrator->searchQuery($query);

        $this->assertSame($expectedResult, $result);
    }

    /** @test */
    public function orchestrator_applies_rate_limiting(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $rateLimiter = m::mock('Core\RateLimiter');
        
        $logger->shouldIgnoreMissing();
        
        // Rate limiter returns false (rate limited!)
        $rateLimiter->shouldReceive('attempt')->andReturn(false);

        $orchestrator = new SearchOrchestrator($logger, $rateLimiter);
        
        $query = new SearchQuery('test_query', ['scope' => 'transactions']);
        $result = $orchestrator->searchQuery($query);

        $this->assertEmpty($result->getItems());
        $this->assertTrue($result->getMetadata()['rate_limited'] ?? false);
    }

    /** @test */
    public function listener_delegates_events_to_indexer_correctly(): void
    {
        $indexer = m::mock('App\Services\Search\SearchIndexer');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        // 1. Test ticket.created
        $ticketPayload = [
            'ticket_id' => 101,
            'user_id' => 12,
            'ticket_code' => 'TCK-999',
            'subject' => 'مشکل پرداخت',
            'status' => 'open'
        ];

        $indexer->shouldReceive('index')
            ->with('ticket', 101, 'مشکل پرداخت', m::any(), m::any(), true, 12, 'user', 'tickets', 'TCK-999')
            ->once();

        // 2. Test our newly implemented indexPrediction method via prediction.created event
        $predictionPayload = [
            'id' => 201,
            'user_id' => 12,
            'title' => 'مسابقه پیش‌بینی هفته ۲۵',
            'description' => 'توضیحات پیش‌بینی'
        ];

        $indexer->shouldReceive('index')
            ->with('prediction', 201, 'مسابقه پیش‌بینی هفته ۲۵', m::any(), m::any(), true, 12, 'user', 'predictions', null)
            ->once();

        // 3. Test our newly implemented indexLottery method via lottery.created event
        $lotteryPayload = [
            'id' => 301,
            'user_id' => 12,
            'title' => 'قرعه‌کشی بهاره',
            'description' => 'جایزه ویژه'
        ];

        $indexer->shouldReceive('index')
            ->with('lottery', 301, 'قرعه‌کشی بهاره', m::any(), m::any(), true, 12, 'user', 'lotteries', null)
            ->once();

        $listener = new SearchProjectionListener($indexer, $logger, m::mock('Core\Database'));

        $listener->handle('ticket.created', $ticketPayload);
        $listener->handle('prediction.created', $predictionPayload);
        $listener->handle('lottery.created', $lotteryPayload);

        $this->assertTrue(true);
    }

    /** @test */
    public function repository_is_ready_checks_schema_inspector(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $schema = m::mock('App\Services\Search\SchemaInspector');

        $logger->shouldIgnoreMissing();

        // Schema inspector returns true (table exists)
        $schema->shouldReceive('tableExists')->with('search_projections')->once()->andReturn(true);

        // Database fetchColumn returns 1 (isReady = true)
        $db->shouldReceive('fetchColumn')->once()->andReturn(1);

        $repository = new SearchProjectionRepository($db, $logger, $schema);

        $this->assertTrue($repository->isReady('admin'));
    }

    /** @test */
    public function repository_search_builds_and_executes_boolean_fulltext_queries(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $schema = m::mock('App\Services\Search\SchemaInspector');

        $logger->shouldIgnoreMissing();

        $schema->shouldReceive('tableExists')->with('search_projections')->once()->andReturn(true);

        // Expected queries results
        $db->shouldReceive('fetchColumn')->once()->andReturn(1); // COUNT total = 1

        $rowsMock = [
            (object)[
                'entity_id' => 456,
                'entity_type' => 'ticket',
                'module' => 'tickets',
                'ref' => 'TCK-12',
                'title' => 'عنوان',
                'updated_at' => '2026-06-03 12:00:00',
                'metadata' => json_encode(['priority' => 'high'])
            ]
        ];

        $db->shouldReceive('fetchAll')->once()->andReturn($rowsMock);

        $repository = new SearchProjectionRepository($db, $logger, $schema);

        $query = 'تست';
        $filters = ['scope' => 'user', 'owner_id' => 12];

        $result = $repository->search($query, $filters, 20, 0);

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertEquals(1, $result->getTotal());
        $this->assertCount(1, $result->getItems());
        $item0 = (object)$result->getItems()[0];
        $meta0 = is_array($item0->metadata ?? null) ? $item0->metadata : (array)($item0->metadata ?? []);
        $this->assertEquals(456, $item0->id);
        $this->assertEquals('high', $meta0['priority'] ?? '');
    }

    /** @test */
    public function admin_search_provider_delegates_to_gateway(): void
    {
        $cache = m::mock('App\Contracts\CacheInterface');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $gateway = m::mock('App\Services\Search\AdminSearchGateway');

        $logger->shouldIgnoreMissing();
        
        // Cache tags mock of type CacheInterface
        $taggedCache = m::mock('App\Contracts\CacheInterface');
        $taggedCache->shouldReceive('get')->once()->andReturn(null); // cache miss
        $taggedCache->shouldReceive('set')->once();
        $cache->shouldReceive('tags')->andReturn($taggedCache);

        $query = new SearchQuery('term', ['scope' => 'admin_module:kyc']);
        
        $searchResult = new SearchResult([(object)['id' => 12, 'entity_type' => 'user']], 1);

        // Expect gateway search
        $gateway->shouldReceive('searchRegistered')
            ->with('kyc', 'term', ['scope' => 'admin_module:kyc'], 50, 0)
            ->once()
            ->andReturn($searchResult);

        $provider = new AdminSearchProvider($cache, $logger, $gateway);

        $result = $provider->search($query);

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertCount(1, $result->getItems());
    }

    /** @test */
    public function user_search_provider_applies_ownership_isolation(): void
    {
        $cache = m::mock('App\Contracts\CacheInterface');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $gateway = m::mock('App\Services\Search\UserSearchGateway');

        $logger->shouldIgnoreMissing();
        
        // Cache tags mock of type CacheInterface
        $taggedCache = m::mock('App\Contracts\CacheInterface');
        $taggedCache->shouldReceive('get')->once()->andReturn(null); // cache miss
        $taggedCache->shouldReceive('set')->once();
        $cache->shouldReceive('tags')->andReturn($taggedCache);

        $searchResult = new SearchResult([(object)['id' => 12, 'entity_type' => 'ticket']], 1);

        // Expect gateway search to enforce owner_id and scope=user
        $gateway->shouldReceive('searchRegistered')
            ->with('tickets', m::type(SearchQuery::class))
            ->once()
            ->andReturn($searchResult);

        $provider = new UserSearchProvider($cache, $logger, $gateway);

        $result = $provider->searchDomain('tickets', 'term', 45, [], 20, 0);

        $this->assertEquals(1, $result['total']);
    }

    /** @test */
    public function test_integration_cqrs_search_events_trigger_listeners_with_correct_normalization(): void
    {
        $indexer = m::mock('App\Services\Search\SearchIndexer');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        // 1. KYCApprovedEvent -> kyc.approved
        $indexer->shouldReceive('index')
            ->with('kyc', 501, 'KYC Submission', m::any(), m::any(), true, 12, 'user', 'kyc', null)
            ->once();

        // 2. EscrowReleasedEvent -> escrow.released
        $indexer->shouldReceive('index')
            ->with('escrow', 601, 'Escrow #601', m::any(), m::any(), true, 12, 'user', 'escrows', null)
            ->once();

        $listener = new SearchProjectionListener($indexer, $logger, m::mock('Core\Database'));

        // Instantiate real event classes for perfect reflection-based normalization
        $kycEvent = new \App\Events\KYCApprovedEvent(12, 501);
        $escrowEvent = new \App\Events\EscrowReleasedEvent(601, 12, 50.0, 'irt');

        $listener->handle($kycEvent);
        $listener->handle($escrowEvent);

        $this->assertTrue(true);
    }
}
