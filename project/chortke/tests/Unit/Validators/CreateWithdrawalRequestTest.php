<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Validators\Requests\CreateWithdrawalRequest;
use App\Services\Settings\AppSettings;
use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست‌های CreateWithdrawalRequest
 *
 * این Validator دروازه ورودی برای درخواست برداشت وجه است (ریالی و کریپتو)
 * و باید قبل از رسیدن به WalletService/EscrowService جلوی مقادیر نامعتبر را بگیرد.
 */
class CreateWithdrawalRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testValidIrtWithdrawalPasses(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertTrue($request->validate());
        $this->assertFalse($request->fails());
        $this->assertSame('50000', $request->validated()['amount']);
    }

    public function testValidUsdtWithdrawalPasses(): void
    {
        // نکته: rules() پایه برای amount همیشه «numeric|min:1000» است — حتی برای
        // ارز USDT — بدون توجه به اینکه حداقل برداشت واقعی USDT (از طریق
        // AppSettings، پیش‌فرض ۱۰) معمولاً بسیار کمتر از ۱۰۰۰ است. به همین خاطر
        // این تست happy-path از مقداری استفاده می‌کند که از قانون ثابت min:1000
        // هم عبور کند. سناریوی واقعی‌تر (مثلاً برداشت ۲۰ USDT) را در تست بعدی
        // به‌عنوان یک باگ کشف‌شده مستند کرده‌ایم.
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'USDT',
            'crypto_wallet'   => 'TXAbCdEfGhIjKlMnOpQrStUv',
            'crypto_network'  => 'TRC20',
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertTrue($request->validate());
        $this->assertFalse($request->fails());
    }

    /**
     * 🐛 باگ کشف‌شده: rules() مقدار amount را همیشه با «min:1000» بررسی می‌کند،
     * حتی وقتی currency برابر USDT باشد. اما حداقل واقعی برداشت USDT طبق منطق
     * AppSettings (در متد validate()) معمولاً پیش‌فرض ۱۰ است. نتیجه: یک کاربر که
     * می‌خواهد ۲۰ USDT برداشت کند (که از نظر بیزینسی کاملاً مجاز است) همیشه در
     * همان لایه‌ی rules() رد می‌شود و حتی هرگز به چک سفارشی AppSettings
     * نمی‌رسد. این یعنی منطق AppSettings برای USDT در عمل کد مرده (dead code)
     * است مادامی که amount کمتر از ۱۰۰۰ باشد.
     */
    public function testUsdtAmountBelowGenericFormMinimumIncorrectlyFails(): void
    {
        $appSettings = m::mock(AppSettings::class);
        // اگر این مقدار صدا زده شود یعنی به لایه‌ی دوم رسیده‌ایم — که در باگ فعلی هرگز رخ نمی‌دهد.
        $appSettings->shouldReceive('get')->with('min_withdrawal_usdt', 10)->andReturn(10)->byDefault();

        $request = new CreateWithdrawalRequest([
            'amount'          => '20',
            'currency'        => 'USDT',
            'crypto_wallet'   => 'TXAbCdEfGhIjKlMnOpQrStUv',
            'crypto_network'  => 'TRC20',
            'idempotency_key' => 'idem-key-1234567890',
        ]);
        $request->setAppSettings($appSettings);

        // رفتار فعلی (باگ‌دار): رد می‌شود، چون قانون ثابت min:1000 در rules() حاکم است.
        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }

    public function testIrtWithdrawalWithoutBankCardFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'IRT',
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('bank_card_id', $request->errors());
    }

    public function testUsdtWithdrawalWithoutWalletAddressFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '20',
            'currency'        => 'USDT',
            'crypto_network'  => 'TRC20',
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('crypto_wallet', $request->errors());
    }

    public function testUsdtWithdrawalWithInvalidNetworkFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '20',
            'currency'        => 'USDT',
            'crypto_wallet'   => 'TXAbCdEfGhIjKlMnOpQrStUv',
            'crypto_network'  => 'NOT_A_REAL_NETWORK',
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('crypto_network', $request->errors());
    }

    /**
     * 🐛 یافته: قانون «in:IRT,USDT» در rules() دقیقاً (case-sensitive) چک می‌شود،
     * در حالی‌که شاخه‌بندی bank_card_id در برابر crypto_wallet در همان متد با
     * strtoupper() کار می‌کند. نتیجه: اگر فرانت‌اند یا کلاینت API مقدار 'usdt'
     * (حروف کوچک) بفرستد، درخواست رد می‌شود؛ حتی اگر همه‌ی فیلدهای دیگر درست
     * پر شده باشند. اگر ورودی case-insensitive قرار است پذیرفته شود، این قانون
     * باید روی مقدار normalize‌شده اعمال شود.
     */
    public function testLowercaseCurrencyIsRejectedDespiteCaseInsensitiveBranching(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'usdt',
            'crypto_wallet'   => 'TXAbCdEfGhIjKlMnOpQrStUv',
            'crypto_network'  => 'TRC20',
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('currency', $request->errors());
    }

    public function testInvalidCurrencyFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'USD',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('currency', $request->errors());
    }

    public function testNonNumericAmountFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => 'abc',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }

    public function testNegativeAmountFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '-1000',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }

    public function testZeroAmountFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '0',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);

        $this->assertFalse($request->validate());
    }

    public function testShortIdempotencyKeyFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'short',
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('idempotency_key', $request->errors());
    }

    public function testMissingIdempotencyKeyFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'       => '50000',
            'currency'     => 'IRT',
            'bank_card_id' => 1,
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('idempotency_key', $request->errors());
    }

    public function testDescriptionLongerThan500CharsFails(): void
    {
        $request = new CreateWithdrawalRequest([
            'amount'           => '50000',
            'currency'         => 'IRT',
            'bank_card_id'     => 1,
            'idempotency_key'  => 'idem-key-1234567890',
            'user_description' => str_repeat('a', 501),
        ]);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('user_description', $request->errors());
    }

    public function testAmountBelowAppSettingsMinimumFails(): void
    {
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('min_withdrawal_irt', 50000)->andReturn(100000);

        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);
        $request->setAppSettings($appSettings);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }

    public function testAmountMeetingAppSettingsMinimumPasses(): void
    {
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('min_withdrawal_irt', 50000)->andReturn(50000);

        $request = new CreateWithdrawalRequest([
            'amount'          => '50000',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);
        $request->setAppSettings($appSettings);

        $this->assertTrue($request->validate());
    }

    public function testFractionalIrtAmountIsRejectedWhenAppSettingsPresent(): void
    {
        // مبلغ تومان نباید اعشاری باشد
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('min_withdrawal_irt', 50000)->andReturn(10000);

        $request = new CreateWithdrawalRequest([
            'amount'          => '50000.5',
            'currency'        => 'IRT',
            'bank_card_id'    => 1,
            'idempotency_key' => 'idem-key-1234567890',
        ]);
        $request->setAppSettings($appSettings);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }

    public function testUsdtMinimumFromAppSettingsIsRespected(): void
    {
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('min_withdrawal_usdt', 10)->andReturn(15);

        $request = new CreateWithdrawalRequest([
            'amount'          => '10',
            'currency'        => 'USDT',
            'crypto_wallet'   => 'TXAbCdEfGhIjKlMnOpQrStUv',
            'crypto_network'  => 'TRC20',
            'idempotency_key' => 'idem-key-1234567890',
        ]);
        $request->setAppSettings($appSettings);

        $this->assertFalse($request->validate());
        $this->assertArrayHasKey('amount', $request->errors());
    }
}