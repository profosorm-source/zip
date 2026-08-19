<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Shared\BatchLoader;
use Core\Database;
use Mockery as m;

/**
 * تست‌های واحد BatchLoader
 *
 * پوشش:
 *  - byIds: حالت عادی، empty input، null filtering، deduplication
 *  - byQuery: compound key، empty params
 *  - existingIds: بررسی وجود، empty input
 *  - aggregate: IN query با extra params، empty in-values
 *  - sanitizeIdentifier: جلوگیری از SQL Injection
 */
class BatchLoaderTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────
    // byIds
    // ──────────────────────────────────────────────────────────────

    public function test_byIds_returns_empty_on_empty_input(): void
    {
        // نباید هیچ query ارسال شود
        $this->db->shouldNotReceive('fetchAll');

        $result = BatchLoader::byIds($this->db, 'users', []);
        $this->assertSame([], $result);
    }

    public function test_byIds_returns_empty_when_all_ids_are_null(): void
    {
        $this->db->shouldNotReceive('fetchAll');

        $result = BatchLoader::byIds($this->db, 'users', [null, null, '']);
        $this->assertSame([], $result);
    }

    public function test_byIds_deduplicates_ids(): void
    {
        // [1, 1, 2] → باید فقط با [1, 2] query بزند
        $row1 = (object)['id' => 1, 'name' => 'Alice'];
        $row2 = (object)['id' => 2, 'name' => 'Bob'];

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with(
                'SELECT * FROM `users` WHERE `id` IN (?,?)',
                [1, 2]
            )
            ->andReturn([$row1, $row2]);

        $result = BatchLoader::byIds($this->db, 'users', [1, 1, 2]);

        $this->assertCount(2, $result);
        $this->assertSame($row1, $result[1]);
        $this->assertSame($row2, $result[2]);
    }

    public function test_byIds_indexes_by_key_column(): void
    {
        $row = (object)['id' => 42, 'email' => 'test@example.com'];

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->andReturn([$row]);

        $result = BatchLoader::byIds($this->db, 'users', [42]);

        $this->assertArrayHasKey(42, $result);
        $this->assertSame($row, $result[42]);
    }

    public function test_byIds_uses_custom_key_column(): void
    {
        $row = (object)['wallet_id' => 7, 'balance' => '1000.00'];

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with(
                'SELECT * FROM `wallets` WHERE `wallet_id` IN (?)',
                [7]
            )
            ->andReturn([$row]);

        $result = BatchLoader::byIds($this->db, 'wallets', [7], 'wallet_id');

        $this->assertArrayHasKey(7, $result);
        $this->assertSame($row, $result[7]);
    }

    public function test_byIds_returns_empty_map_when_db_returns_empty_array(): void
    {
        $this->db->shouldReceive('fetchAll')
            ->once()
            ->andReturn([]);

        $result = BatchLoader::byIds($this->db, 'users', [1, 2, 3]);
        $this->assertSame([], $result);
    }

    public function test_byIds_skips_rows_without_key(): void
    {
        $rowValid   = (object)['id' => 5, 'name' => 'Valid'];
        $rowInvalid = (object)['name' => 'No ID'];   // فاقد id

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->andReturn([$rowValid, $rowInvalid]);

        $result = BatchLoader::byIds($this->db, 'users', [5]);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey(5, $result);
    }

    public function test_byIds_throws_on_invalid_table_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL identifier/');

        BatchLoader::byIds($this->db, 'users; DROP TABLE users--', [1]);
    }

    public function test_byIds_throws_on_invalid_key_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BatchLoader::byIds($this->db, 'users', [1], 'id; DROP TABLE users--');
    }

    public function test_byIds_allows_dot_notation_identifier(): void
    {
        // table.column باید مجاز باشد
        $row = (object)['id' => 1];
        $this->db->shouldReceive('fetchAll')->once()->andReturn([$row]);

        $result = BatchLoader::byIds($this->db, 'schema.users', [1]);
        $this->assertCount(1, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // byQuery
    // ──────────────────────────────────────────────────────────────

    public function test_byQuery_returns_empty_on_empty_params(): void
    {
        $this->db->shouldNotReceive('fetchAll');

        $result = BatchLoader::byQuery($this->db, 'SELECT * FROM wallets WHERE id IN (?)', [], fn($r) => $r->id);
        $this->assertSame([], $result);
    }

    public function test_byQuery_builds_map_via_keyFn(): void
    {
        $row1 = (object)['user_id' => 1, 'currency' => 'irt', 'net' => '500'];
        $row2 = (object)['user_id' => 2, 'currency' => 'irt', 'net' => '300'];

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->andReturn([$row1, $row2]);

        $result = BatchLoader::byQuery(
            $this->db,
            'SELECT user_id, currency, SUM(amount) AS net FROM wallets WHERE user_id IN (?,?) GROUP BY user_id, currency',
            [1, 2],
            fn($r) => "{$r->user_id}:{$r->currency}"
        );

        $this->assertArrayHasKey('1:irt', $result);
        $this->assertArrayHasKey('2:irt', $result);
        $this->assertSame($row1, $result['1:irt']);
    }

    public function test_byQuery_returns_empty_map_when_db_returns_empty_array(): void
    {
        $this->db->shouldReceive('fetchAll')->once()->andReturn([]);

        $result = BatchLoader::byQuery($this->db, 'SELECT 1', [1], fn($r) => $r->id ?? 0);
        $this->assertSame([], $result);
    }

    // ──────────────────────────────────────────────────────────────
    // existingIds
    // ──────────────────────────────────────────────────────────────

    public function test_existingIds_returns_empty_on_empty_input(): void
    {
        $this->db->shouldNotReceive('fetchAll');

        $result = BatchLoader::existingIds($this->db, 'users', []);
        $this->assertSame([], $result);
    }

    public function test_existingIds_returns_ids_that_exist(): void
    {
        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with(
                'SELECT `id` FROM `users` WHERE `id` IN (?,?,?)',
                [1, 2, 3]
            )
            ->andReturn([(object)['id' => 1], (object)['id' => 3]]);

        $result = BatchLoader::existingIds($this->db, 'users', [1, 2, 3]);
        $this->assertCount(2, $result);
        $this->assertContains(1, $result);
        $this->assertContains(3, $result);
        $this->assertNotContains(2, $result);
    }

    public function test_existingIds_deduplicates_input(): void
    {
        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with(
                'SELECT `id` FROM `users` WHERE `id` IN (?,?)',
                [5, 6]
            )
            ->andReturn([(object)['id' => 5]]);

        $result = BatchLoader::existingIds($this->db, 'users', [5, 5, 6, 6]);
        $this->assertCount(1, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // aggregate
    // ──────────────────────────────────────────────────────────────

    public function test_aggregate_returns_empty_on_empty_inValues(): void
    {
        $this->db->shouldNotReceive('fetchAll');

        $result = BatchLoader::aggregate($this->db, 'SELECT %s', [], [], fn($r) => $r->id);
        $this->assertSame([], $result);
    }

    public function test_aggregate_builds_correct_query(): void
    {
        $row = (object)['account' => 'acc1', 'currency' => 'irt', 'net' => '1000.00'];

        $expectedQuery = 'SELECT account, SUM(debit)-SUM(credit) AS net FROM ledger WHERE account IN (?,?) AND currency = ? GROUP BY account';

        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with($expectedQuery, ['acc1', 'acc2', 'irt'])
            ->andReturn([$row]);

        $result = BatchLoader::aggregate(
            $this->db,
            'SELECT account, SUM(debit)-SUM(credit) AS net FROM ledger WHERE account IN (%s) AND currency = ? GROUP BY account',
            ['acc1', 'acc2'],
            ['irt'],
            fn($r) => "{$r->account}:{$r->currency}"
        );

        $this->assertArrayHasKey('acc1:irt', $result);
        $this->assertSame($row, $result['acc1:irt']);
    }

    public function test_aggregate_deduplicates_in_values(): void
    {
        $this->db->shouldReceive('fetchAll')
            ->once()
            ->with(
                m::pattern('/IN \(\?,\?\)/'),
                ['a', 'b', 'extra']
            )
            ->andReturn([]);

        $result = BatchLoader::aggregate(
            $this->db,
            'SELECT col FROM t WHERE col IN (%s) AND x = ?',
            ['a', 'a', 'b'],
            ['extra'],
            fn($r) => $r->col ?? ''
        );

        // deduplication: ['a','a','b'] → ['a','b'] → IN (?,?)
        $this->assertSame([], $result, 'نتیجه خالی باید باشد چون mock [] برمیگرداند');
    }

    public function test_aggregate_returns_empty_map_when_db_returns_empty_array(): void
    {
        $this->db->shouldReceive('fetchAll')->once()->andReturn([]);

        $result = BatchLoader::aggregate($this->db, 'SELECT col FROM t WHERE col IN (%s)', ['x'], [], fn($r) => $r->col ?? '');
        $this->assertSame([], $result);
    }

    // ──────────────────────────────────────────────────────────────
    // sanitizeIdentifier — boundary tests
    // ──────────────────────────────────────────────────────────────

    public function test_sanitizeIdentifier_allows_valid_identifiers(): void
    {
        // اگر identifier معتبر باشد، byIds باید کار کند
        $row = (object)['my_col_1' => 99];
        $this->db->shouldReceive('fetchAll')->once()->andReturn([$row]);

        $result = BatchLoader::byIds($this->db, 'my_table_1', [99], 'my_col_1');
        $this->assertArrayHasKey(99, $result);
    }

    public function test_sanitizeIdentifier_rejects_special_chars(): void
    {
        $invalidIdentifiers = [
            "users'; DROP TABLE users--",
            "users UNION SELECT",
            "users`",
            "table name",
            "",
        ];

        foreach ($invalidIdentifiers as $identifier) {
            try {
                BatchLoader::byIds($this->db, $identifier, [1]);
                $this->fail("باید برای identifier='{$identifier}' exception پرتاب شود");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid SQL identifier', $e->getMessage());
            }
        }
    }
}
