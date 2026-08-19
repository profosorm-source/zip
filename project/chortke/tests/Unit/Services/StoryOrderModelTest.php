<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Models\StoryOrder;

/**
 * تست‌های حرفه‌ای برای مدل StoryOrder
 *
 * پوشش:
 *  - قراردادهای خروجی (statusLabels / statusClasses / generateVerificationCode)
 *  - رفتار پایگاه‌داده (find، createStoryOrder، لیست‌ها، شمارنده‌ها)
 *  - لبه‌ها (record پیدا نشد، insert ناموفق، لیست خالی)
 *  - قرارداد امنیتی whitelist ستون‌ها در update (جلوگیری از تزریق ستون)
 */
class StoryOrderModelTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeModel(?Database $db = null): StoryOrder
    {
        return new StoryOrder($db ?? m::mock(Database::class));
    }

    /** @test */
    public function status_labels_cover_every_domain_status(): void
    {
        $labels = $this->makeModel()->statusLabels();

        $expected = [
            'pending_payment', 'pending_acceptance', 'paid', 'accepted',
            'rejected_by_influencer', 'published', 'proof_submitted',
            'awaiting_buyer_check', 'peer_resolution', 'escalated_to_admin',
            'verified', 'rejected', 'disputed', 'completed', 'cancelled',
            'refunded', 'expired',
        ];

        foreach ($expected as $status) {
            $this->assertArrayHasKey($status, $labels, "label for {$status} is missing");
            $this->assertNotEmpty($labels[$status], "label for {$status} is empty");
        }
        // هیچ برچسب اضافی‌ای نباید وجود داشته باشد (قرارداد بسته)
        $this->assertCount(count($expected), $labels);
    }

    /** @test */
    public function every_status_has_a_non_empty_bootstrap_class(): void
    {
        $model = $this->makeModel();
        $labels = $model->statusLabels();
        $classes = $model->statusClasses();

        foreach (array_keys($labels) as $status) {
            $this->assertArrayHasKey($status, $classes, "class for {$status} is missing");
            $this->assertStringStartsWith('badge-', $classes[$status], "class for {$status}");
        }
    }

    /** @test */
    public function generate_verification_code_has_contract_format_and_is_unique(): void
    {
        $model = $this->makeModel();
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $code = $model->generateVerificationCode();
            $this->assertRegExp('/^CK-[0-9A-F]{6}$/', $code, 'invalid code format');
            $seen[$code] = true;
        }

        // با 100 نمونه، تقریباً قطعاً بیش از 10 مقدار یکتا تولید می‌شود
        $this->assertGreaterThan(10, count($seen), 'codes are not unique enough');
    }

    /** @test */
    public function find_returns_normalized_std_class_object(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([42]);
        $stmt->shouldReceive('fetch')->once()->with(\PDO::FETCH_OBJ)
            ->andReturn((object)['id' => 42, 'status' => 'paid']);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $order = $this->makeModel($db)->find(42);

        $this->assertInstanceOf(\stdClass::class, $order);
        $this->assertSame(42, (int)$order->id);
        $this->assertSame('paid', $order->status);
    }

    /** @test */
    public function find_returns_null_when_record_missing(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([99]);
        $stmt->shouldReceive('fetch')->once()->with(\PDO::FETCH_OBJ)->andReturn(false);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $this->assertNull($this->makeModel($db)->find(99));
    }

    /** @test */
    public function create_story_order_returns_created_record_on_success(): void
    {
        // INSERT
        $insertStmt = m::mock(\PDOStatement::class);
        $insertStmt->shouldReceive('execute')->once()->andReturn(true);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($insertStmt);
        $db->shouldReceive('lastInsertId')->once()->andReturn(7);

        // find(7)
        $findStmt = m::mock(\PDOStatement::class);
        $findStmt->shouldReceive('execute')->once()->with([7]);
        $findStmt->shouldReceive('fetch')->once()->with(\PDO::FETCH_OBJ)
            ->andReturn((object)['id' => 7, 'status' => 'pending_payment']);
        $db->shouldReceive('prepare')->once()->andReturn($findStmt);

        $order = $this->makeModel($db)->createStoryOrder([
            'customer_id'        => 1,
            'influencer_id'      => 2,
            'influencer_user_id' => 3,
            'order_type'         => 'story',
            'verification_code'  => 'CK-ABC123',
            'price'              => 100,
            'idempotency_key'    => 'k',
        ]);

        $this->assertInstanceOf(\stdClass::class, $order);
        $this->assertSame(7, (int)$order->id);
        $this->assertSame('pending_payment', $order->status);
    }

    /** @test */
    public function create_story_order_returns_null_when_insert_fails(): void
    {
        $insertStmt = m::mock(\PDOStatement::class);
        $insertStmt->shouldReceive('execute')->once()->andReturn(false);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($insertStmt);

        $this->assertNull($this->makeModel($db)->createStoryOrder([
            'customer_id'        => 1,
            'influencer_id'      => 2,
            'influencer_user_id' => 3,
            'verification_code'  => 'CK-ABC123',
            'price'              => 100,
            'idempotency_key'    => 'k',
        ]));
    }

    /** @test */
    public function get_by_customer_returns_list_of_std_class_records(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('bindValue')->times(3);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetchAll')->once()->with(\PDO::FETCH_OBJ)
            ->andReturn([(object)['id' => 1], (object)['id' => 2]]);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $rows = $this->makeModel($db)->getByCustomer(1, null, 20, 0);

        $this->assertCount(2, $rows);
        $this->assertInstanceOf(\stdClass::class, $rows[0]);
        $this->assertSame(1, (int)$rows[0]->id);
    }

    /** @test */
    public function count_pending_for_influencer_returns_integer(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([5]);
        $stmt->shouldReceive('fetchColumn')->once()->andReturn('3');

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $this->assertSame(3, $this->makeModel($db)->countPendingForInfluencer(5));
    }

    /** @test */
    public function get_expired_buyer_checks_returns_list(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetchAll')->once()->with(\PDO::FETCH_OBJ)
            ->andReturn([(object)['id' => 11], (object)['id' => 12]]);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $rows = $this->makeModel($db)->getExpiredBuyerChecks();
        $this->assertCount(2, $rows);
        $this->assertSame(11, (int)$rows[0]->id);
    }

    /** @test */
    public function update_excludes_non_whitelisted_columns(): void
    {
        // قرارداد امنیتی: فقط ستون‌های مجاز باید به SQL راه پیدا کنند؛
        // ستونِ جعلی نباید هرگز در کوئری ظاهر شود (جلوگیری از column injection).
        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')
            ->once()
            ->with(m::on(function (string $sql) {
                $this->assertStringNotContainsString('malicious_column', $sql);
                $this->assertStringContainsString('status = ?', $sql);
                $this->assertStringContainsString('updated_at = NOW()', $sql);
                return true;
            }))
            ->andReturnUsing(function () {
                $stmt = m::mock(\PDOStatement::class);
                $stmt->shouldReceive('execute')->once()->andReturn(true);
                return $stmt;
            });

        $ok = $this->makeModel($db)->update(7, [
            'status'            => 'paid',
            'malicious_column'  => 'DROP TABLE story_orders',
        ]);

        $this->assertTrue($ok);
    }

    /** @test */
    public function update_returns_false_when_no_whitelisted_field_present(): void
    {
        $db = m::mock(Database::class);
        $db->shouldNotReceive('prepare');

        $this->assertFalse($this->makeModel($db)->update(7, ['not_allowed_field' => 'x']));
    }

    /** @test */
    public function update_returns_execute_result(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->andReturn(false);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $this->assertFalse($this->makeModel($db)->update(7, ['status' => 'paid']));
    }
}
