<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Models\InteractionModel;
use App\Models\MessageModerationModel;
use App\Services\AuditTrail;
use App\Services\MessageModerationService;
use Core\Cache;
use Core\Database;
use Core\EventDispatcher;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class MessageModerationBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_report_listing_combines_items_and_count_from_models(): void
    {
        $interaction=m::mock(InteractionModel::class);
        $interaction->shouldReceive('findMessageReportsPaginated')->once()->with(20,40,'pending')->andReturn([(object)['id'=>1]]);
        $interaction->shouldReceive('countMessageReports')->once()->with('pending')->andReturn(81);
        $result=$this->service($interaction,m::mock(MessageModerationModel::class))->getReports('pending',20,40);
        $this->assertSame(81,$result['total']);
        $this->assertSame(1,$result['reports'][0]->id);
    }

    public function test_blocked_user_output_redacts_email_mobile_identity_and_ip(): void
    {
        $moderation=m::mock(MessageModerationModel::class);
        $moderation->shouldReceive('getBlockedUsers')->once()->with(10,0)->andReturn([[
            'blocked_email'=>'sensitive@example.test','mobile'=>'09121234567','national_id'=>'1234567890','ip_address'=>'203.0.113.77',
        ]]);
        $row=$this->service(m::mock(InteractionModel::class),$moderation)->getBlockedUsers(10,0)[0];
        $this->assertSame('se***@example.test',$row['blocked_email']);
        $this->assertSame('091***67',$row['mobile']);
        $this->assertSame('***890',$row['national_id']);
        $this->assertSame('203.0.113.***',$row['ip_address']);
    }

    private function service(InteractionModel $interaction,MessageModerationModel $moderation): MessageModerationService
    {
        return new MessageModerationService(
            $this->lenientMock(EventDispatcher::class),$this->lenientMock(Cache::class),
            $this->lenientMock(Database::class),$this->lenientMock(LoggerInterface::class),
            $interaction,$moderation,$this->lenientMock(AuditTrail::class)
        );
    }
}
