<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\BulkOperationsService;
use Mockery as m;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
class BulkOperationsServiceTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\BulkOperation&\Mockery\MockInterface */
    private \App\Models\BulkOperation $bulkOperationModel;
    /** @var \App\Contracts\CacheInterface&\Mockery\MockInterface */
    private \App\Contracts\CacheInterface $cache;
    /** @var \App\Contracts\NotificationServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\NotificationServiceInterface $notificationService;
    private BulkOperationsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->bulkOperationModel = m::mock('App\Models\BulkOperation');
        $this->cache = m::mock('App\Contracts\CacheInterface');
        $this->notificationService = m::mock('App\Contracts\NotificationServiceInterface');

        $this->logger->shouldIgnoreMissing();
        $this->db->shouldReceive('inTransaction')->byDefault()->andReturn(false);
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();
        $this->db->shouldReceive('rollBack')->byDefault();

        $this->service = new BulkOperationsService(
            $this->db,
            $this->logger,
            $this->bulkOperationModel,
            $this->cache,
            new \Core\PathResolver(dirname(__DIR__, 3)),
            $this->notificationService
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(BulkOperationsService::class, $this->service);
    }

    /** @test */
    public function bulk_update_calls_model_in_batches_properly(): void
    {
        $table = 'users';
        $ids = range(1, 150); // 150 items (will trigger 2 batches of size 100)
        $data = ['status' => 'active'];

        $this->bulkOperationModel->shouldReceive('applyBatchUpdate')
            ->with($table, range(1, 100), $data, 'id')
            ->once()
            ->andReturn(100);

        $this->bulkOperationModel->shouldReceive('applyBatchUpdate')
            ->with($table, range(101, 150), $data, 'id')
            ->once()
            ->andReturn(50);

        $result = $this->service->bulkUpdate($table, $ids, $data);

        $this->assertTrue($result['success']);
        $this->assertEquals(150, $result['data']['updated']);
    }

    /** @test */
    public function bulk_soft_delete_uses_bulk_update(): void
    {
        $table = 'banners';
        $ids = [5, 10];

        $this->bulkOperationModel->shouldReceive('applyBatchUpdate')
            ->with($table, $ids, m::type('array'), 'id')
            ->once()
            ->andReturn(2);

        $result = $this->service->bulkSoftDelete($table, $ids);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['data']['updated']);
    }

    /** @test */
    public function bulk_hard_delete_verifies_confirmation(): void
    {
        $this->expectException(\Core\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('نیاز به تأیید دارد');

        $this->service->bulkHardDelete('users', [1, 2], 'id', false); // false = not confirmed
    }

    /** @test */
    public function clear_cache_scans_and_deletes_keys_correctly(): void
    {
        // Set cache driver to redis
        $this->cache->shouldReceive('driver')->once()->andReturn('redis');

        $redisMock = m::mock('\Redis');
        $redisMock->shouldReceive('scanKeys')->with('*')->once()->andReturn(['key1', 'key2']);
        $redisMock->shouldReceive('del')->with(['key1', 'key2'])->once();

        $this->cache->shouldReceive('redis')->once()->andReturn($redisMock);

        $this->service->clearCache();
    }
}
