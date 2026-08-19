<?php

declare(strict_types=1);

namespace Core\ValueObjects;

use InvalidArgumentException;

/**
 * Money Value Object
 *
 * مدیریت امن مبالغ برای جلوگیری از خطای اعشار و تداخل محاسباتی.
 * تمام مبالغ به صورت string (سازگار با bcmath) نگهداری می‌شوند.
 *
 * 🛡️ استانداردهای مالی ارتقا یافته (مبارزه با float):
 *   - طبق دستورالعمل طلایی، استفاده از float برای ذخیره یا محاسبه مبالغ اکیداً ممنوع است.
 *   - مبالغ فقط به صورت عدد صحیح (Integer) یا رشته‌های بزرگ محاسباتی (BCMath) پردازش می‌شوند.
 *   - تمام ورودی‌های float بلافاصله رد شده و استثنا پرتاب می‌کنند.
 *
 * Scale پیش‌فرض بر اساس ارز:
 *   IRT (تومان)            → 4 رقم اعشار  ← واحد رسمی پلتفرم چرتکه
 *   IRR (ریال)             → 4 رقم اعشار  ← فقط برای سازگاری با درگاه‌های پرداخت
 *   USDT / BTC / ...       → 8 رقم اعشار (سازگار با crypto)
 *
 * توجه ارزی (CURRENCY-UNIT-2026-06):
 *   واحد پایه‌ی همه‌ی نمایش‌ها، ذخیره‌سازی DB، و گزارش‌ها در چرتکه «تومان» (IRT) است.
 *   ریال (IRR) فقط در لایه‌ی آداپتر درگاه پرداخت (DgPay, IDPay, NextPay) و در
 *   لحظه‌ی تبدیل برای ارسال به API بانکی استفاده می‌شود — و سپس فوراً به IRT
 *   بازمی‌گردد. هیچ ستون DB، هیچ نمایش UI، و هیچ default سرویس در سطح اپ
 *   نباید IRR را به‌عنوان واحد ذخیره‌سازی یا نمایش استفاده کند.
 */
readonly class Money
{
    /** scale پیش‌فرض برای ارزهای fiat (IRT / IRR) */
    public const SCALE_FIAT   = 4;

    /** scale پیش‌فرض برای ارزهای دیجیتال (USDT/BTC/...) */
    public const SCALE_CRYPTO = 8;

    /** scale داخلی میانی برای محاسبات درصدی (جلوگیری از تجمع خطا) */
    private const INTERNAL_SCALE = 10;

    /** واحد پولی پیش‌فرض پلتفرم — تومان */
    public const DEFAULT_CURRENCY = 'IRT';

    private string $amount;
    private string $currency;

    public function __construct(string|int $amount, string $currency = self::DEFAULT_CURRENCY) {
        // Float is deliberately excluded by the public type contract. Under
        // strict_types it is rejected before any monetary object is created.
        $cleaned = trim((string)$amount);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $cleaned)) {
            throw new InvalidArgumentException("Invalid monetary amount: '{$cleaned}'");
        }
        $this->amount = $cleaned;
        $this->currency = strtoupper((string)$currency);
    }

    // ─── Factory methods ──────────────────────────────────────────────────────

    public static function fromString(string $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($amount, $currency);
    }

    public static function fromInt(int $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($amount, $currency);
    }

    /**
     * Factory با scale خودکار بر اساس ارز
     */
    public static function of(string $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($amount, $currency);
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** scale مناسب برای ارز این شیء */
    public function getScale(): int
    {
        return self::scaleForCurrency($this->currency);
    }

    public static function scaleForCurrency(string $currency): int
    {
        return match (strtoupper((string)$currency)) {
            'IRR', 'IRT', 'TOMAN' => self::SCALE_FIAT,
            default               => self::SCALE_CRYPTO, // USDT, BTC, ETH, ...
        };
    }

    // ─── Arithmetic ───────────────────────────────────────────────────────────

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        $scale  = $this->getScale();
        $result = bcadd($this->amount, $other->getAmount(), $scale);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        $scale  = $this->getScale();
        $result = bcsub($this->amount, $other->getAmount(), $scale);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    public function multiply(string|int $multiplier): self
    {
        $scale = $this->getScale();
        $result = bcmul($this->amount, (string)$multiplier, $scale);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    /**
     * تقسیم مقدار بر یک عدد
     */
    public function divide(string|int $divisor): self
    {
        $scale = $this->getScale();
        $dStr  = (string)$divisor;
        if (bccomp($dStr, '0', self::INTERNAL_SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero is not allowed.');
        }
        $result = bcdiv($this->amount, $dStr, $scale);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    /**
     * محاسبه X درصد از این مبلغ (برای کارمزد، مالیات و...)
     */
    public function percentage(string $percent): self
    {
        $scale = $this->getScale();
        $factor = bcdiv($percent, '100', self::INTERNAL_SCALE);
        $result = bcmul($this->amount, $factor, $scale);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    /**
     * نگه‌داشتن فقط scale رقم اعشار (بدون گرد کردن — truncate)
     */
    public function toScale(int $scale): self
    {
        $result = bcadd($this->amount, '0', $scale);
        return new self($result, $this->currency);
    }

    // ─── Comparison ───────────────────────────────────────────────────────────

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), $this->getScale()) === 1;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), $this->getScale()) >= 0;
    }

    public function isLessThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), $this->getScale()) === -1;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', $this->getScale()) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', $this->getScale()) === -1;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', $this->getScale()) === 1;
    }

    public function equals(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), $this->getScale()) === 0;
    }

    // ─── Output ───────────────────────────────────────────────────────────────

    /**
     * فرمت‌سازی کاملاً دقیق و رشته‌ای بدون استفاده از float و تابع لرزان number_format
     */
    public function format(): string
    {
        $scale = $this->getScale();
        
        $parts = explode('.', $this->amount);
        $integerPart = $parts[0];
        $decimalPart = $parts[1] ?? '';
        
        // افزودن جداکننده‌ی هزارگان به بخش صحیح به صورت رشته‌ای پویا
        $formattedInteger = '';
        $len = strlen((string)$integerPart);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0 && $integerPart[$i] !== '-') {
                $formattedInteger .= ',';
            }
            $formattedInteger .= $integerPart[$i];
        }
        
        if ($scale === self::SCALE_FIAT || $decimalPart === '') {
            $formattedAmount = $formattedInteger;
        } else {
            $formattedAmount = $formattedInteger . '.' . substr(str_pad($decimalPart, $scale, '0'), 0, $scale);
        }

        $currencyName = match ($this->currency) {
            'IRR'   => 'ریال',
            'IRT'   => 'تومان',
            'USDT'  => 'USDT',
            default => $this->currency,
        };
        return "{$formattedAmount} {$currencyName}";
    }

    /** خروجی رشته‌ای برای ذخیره در DB */
    public function __toString(): string
    {
        return $this->amount;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->getCurrency()) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->getCurrency()}"
            );
        }
    }

    private function stripTrailingZeroes(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '' ? '0' : $value;
    }
}
