<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Withdrawal\WithdrawalQueryService;
use Core\Database;
use App\Models\Withdrawal as WithdrawalModel;
use App\Contracts\LoggerInterface;

class WithdrawalQueryServiceTest extends TestCase
{
    public function test_hasPendingWithdrawal_calls_model(): void
    {
        $db = $this->createMock(Database::class);
        $model = $this->createMock(WithdrawalModel::class);
        $appSettings = $this->createMock(\App\Services\Settings\AppSettings::class);

        $svc = new WithdrawalQueryService($db, $model, $appSettings);

        $model->expects($this->once())
            ->method('hasPendingWithdrawal')
            ->with(123, false)
            ->willReturn(true);

        $this->assertTrue($svc->hasPendingWithdrawal(123));
    }

    public function test_getLimitsForUser_returns_expected_keys(): void
    {
        $db = $this->createMock(Database::class);
        $model = $this->createMock(WithdrawalModel::class);
        $appSettings = $this->createMock(\App\Services\Settings\AppSettings::class);

        $db->method('selectOne')->willReturn((object)['used_today' => '1000']);
        $appSettings->method('get')->willReturnCallback(fn() => '0');

        $svc = new WithdrawalQueryService($db, $model, $appSettings);
        $res = $svc->getLimitsForUser(1, 'IRT');

        $this->assertIsArray($res);
        $this->assertArrayHasKey('daily_limit', $res);
        $this->assertArrayHasKey('used_today', $res);
        $this->assertArrayHasKey('remaining_limit', $res);
    }
}
