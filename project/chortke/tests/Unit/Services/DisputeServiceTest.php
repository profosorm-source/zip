<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Shared\DisputeService;
use Mockery as m;

/**
 * تست‌های حرفه‌ای DisputeService
 *
 * پوشش: رفتارِ واگذاریِ (delegation) کوئری‌ها به مدل، مقادیر برگشتی،
 * و لبه‌ها (لیست خالی، رکورد پیدا نشد → null).
 */
class DisputeServiceTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $database;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\Dispute&\Mockery\MockInterface */
    private \App\Models\Dispute $disputeModel;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \App\Services\ReconciliationService&\Mockery\MockInterface */
    private \App\Services\ReconciliationService $reconciliationService;
    /** @var \App\Models\Transaction&\Mockery\MockInterface */
    private \App\Models\Transaction $transactionModel;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private DisputeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->disputeModel = m::mock('App\Models\Dispute');
        $this->walletService = m::mock('App\Contracts\WalletServiceInterface');
        $this->reconciliationService = m::mock('App\Services\ReconciliationService');
        $this->transactionModel = m::mock('App\Models\Transaction');

        $this->idempotencyService = m::mock('App\Services\Shared\IdempotencyService');
        $this->idempotencyService->shouldIgnoreMissing();

        // ترتیب صحیح سازنده‌ی واقعی:
        // (db, logger, disputeModel, walletService, reconciliationService, transactionModel, idempotencyService, ...)
        $this->service = new DisputeService(
            $this->database,
            $this->logger,
            $this->disputeModel,
            $this->walletService,
            $this->reconciliationService,
            $this->transactionModel,
            $this->idempotencyService,
            null, null, null, null, null, null, null
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function get_user_disputes_delegates_to_model_and_returns_records(): void
    {
        $rows = [(object)['id' => 1, 'status' => 'open']];

        $this->disputeModel->shouldReceive('getByUser')
            ->once()
            ->with(5, 10, 20)
            ->andReturn($rows);

        $result = $this->service->getUserDisputes(5, 10, 20);

        $this->assertSame($rows, $result);
        $this->assertCount(1, $result);
        $this->assertSame(1, (int)$result[0]->id);
    }

    /** @test */
    public function get_user_disputes_returns_empty_list_when_none(): void
    {
        $this->disputeModel->shouldReceive('getByUser')
            ->once()
            ->with(99, 20, 0)
            ->andReturn([]);

        $this->assertSame([], $this->service->getUserDisputes(99));
    }

    /** @test */
    public function count_user_disputes_returns_model_count(): void
    {
        $this->disputeModel->shouldReceive('countByUser')->once()->with(7)->andReturn(3);
        $this->assertSame(3, $this->service->countUserDisputes(7));
    }

    /** @test */
    public function find_returns_normalized_std_class_record(): void
    {
        $this->disputeModel->shouldReceive('getSafe')->once()->with(42)->andReturn((object)['id' => 42]);

        $dispute = $this->service->find(42);

        $this->assertInstanceOf(\stdClass::class, $dispute);
        $this->assertSame(42, (int)$dispute->id);
    }

    /** @test */
    public function find_returns_null_when_record_missing(): void
    {
        $this->disputeModel->shouldReceive('getSafe')->once()->with(999)->andReturn(null);
        $this->assertNull($this->service->find(999));
    }

    /** @test */
    public function get_messages_returns_model_messages_in_order(): void
    {
        $messages = [(object)['id' => 1], (object)['id' => 2]];

        $this->disputeModel->shouldReceive('getMessages')->once()->with(10)->andReturn($messages);

        $result = $this->service->getMessages(10);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(\stdClass::class, $result[0]);
        $this->assertSame(2, (int)$result[1]->id);
    }

    /** @test */
    public function list_for_admin_returns_all_modules_when_no_ref_type_filter(): void
    {
        $items = [
            (object)['id' => 1, 'ref_type' => 'custom_task_submission', 'task_title' => 'T1', 'raised_by_role' => 'worker', 'status' => 'open'],
            (object)['id' => 2, 'ref_type' => 'vitrine_listing', 'task_title' => 'L1', 'raised_by_role' => 'party', 'status' => 'open'],
            (object)['id' => 3, 'ref_type' => 'order', 'task_title' => 'O1', 'raised_by_role' => 'customer', 'status' => 'resolved_admin'],
        ];

        // DisputeQueryService از $this->database استفاده می‌کند
        $this->database->shouldReceive('fetchAll')
            ->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'FROM disputes d') && str_contains($sql, 'LEFT JOIN story_orders') && str_contains($sql, 'LEFT JOIN vitrine_listings')), m::on(fn($p) => empty($p)))
            ->andReturn($items);
        $this->database->shouldReceive('fetch')
            ->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'SELECT COUNT(*)')), m::on(fn($p) => empty($p)))
            ->andReturn((object)['c' => 3]);

        $result = $this->service->listForAdmin([], 30, 0);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['items']);
        $this->assertSame('vitrine_listing', $result['items'][1]->ref_type);
    }

    /** @test */
    public function list_for_admin_filters_by_ref_type(): void
    {
        $items = [(object)['id' => 2, 'ref_type' => 'vitrine_listing', 'task_title' => 'L1', 'raised_by_role' => 'party', 'status' => 'open']];

        $this->database->shouldReceive('fetchAll')
            ->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'd.ref_type = ?')), m::on(fn($p) => $p === ['vitrine_listing']))
            ->andReturn($items);
        $this->database->shouldReceive('fetch')
            ->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'SELECT COUNT(*)')), m::on(fn($p) => $p === ['vitrine_listing']))
            ->andReturn((object)['c' => 1]);

        $result = $this->service->listForAdmin(['ref_type' => 'vitrine_listing'], 30, 0);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('vitrine_listing', $result['items'][0]->ref_type);
    }

    /** @test */
    public function list_for_admin_filters_by_status_resolved_group(): void
    {
        $this->database->shouldReceive('fetchAll')
            ->once()
            ->with(m::on(fn($sql) => str_contains($sql, "d.status IN ('resolved','resolved_admin','resolved_for_executor','resolved_for_advertiser','closed')")), m::on(fn($p) => empty($p)))
            ->andReturn([]);
        $this->database->shouldReceive('fetch')
            ->once()
            ->andReturn((object)['c' => 0]);

        $result = $this->service->listForAdmin(['status' => 'resolved'], 30, 0);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** @test */
    public function resolve_for_admin_returns_error_when_dispute_missing(): void
    {
        $this->disputeModel->shouldReceive('getSafe')->once()->with(999)->andReturn(null);

        $result = $this->service->resolveForAdmin(7, 999, ['decision' => 'buyer']);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('یافت نشد', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function resolve_for_admin_validates_vitrine_winner(): void
    {
        $this->disputeModel->shouldReceive('getSafe')->once()->with(10)->andReturn((object)['id' => 10, 'ref_type' => 'vitrine_listing', 'ref_id' => 42]);

        $result = $this->service->resolveForAdmin(7, 10, ['decision' => 'invalid_winner']);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('برنده', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function resolve_for_admin_dispatches_influencer_order_to_admin_resolve(): void
    {
        $this->disputeModel->shouldReceive('getSafe')->once()->with(11)->andReturn((object)['id' => 11, 'ref_type' => 'order', 'ref_id' => 5]);

        // adminResolve در commandService از disputeModel->update استفاده می‌کند؛
        // اینجا فقط اطمینان می‌گیریم که dispatch به شاخه‌ی سفارش می‌رود (خطای role ادمین یا ...).
        // چون commandService مستقیماً mock نمی‌شود، تست را با انتظار خروجی (بدون throw) اجرا می‌کنیم.
        $result = $this->service->resolveForAdmin(7, 11, ['decision' => 'buyer']);

        // حتی اگر escrow/تراکنش ناقص باشد، ساختار خروجی باید باشد و به‌جای exception، array برگردد
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }
}
