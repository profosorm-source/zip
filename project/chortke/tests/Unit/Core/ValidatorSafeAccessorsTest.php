<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * تست رفتار/لبهی متدهای خواندنِ امنِ دادهی اعتبارسنجیشده در Validator.
 *
 * این متدها (int/float/str) نمایانگر «مرزِ شکلگیری داده» پس از اعتبارسنجی هستند:
 * Validator تضمین میکند مقدار از ruleها (مثلاً numeric/integer) گذشته، اما خودش
 * تبدیل نوع انجام نمیدهد؛ این متدها با guard امن (is_numeric/is_scalar) تبدیل را انجام
 * میدهند تا در محل مصرف cast کور نشود.
 */
class ValidatorSafeAccessorsTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $rules
     */
    private function make(array $data, array $rules = []): Validator
    {
        return new Validator($data, $rules);
    }

    // ─── int() ──────────────────────────────────────────────────

    public function testIntReturnsNumericValueAsInt(): void
    {
        $v = $this->make(['count' => '42', 'neg' => '-7', 'floaty' => '3.99']);
        $this->assertSame(42, $v->int('count'));
        $this->assertSame(-7, $v->int('neg'));
        $this->assertSame(3, $v->int('floaty')); // (int) truncates
    }

    public function testIntFallsBackOnInvalidOrMissing(): void
    {
        $v = $this->make(['bad' => '12abc', 'arr' => [1, 2]]);
        $this->assertSame(0, $v->int('bad'));
        $this->assertSame(0, $v->int('arr'));
        $this->assertSame(0, $v->int('missing'));
        $this->assertSame(99, $v->int('missing', 99));
    }

    // ─── float() ────────────────────────────────────────────────

    public function testFloatReturnsNumericValueAsFloat(): void
    {
        $v = $this->make(['price' => '12.75', 'intval' => '5', 'neg' => '-1.25']);
        $this->assertSame(12.75, $v->float('price'));
        $this->assertSame(5.0, $v->float('intval'));
        $this->assertSame(-1.25, $v->float('neg'));
    }

    public function testFloatFallsBackOnInvalidOrMissing(): void
    {
        $v = $this->make(['bad' => 'abc', 'arr' => [1]]);
        $this->assertSame(0.0, $v->float('bad'));
        $this->assertSame(0.0, $v->float('arr'));
        $this->assertSame(0.0, $v->float('missing'));
        $this->assertSame(7.5, $v->float('missing', 7.5));
    }

    // ─── str() ──────────────────────────────────────────────────

    public function testStrReturnsScalarAsString(): void
    {
        $v = $this->make(['name' => 'ali', 'age' => 28, 'flag' => 'on']);
        $this->assertSame('ali', $v->str('name'));
        $this->assertSame('28', $v->str('age'));
        $this->assertSame('on', $v->str('flag'));
    }

    public function testStrFallsBackOnNonScalarOrMissing(): void
    {
        $v = $this->make(['nested' => ['a' => 1]]);
        $this->assertSame('', $v->str('nested'));
        $this->assertSame('', $v->str('missing'));
        $this->assertSame('def', $v->str('missing', 'def'));
        // empty string preserved (not defaulted)
        $v2 = $this->make(['empty' => '']);
        $this->assertSame('', $v2->str('empty'));
    }

    // ─── نفوذ نکردن مقادیرِ ردشده در اعتبارسنجی ──────────────────

    public function testAccessorsReflectDataEvenWhenValidationFails(): void
    {
        // حتی وقتی validation fails، متدهای accessor همان دادهی خام را با guard برمیگردانند
        $v = $this->make(['price' => 'not-a-number'], ['price' => 'numeric']);
        $this->assertTrue($v->fails());
        $this->assertSame(0.0, $v->float('price'));
    }
}
