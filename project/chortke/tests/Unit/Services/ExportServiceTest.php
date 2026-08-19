<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Models\ExportData;
use Core\Session;
use App\Services\Shared\PolicyService;
use Core\RateLimiter;
use App\Services\ExportService;

class ExportServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(?ExportData $exportData = null): ExportService
    {
        return new ExportService(
            $exportData ?? m::mock(ExportData::class),
            m::mock(Session::class),
            m::mock(PolicyService::class),
            m::mock(RateLimiter::class)
        );
    }

    /** @test */
    public function prepare_users_export_builds_headers_and_rows(): void
    {
        $user = (object)[
            'id' => 1,
            'full_name' => 'Ali',
            'email' => 'ali@example.com',
            'mobile' => '0912',
            'level_slug' => 'gold',
            'status' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'last_login' => '2026-01-02',
            'balance_irt' => '1000',
            'balance_usdt' => '5.5',
        ];

        $exportData = m::mock(ExportData::class);
        $exportData->shouldReceive('getUsers')->once()->with(null, null)->andReturn([$user]);

        $service = $this->makeService($exportData);
        $result = $service->prepareUsersExport([]);

        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['rows'][0][0]);
        $this->assertSame('Ali', $result['rows'][0][1]);
        // وضعیت 1 => 'فعال'
        $this->assertSame('فعال', $result['rows'][0][5]);
    }

    /** @test */
    public function prepare_users_export_handles_missing_optional_fields(): void
    {
        $user = (object)[
            'id' => 2,
            'full_name' => 'Sara',
            'email' => 'sara@example.com',
            'status' => 99, // unknown status
            'created_at' => '2026-01-01',
            'balance_irt' => 0,
            'balance_usdt' => 0,
        ];

        $exportData = m::mock(ExportData::class);
        $exportData->shouldReceive('getUsers')->once()->with('2026-01-01', '2026-01-31')->andReturn([$user]);

        $service = $this->makeService($exportData);
        $result = $service->prepareUsersExport([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $result['rows']);
        // mobile نبود => '' و level نبود => 'silver' و status ناشناخته => 'نامشخص'
        $this->assertSame('', $result['rows'][0][3]);
        $this->assertSame('silver', $result['rows'][0][4]);
        $this->assertSame('نامشخص', $result['rows'][0][5]);
    }
}
