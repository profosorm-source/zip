<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Models\ContentAgreement;
use App\Models\ContentRevenue;
use App\Models\ContentSubmission;
use App\Services\ContentService;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\TransactionWrapper;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    private function createService(
        ?OutboxServiceInterface $outbox = null,
        ?ContentSubmission $submission = null,
        ?LoggerInterface $logger = null
    ): ContentService {
        $logger ??= m::mock(LoggerInterface::class);
        if ($logger instanceof m\MockInterface) $logger->shouldIgnoreMissing();

        return new ContentService(
            $logger,
            m::mock(Database::class),
            $submission ?? m::mock(ContentSubmission::class),
            m::mock(ContentRevenue::class),
            m::mock(ContentAgreement::class),
            m::mock(TransactionWrapper::class),
            m::mock(AppSettings::class),
            $outbox
        );
    }

    public function test_service_instantiates_with_outbox(): void
    {
        $service = $this->createService(m::mock(OutboxServiceInterface::class));
        $this->assertInstanceOf(ContentService::class, $service);
    }

    public function test_service_instantiates_without_outbox(): void
    {
        $this->assertInstanceOf(ContentService::class, $this->createService());
    }

    public function test_approve_submission_approves_pending_and_records_outbox(): void
    {
        $outbox = m::mock(OutboxServiceInterface::class);
        $submission = m::mock(ContentSubmission::class);
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once();
        $logger->shouldReceive('error')->never();
        $submission->shouldReceive('find')->once()->with(10)->andReturn((object)[
            'id' => 10, 'user_id' => 5, 'status' => ContentSubmission::STATUS_PENDING,
        ]);
        $submission->shouldReceive('update')->once()->with(10, m::on(static function (array $data): bool {
            return ($data['status'] ?? '') === ContentSubmission::STATUS_APPROVED && isset($data['approved_by']);
        }))->andReturn(true);
        $outbox->shouldReceive('record')->once()->with('content', 10, 'content.approved', m::on(static function (array $context): bool {
            return ($context['user_id'] ?? null) === 5;
        }));

        $result = $this->createService($outbox, $submission, $logger)->approveSubmission(10, 3);
        $this->assertTrue($result['success']);
    }

    public function test_approve_submission_rejects_when_status_not_approvable(): void
    {
        $submission = m::mock(ContentSubmission::class);
        $submission->shouldReceive('find')->once()->with(10)->andReturn((object)[
            'id' => 10, 'user_id' => 5, 'status' => ContentSubmission::STATUS_REJECTED,
        ]);

        $this->expectException(\Core\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('وضعیت محتوا اجازه تأیید را نمی‌دهد.');
        $this->createService(null, $submission)->approveSubmission(10, 3);
    }

    public function test_approve_submission_throws_when_submission_missing(): void
    {
        $submission = m::mock(ContentSubmission::class);
        $submission->shouldReceive('find')->once()->with(999)->andReturn(null);

        $this->expectException(\Core\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('محتوا یافت نشد.');
        $this->createService(null, $submission)->approveSubmission(999, 3);
    }
}
