<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\UnifiedTaskService;
use Mockery as m;

class UnifiedTaskServiceTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $database;
    private UnifiedTaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = m::mock('Core\Database');
        
        $this->service = new UnifiedTaskService($this->database);
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(UnifiedTaskService::class, $this->service);
    }

    /** @test */
    public function service_has_required_methods(): void
    {
        $methods = ['getExecutorStats'];
        
        foreach ($methods as $method) {
            $this->assertTrue(method_exists($this->service, $method), "Method {$method} not found");
        }
    }
}
