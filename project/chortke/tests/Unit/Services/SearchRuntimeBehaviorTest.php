<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Services\Search\SchemaInspector;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchProjectionListener;
use App\Services\Search\SearchProjectionRepository;
use Core\Database;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class SearchRuntimeBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_projection_rejects_deep_offset_before_querying_database(): void
    {
        config_set('search.max_offset',100);
        $schema=m::mock(SchemaInspector::class);$schema->shouldReceive('tableExists')->once()->with('search_projections')->andReturn(true);
        $db=m::mock(Database::class);$db->shouldNotReceive('fetchColumn');$db->shouldNotReceive('fetchAll');
        $logger=m::mock(LoggerInterface::class);$logger->shouldReceive('warning')->once()->with('search.projection.deep_offset_blocked',['requested_offset'=>101,'max_offset'=>100]);
        $result=(new SearchProjectionRepository($db,$logger,$schema))->search('term',[],20,101)->toArray();
        $this->assertSame([], $result['items']);
        $this->assertSame(0,$result['total']);
        $this->assertSame('offset_too_deep',$result['metadata']['error']);
    }

    public function test_account_deleted_event_deactivates_all_owner_projections(): void
    {
        $db=m::mock(Database::class);
        $db->shouldReceive('execute')->once()->with(
            m::on(static fn(string $sql): bool=>str_contains($sql,'SET is_active = 0')&&str_contains($sql,'owner_id = ?')),
            [77]
        )->andReturn(3);
        $logger=m::mock(LoggerInterface::class);$logger->shouldReceive('info')->once()->with('search.projection.owner_deactivated',['user_id'=>77]);
        $listener=new SearchProjectionListener($this->lenientMock(SearchIndexer::class),$logger,$db);
        $listener->handle('account.deleted',['user_id'=>77]);
        $this->addToAssertionCount(1);
    }
}
