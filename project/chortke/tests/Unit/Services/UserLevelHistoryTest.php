<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\UserLevelHistory;
use Core\Database;
use Mockery as m;

/**
 * تست حرفه‌ای مدل UserLevelHistory — رفتار، لبه‌ها و قراردادها.
 *
 * پوشش:
 *   - امضای دیجیتال: تولید/تأیید، ردِ امضای خالی، ردِ تغییر (tampering)
 *   - ثبت تاریخچه: اعتبارسنجی فیلدهای الزامی (to_level / change_type)
 *   - ثبت تاریخچه: بازگشت null هنگام شکست INSERT
 *   - ثبت تاریخچه: بازگشت ردیف تأییدشده در موفقیت (جریان کامل createHistory → find)
 */
class UserLevelHistoryTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    private UserLevelHistory $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->model = new UserLevelHistory($this->db);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_and_verify_signatures(): void
    {
        $metadata = \json_encode(['foo' => 'bar']);
        $this->assertIsString($metadata);
        $sig = $this->model->generateSignature(42, 'bronze', 'silver', 'system', 'test', $metadata, '127.0.0.1');

        $row = (object)[
            'user_id' => 42,
            'from_level' => 'bronze',
            'to_level' => 'silver',
            'change_type' => 'system',
            'reason' => 'test',
            'metadata' => \json_encode(['foo' => 'bar']),
            'ip_address' => '127.0.0.1',
            'signature' => $sig,
        ];

        $this->assertTrue($this->model->verifySignature($row));

        // Tamper with data → signature must fail
        $row->reason = 'tampered';
        $this->assertFalse($this->model->verifySignature($row));
    }

    /** @test */
    public function it_rejects_empty_or_missing_signature(): void
    {
        $row = (object)[
            'user_id' => 1,
            'from_level' => null,
            'to_level' => 'silver',
            'change_type' => 'purchase',
            'reason' => null,
            'metadata' => null,
            'ip_address' => '127.0.0.1',
            'signature' => '',
        ];

        $this->assertFalse($this->model->verifySignature($row));
    }

    /** @test */
    public function it_requires_non_empty_to_level_and_change_type(): void
    {
        // to_level empty
        try {
            $this->model->createHistory(['user_id' => 1, 'to_level' => '', 'change_type' => 'purchase']);
            $this->fail('Expected InvalidArgumentException for empty to_level');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('to_level', $e->getMessage());
        }

        // change_type empty
        try {
            $this->model->createHistory(['user_id' => 1, 'to_level' => 'silver', 'change_type' => '']);
            $this->fail('Expected InvalidArgumentException for empty change_type');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('change_type', $e->getMessage());
        }
    }

    /** @test */
    public function it_returns_null_when_insert_fails(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->andReturn(false);

        $this->db->shouldReceive('prepare')->once()->andReturn($stmt);

        $result = $this->model->createHistory([
            'user_id' => 7,
            'from_level' => 'bronze',
            'to_level' => 'silver',
            'change_type' => 'purchase',
            'reason' => 'test',
        ]);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_verified_row_on_successful_history_creation(): void
    {
        $userId = 7;
        $fromLevel = 'bronze';
        $toLevel = 'silver';
        $changeType = 'purchase';
        $reason = 'test';
        $ip = '127.0.0.1';

        $expectedSig = $this->model->generateSignature($userId, $fromLevel, $toLevel, $changeType, $reason, null, $ip);

        $insertStmt = m::mock(\PDOStatement::class);
        $insertStmt->shouldReceive('execute')->once()->andReturn(true);

        $selectStmt = m::mock(\PDOStatement::class);
        $selectStmt->shouldReceive('execute')->once()->andReturn(true);
        $selectStmt->shouldReceive('fetch')->once()->andReturn(
            (object)[
                'id' => 5,
                'user_id' => $userId,
                'from_level' => $fromLevel,
                'to_level' => $toLevel,
                'change_type' => $changeType,
                'reason' => $reason,
                'metadata' => null,
                'ip_address' => $ip,
                'signature' => $expectedSig,
            ]
        );

        $this->db->shouldReceive('prepare')
            ->twice()
            ->andReturn($insertStmt, $selectStmt);
        $this->db->shouldReceive('lastInsertId')->once()->andReturn(5);

        $row = $this->model->createHistory([
            'user_id' => $userId,
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'change_type' => $changeType,
            'reason' => $reason,
        ]);

        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame(5, $row->id);
        $this->assertTrue($row->is_valid);
    }
}
