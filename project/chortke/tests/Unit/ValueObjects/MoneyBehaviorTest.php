<?php

declare(strict_types=1);

namespace Tests\Unit\ValueObjects;

use Core\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class MoneyBehaviorTest extends TestCase
{
    /**
     * @dataProvider floatOperationProvider
     */
    public function test_float_arguments_are_rejected_at_the_public_boundary(callable $operation): void
    {
        $this->expectException(TypeError::class);

        $operation();
    }

    /** @return array<string, array{0: callable(): void}> */
    public function floatOperationProvider(): array
    {
        return [
            'constructor amount' => [static function (): void {
                (new \ReflectionClass(Money::class))->newInstanceArgs([0.1]);
            }],
            'multiplication operand' => [static function (): void {
                (new \ReflectionMethod(Money::class, 'multiply'))->invoke(new Money('10'), 0.1);
            }],
            'division operand' => [static function (): void {
                (new \ReflectionMethod(Money::class, 'divide'))->invoke(new Money('10'), 0.1);
            }],
        ];
    }

    public function test_decimal_arithmetic_uses_exact_bcmath_values(): void
    {
        $result = Money::fromString('0.1', 'USDT')
            ->add(Money::fromString('0.2', 'USDT'))
            ->multiply('3')
            ->divide('2');

        $this->assertSame('0.45', $result->getAmount());
        $this->assertSame('USDT', $result->getCurrency());
    }

    public function test_non_numeric_amount_is_rejected_without_creating_money(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid monetary amount');

        new Money('1e3');
    }

    public function test_division_by_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Division by zero');

        Money::fromString('10')->divide('0.0000000000');
    }
}
