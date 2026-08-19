<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * تست رفتار/لبهی توابع تبدیل امن (جایگزینِ cast کور در PHPStan Level 9).
 *
 * این توابع بهعنوان «مرز شکلگیری داده» عمل میکنند: هرگاه مقدار از منبعی
 * `mixed` (request/session/config/json_decode) وارد میشود، در همین مرز با
 * guard ایزوله میشود تا در محلهای مصرف cast کور نشود.
 */
class SafeValueHelpersTest extends TestCase
{
    // ─── str_value ────────────────────────────────────────────────

    public function testStrValueKeepsScalarsAsIs(): void
    {
        $this->assertSame('ali', str_value('ali'));
        $this->assertSame('123', str_value(123));
        $this->assertSame('1.5', str_value(1.5));
        $this->assertSame('1', str_value(1, 'fallback'));
    }

    public function testStrValueFallsBackOnNonScalarAndNull(): void
    {
        $this->assertSame('', str_value(null));
        $this->assertSame('d', str_value([1, 2], 'd'));
        $this->assertSame('d', str_value(new \stdClass(), 'd'));
        $this->assertSame('d', str_value([], 'd'));
    }

    // ─── int_value ────────────────────────────────────────────────

    public function testIntValueConvertsNumeric(): void
    {
        $this->assertSame(42, int_value(42));
        $this->assertSame(42, int_value('42'));
        $this->assertSame(-7, int_value('-7'));
        $this->assertSame(3, int_value('3.99')); // (int) truncates
    }

    public function testIntValueRejectsNonNumericAndNonScalar(): void
    {
        $this->assertSame(0, int_value('12abc'));
        $this->assertSame(0, int_value([]));
        $this->assertSame(0, int_value(null));
        $this->assertSame(0, int_value(new \stdClass()));
        $this->assertSame(9, int_value('nope', 9));
    }

    // ─── float_value ──────────────────────────────────────────────

    public function testFloatValueConvertsNumeric(): void
    {
        $this->assertSame(12.75, float_value('12.75'));
        $this->assertSame(5.0, float_value('5'));
        $this->assertSame(-1.25, float_value('-1.25'));
        $this->assertSame(2.0, float_value(2));
    }

    public function testFloatValueRejectsNonNumericAndNonScalar(): void
    {
        $this->assertSame(0.0, float_value('abc'));
        $this->assertSame(0.0, float_value([]));
        $this->assertSame(0.0, float_value(null));
        $this->assertSame(7.5, float_value('x', 7.5));
    }

    // ─── boolean edge ─────────────────────────────────────────────

    public function testBoolIsNotTreatedAsNumericForIntFloat(): void
    {
        // is_numeric(true/false) is false in PHP 8.x → must not cast to 1/0
        $this->assertSame(0, int_value(true));
        $this->assertSame(0, int_value(false));
        $this->assertSame(0.0, float_value(true));
        // but is_scalar(true) is true → str_value stringifies
        $this->assertSame('1', str_value(true));
        $this->assertSame('', str_value(false));
    }
}
