<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Adapters\DeepFaceKycAdapter;
use App\Adapters\Notification\FcmNotificationAdapter;
use App\Adapters\Notification\LogNotificationAdapter;
use App\Adapters\JibitInquiryAdapter;
use App\Adapters\VandarInquiryAdapter;
use App\Adapters\BankInquiryManager;
use App\Adapters\CryptoApiAdapter;
use App\Adapters\CryptoExplorerAdapter;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Models\PaymentGateway;
use App\Services\Notification\NotificationOrchestrator;
use App\Services\Payment\DgPayGateway;
use App\Services\Payment\IDPayGateway;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\ZarinPalGateway;
use App\Services\Auth\GoogleJwtVerifier;
use App\Services\CaptchaService;
use Core\CircuitBreaker;
use Core\Logger;
use Core\Queue;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * Real cURL contracts against a deterministic HTTP provider server.
 * Only persistence/logging dependencies are mocked; no HTTP method is mocked.
 */
/**
 * @phpstan-type ProviderRequest array{
 *   method:string,path:string,query:array<string,mixed>,headers:array<string,string>,
 *   body:string,post:array<string,mixed>,files:array<string,array{name:string,size:int}>
 * }
 */
final class ExternalProviderHttpContractTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    private string $baseUrl = 'http://8.8.8.8:8092';
    private string $stateDir;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var CircuitBreaker&\Mockery\MockInterface */
    private CircuitBreaker $circuit;
    private ?string $fcmServiceAccountFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-provider-contract.log');
        $configuredStateDir = getenv('PROVIDER_FAKE_STATE_DIR');
        $this->stateDir = is_string($configuredStateDir) && $configuredStateDir !== ''
            ? $configuredStateDir
            : sys_get_temp_dir() . '/chortke-provider-state';
        $configuredBaseUrl = getenv('PROVIDER_CONTRACT_BASE_URL');
        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            $this->baseUrl = rtrim($configuredBaseUrl, '/');
        }
        if (!is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0777, true);
        }
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }

        config_set('app.env', 'testing');
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->circuit = m::mock(CircuitBreaker::class);
        $this->circuit->shouldReceive('call')->andReturnUsing(static fn(string $name, callable $operation) => $operation());
    }

    protected function tearDown(): void
    {
        if ($this->fcmServiceAccountFile !== null) {
            @unlink($this->fcmServiceAccountFile);
            $this->fcmServiceAccountFile = null;
        }
        m::close();
        parent::tearDown();
    }

    public function test_idpay_create_contract_sends_headers_and_converts_toman_to_rial(): void
    {
        config_set('payment.idpay.api_url', $this->baseUrl . '/success/idpay/v1.1');
        $gateway = $this->idPayGateway();

        $result = $gateway->createPayment('12500', 'runtime contract', 'https://merchant.example/callback', [
            'mobile' => '09121234567',
            'email' => 'contract@example.test',
        ]);

        $this->assertTrue((bool) ($result['success'] ?? false), (json_encode($result) ?: ''));
        $this->assertSame('FAKE-IDPAY-AUTHORITY-000000000001', $result['authority'] ?? null);
        $this->assertSame(1, $this->requestCount('success', 'idpay'));

        $request = $this->lastRequest('success', 'idpay');
        $payload = $this->decodeArray($request['body']);
        $this->assertSame(125000, $payload['amount'] ?? null);
        $this->assertSame('09121234567', $payload['phone'] ?? null);
        $this->assertSame('fake-idpay-key', $request['headers']['X-API-KEY'] ?? null);
        $this->assertSame('1', $request['headers']['X-SANDBOX'] ?? null);
    }

    public function test_idpay_retries_transient_503_and_does_not_retry_permanent_400(): void
    {
        config_set('payment.idpay.api_url', $this->baseUrl . '/retry/idpay/v1.1');
        $retry = $this->idPayGateway()->createPayment('12500', 'retry contract', 'https://merchant.example/callback');
        $this->assertTrue((bool) ($retry['success'] ?? false), (json_encode($retry) ?: ''));
        $this->assertSame(3, $this->requestCount('retry', 'idpay'));

        $this->clearState();
        config_set('payment.idpay.api_url', $this->baseUrl . '/permanent/idpay/v1.1');
        $permanent = $this->idPayGateway()->createPayment('12500', 'permanent contract', 'https://merchant.example/callback');
        $this->assertFalse((bool) ($permanent['success'] ?? true));
        $this->assertSame(1, $this->requestCount('permanent', 'idpay'));
    }

    public function test_zarinpal_create_and_verify_contract_are_schema_and_amount_strict(): void
    {
        $this->configureZarinPal('success');
        $gateway = $this->zarinPalGateway();
        $created = $gateway->createPayment('12500', 'zarinpal contract', 'https://merchant.example/callback', [
            'mobile'=>'09121234567','email'=>'contract@example.test',
        ]);
        $this->assertTrue((bool)($created['success'] ?? false), (json_encode($created) ?: ''));
        $this->assertSame('ZP-FAKE-AUTHORITY-000000000000000001', $created['authority'] ?? null);
        $request = $this->lastRequest('success', 'zarinpal');
        $payload = $this->decodeArray($request['body']);
        $this->assertSame('fake-zarinpal-merchant', $payload['merchant_id'] ?? null);
        $this->assertSame('12500', str_value($payload['amount'] ?? ''));
        $this->assertSame('09121234567', $this->requireArray($payload['metadata'] ?? null)['mobile'] ?? null);

        $verified = $gateway->verifyPayment((string)$created['authority'], '12500');
        $this->assertTrue((bool)($verified['success'] ?? false), (json_encode($verified) ?: ''));
        $this->assertSame('ZP-FAKE-REF-100', $verified['ref_id'] ?? null);
        $this->assertSame('12500', str_value($verified['amount'] ?? ''));

        $this->clearState();
        $this->configureZarinPal('mismatch');
        $mismatch = $this->zarinPalGateway()->verifyPayment('ZP-FAKE-AUTHORITY-000000000000000001', '12500');
        $this->assertFalse((bool)($mismatch['success'] ?? true));
        $this->assertStringContainsString('مبلغ', str_value($mismatch['message'] ?? ''));

        $this->clearState();
        $this->configureZarinPal('schema');
        $schema = $this->zarinPalGateway()->createPayment('12500', 'schema', 'https://merchant.example/callback');
        $this->assertFalse((bool)($schema['success'] ?? true));
        $this->assertSame(1, $this->requestCount('schema', 'zarinpal'));
    }

    public function test_nextpay_create_and_verify_contract_use_form_and_require_reference_and_amount(): void
    {
        $this->configureNextPay('success');
        $gateway = $this->nextPayGateway();
        $created = $gateway->createPayment('12500', 'nextpay contract', 'https://merchant.example/callback', ['mobile'=>'09121234567']);
        $this->assertTrue((bool)($created['success'] ?? false), (json_encode($created) ?: ''));
        $this->assertSame('NP-FAKE-TRANS-000000000001', $created['authority'] ?? null);
        $request = $this->lastRequest('success', 'nextpay');
        parse_str((string)$request['body'], $form);
        $this->assertSame('fake-nextpay-key', $form['api_key'] ?? null);
        $this->assertSame('12500', $form['amount'] ?? null);
        $this->assertSame('09121234567', $form['customer_phone'] ?? null);

        $verified = $gateway->verifyPayment((string)$created['authority'], '12500');
        $this->assertTrue((bool)($verified['success'] ?? false), (json_encode($verified) ?: ''));
        $this->assertSame('NP-FAKE-REF', $verified['ref_id'] ?? null);
        $this->assertSame('12500', str_value($verified['amount'] ?? ''));

        $this->clearState();
        $this->configureNextPay('mismatch');
        $mismatch = $this->nextPayGateway()->verifyPayment('NP-FAKE-TRANS-000000000001', '12500');
        $this->assertFalse((bool)($mismatch['success'] ?? true));

        $this->clearState();
        $this->configureNextPay('schema');
        $schema = $this->nextPayGateway()->verifyPayment('NP-FAKE-TRANS-000000000001', '12500');
        $this->assertFalse((bool)($schema['success'] ?? true));
        $this->assertStringContainsString('ناقص', str_value($schema['message'] ?? ''));
    }

    public function test_dgpay_contract_converts_toman_to_rial_and_back_without_accepting_mismatch(): void
    {
        $this->configureDgPay('success');
        $gateway = $this->dgPayGateway();
        $created = $gateway->createPayment('12500', 'dgpay contract', 'https://merchant.example/callback', ['mobile'=>'09121234567']);
        $this->assertTrue((bool)($created['success'] ?? false), (json_encode($created) ?: ''));
        $this->assertSame('DG-FAKE-TOKEN-000000000001', $created['authority'] ?? null);
        $request = $this->lastRequest('success', 'dgpay');
        $payload = $this->decodeArray($request['body']);
        $this->assertSame('fake-dgpay-merchant', $payload['merchant'] ?? null);
        $this->assertSame(125000, $payload['amount'] ?? null);
        $this->assertSame('09121234567', $payload['mobile'] ?? null);

        $verified = $gateway->verifyPayment((string)$created['authority'], '12500');
        $this->assertTrue((bool)($verified['success'] ?? false), (json_encode($verified) ?: ''));
        $this->assertSame('DG-FAKE-REF', $verified['ref_id'] ?? null);
        $this->assertSame('12500', str_value($verified['amount'] ?? ''));

        $this->clearState();
        $this->configureDgPay('mismatch');
        $mismatch = $this->dgPayGateway()->verifyPayment('DG-FAKE-TOKEN-000000000001', '12500');
        $this->assertFalse((bool)($mismatch['success'] ?? true));

        $this->clearState();
        $this->configureDgPay('schema');
        $schema = $this->dgPayGateway()->createPayment('12500', 'schema', 'https://merchant.example/callback');
        $this->assertFalse((bool)($schema['success'] ?? true));
        $this->assertStringContainsString('توکن', str_value($schema['message'] ?? ''));
    }

    /** @dataProvider paymentGatewayProvider */
    public function test_remaining_payment_gateways_retry_503_once_per_attempt_policy_and_do_not_retry_400(string $provider): void
    {
        $this->configurePaymentProvider($provider, 'retry');
        $retryGateway = $this->paymentGateway($provider);
        $retry = $retryGateway->createPayment('12500', 'retry contract', 'https://merchant.example/callback');
        $this->assertTrue((bool)($retry['success'] ?? false), (json_encode($retry) ?: ''));
        $this->assertSame(3, $this->requestCount('retry', $provider));

        $this->clearState();
        $this->configurePaymentProvider($provider, 'permanent');
        $permanent = $this->paymentGateway($provider)->createPayment('12500', 'permanent contract', 'https://merchant.example/callback');
        $this->assertFalse((bool)($permanent['success'] ?? true));
        $this->assertSame(1, $this->requestCount('permanent', $provider));

        $this->clearState();
        $this->configurePaymentProvider($provider, 'malformed');
        $malformed = $this->paymentGateway($provider)->createPayment('12500', 'malformed contract', 'https://merchant.example/callback');
        $this->assertFalse((bool)($malformed['success'] ?? true));
        $this->assertGreaterThanOrEqual(1, $this->requestCount('malformed', $provider));
    }

    /** @return list<array{string}> */
    public function paymentGatewayProvider(): array
    {
        return [['zarinpal'],['nextpay'],['dgpay']];
    }

    public function test_payment_gateway_amount_validation_rejects_bad_values_before_network(): void
    {
        foreach (['zarinpal','nextpay','dgpay'] as $provider) {
            $this->configurePaymentProvider($provider, 'success');
            foreach (['0','-1','not-a-number','12.5'] as $amount) {
                try {
                    $this->paymentGateway($provider)->createPayment($amount, 'invalid', 'https://merchant.example/callback');
                    $this->fail("{$provider} accepted invalid amount {$amount}");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('Amount', $e->getMessage());
                }
            }
            $this->assertSame(0, $this->requestCount('success', $provider));
        }
    }

    public function test_google_jwks_contract_verifies_real_rsa_token_caches_keys_retries_and_blocks_ssrf(): void
    {
        [$token,$jwks] = $this->googleJwtFixture('runtime-kid');
        file_put_contents($this->stateDir.'/google_jwks_fixture.json', json_encode($jwks, JSON_THROW_ON_ERROR));
        /** @var array<string, mixed> $values */
        $values=[];
        $cache=m::mock(\Core\Cache::class);
        $cache->shouldReceive('get')->andReturnUsing(static function(string $key) use (&$values) { return $values[$key] ?? null; });
        $cache->shouldReceive('set')->andReturnUsing(static function(string $key,mixed $value) use (&$values): bool { $values[$key]=$value; return true; });
        config_set('services.google.jwks_url',$this->baseUrl.'/success/google/jwks');
        config_set('services.google.timeout',2);
        $verifier=new GoogleJwtVerifier($cache,$this->logger,$this->circuit);
        $verified=$verifier->verifyIdToken($token,'runtime-client',['https://accounts.google.com']);
        $this->assertTrue((bool)($verified['success']??false),(json_encode($verified) ?: ''));
        $this->assertSame('contract@example.test',$this->requireArray($verified['payload'] ?? null)['email'] ?? null);
        $this->assertSame(1,$this->requestCount('success','google'));
        $again=$verifier->verifyIdToken($token,'runtime-client',['https://accounts.google.com']);
        $this->assertTrue((bool)($again['success']??false));
        $this->assertSame(1,$this->requestCount('success','google'));

        $this->clearState();
        file_put_contents($this->stateDir.'/google_jwks_fixture.json', json_encode($jwks, JSON_THROW_ON_ERROR));
        $emptyCache=m::mock(\Core\Cache::class); $emptyCache->shouldReceive('get')->andReturn(null); $emptyCache->shouldReceive('set')->andReturn(true);
        config_set('services.google.jwks_url',$this->baseUrl.'/retry/google/jwks');
        $retried=(new GoogleJwtVerifier($emptyCache,$this->logger,$this->circuit))->verifyIdToken($token,'runtime-client',['https://accounts.google.com']);
        $this->assertTrue((bool)($retried['success']??false),(json_encode($retried) ?: ''));
        $this->assertSame(3,$this->requestCount('retry','google'));

        $this->clearState();
        config_set('services.google.jwks_url','http://127.0.0.1:8092/success/google/jwks');
        $blocked=(new GoogleJwtVerifier($emptyCache,$this->logger,$this->circuit))->verifyIdToken($token,'runtime-client',['https://accounts.google.com']);
        $this->assertFalse((bool)($blocked['success']??true));
        $this->assertSame(0,$this->requestCount('success','google'));
    }

    public function test_recaptcha_contract_success_retry_permanent_malformed_and_ssrf(): void
    {
        config_set('services.recaptcha.verify_url',$this->baseUrl.'/success/recaptcha/siteverify');
        $success=$this->captchaService()->verify('unused','unused','recaptcha-response');
        $this->assertTrue($success);
        $request=$this->lastRequest('success','recaptcha'); parse_str((string)$request['body'],$form);
        $this->assertSame('fake-recaptcha-secret',$form['secret']??null);
        $this->assertSame('recaptcha-response',$form['response']??null);

        $this->clearState(); config_set('services.recaptcha.verify_url',$this->baseUrl.'/retry/recaptcha/siteverify');
        $this->assertTrue($this->captchaService()->verify('unused','unused','recaptcha-response'));
        $this->assertSame(3,$this->requestCount('retry','recaptcha'));

        $this->clearState(); config_set('services.recaptcha.verify_url',$this->baseUrl.'/permanent/recaptcha/siteverify');
        $this->assertFalse($this->captchaService()->verify('unused','unused','recaptcha-response'));
        $this->assertSame(1,$this->requestCount('permanent','recaptcha'));

        $this->clearState(); config_set('services.recaptcha.verify_url',$this->baseUrl.'/malformed/recaptcha/siteverify');
        $this->assertFalse($this->captchaService()->verify('unused','unused','recaptcha-response'));
        $this->assertSame(1,$this->requestCount('malformed','recaptcha'));

        $this->clearState(); config_set('services.recaptcha.verify_url','http://127.0.0.1:8092/success/recaptcha/siteverify');
        $this->assertFalse($this->captchaService()->verify('unused','unused','recaptcha-response'));
        $this->assertSame(0,$this->requestCount('success','recaptcha'));
    }

    public function test_jibit_contract_authenticates_then_queries_iban_and_caches_success(): void
    {
        config_set('services.jibit.api_key', 'fake-jibit-key');
        config_set('services.jibit.api_secret', 'fake-jibit-secret');
        config_set('services.jibit.base_url', $this->baseUrl . '/success/jibit/v1/');
        config_set('services.jibit.timeout', 2);

        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('put')->once()->with(m::type('string'), m::on(static fn($v): bool => is_array($v) && ($v['success'] ?? false) === true), 1440);
        $jibitBreaker = m::mock(\App\Contracts\CircuitBreakerInterface::class);
        $jibitBreaker->shouldReceive('call')->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        $adapter = new JibitInquiryAdapter($this->logger, $cache, $jibitBreaker);

        $result = $adapter->inquireIban('IR820540102680020817909002');

        $this->assertTrue((bool) ($result['success'] ?? false), (json_encode($result) ?: ''));
        $this->assertSame('Test Owner', $result['owner_name'] ?? null);
        $this->assertSame('Runtime Bank', $result['bank'] ?? null);
        $this->assertSame(2, $this->requestCount('success', 'jibit'));
        $ibanRequest = $this->lastRequestMatching('success', 'jibit', 'services_iban');
        $this->assertSame('Bearer fake-jibit-token', $ibanRequest['headers']['Authorization'] ?? null);
    }

    public function test_vandar_iban_contract_authenticates_normalizes_and_caches_success(): void
    {
        config_set('services.vandar.api_token', 'fake-vandar-token');
        config_set('services.vandar.business', 'runtime-business');
        config_set('services.vandar.base_url', $this->baseUrl . '/success/vandar');
        config_set('services.vandar.timeout', 2);
        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('put')->once()->with(m::type('string'), m::on(static fn(mixed $v): bool => is_array($v) && ($v['owner_name'] ?? '') === 'Test Owner'), 1440);
        $breaker = m::mock(\App\Contracts\CircuitBreakerInterface::class);
        $breaker->shouldReceive('call')->once()->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        $adapter = new VandarInquiryAdapter($this->logger, $cache, $breaker);

        $result = $adapter->inquireIban('IR820540102680020817909002');
        $this->assertTrue((bool)($result['success'] ?? false), (json_encode($result) ?: ''));
        $this->assertSame('Test Owner', $result['owner_name'] ?? null);
        $this->assertSame('Runtime Bank', $result['bank'] ?? null);
        $request = $this->lastRequest('success', 'vandar');
        $this->assertSame('Bearer fake-vandar-token', $request['headers']['Authorization'] ?? null);
        $payload = $this->decodeArray($request['body']);
        $this->assertSame('IR820540102680020817909002', $payload['iban'] ?? null);
        $this->assertRegExp('/^[a-f0-9]{24}$/', str_value($payload['track_id'] ?? ''));
    }

    public function test_vandar_schema_retry_permanent_and_ssrf_contracts(): void
    {
        $this->configureVandar('schema');
        $schema = $this->vandarAdapter()->inquireIban('IR820540102680020817909002');
        $this->assertFalse((bool)($schema['success'] ?? true));
        $this->assertStringContainsString('ناقص', str_value($schema['message'] ?? ''));

        $this->clearState();
        $this->configureVandar('retry');
        $retry = $this->vandarAdapter()->inquireIban('IR820540102680020817909002');
        $this->assertTrue((bool)($retry['success'] ?? false), (json_encode($retry) ?: ''));
        $this->assertSame(3, $this->requestCount('retry', 'vandar'));

        $this->clearState();
        $this->configureVandar('permanent');
        $permanent = $this->vandarAdapter()->inquireIban('IR820540102680020817909002');
        $this->assertFalse((bool)($permanent['success'] ?? true));
        $this->assertSame(1, $this->requestCount('permanent', 'vandar'));

        $this->clearState();
        config_set('services.vandar.base_url', 'http://127.0.0.1:8092/success/vandar');
        $blocked = $this->vandarAdapter()->inquireIban('IR820540102680020817909002');
        $this->assertFalse((bool)($blocked['success'] ?? true));
        $this->assertSame(0, $this->requestCount('success', 'vandar'));
    }

    public function test_crypto_verification_contracts_cover_tron_bsc_ton_and_solana(): void
    {
        $adapter = $this->cryptoApiAdapter('success');
        $tron = $adapter->verify('TRC20', str_repeat('a',64), 'TFromRuntimeWallet', 'TToRuntimeWallet', '12.5');
        $this->assertSame('verified', $tron['status'] ?? null, (json_encode($tron) ?: ''));
        $bsc = $adapter->verify('BNB20', '0x'.str_repeat('c',64), '0x1111111111111111111111111111111111111111', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('verified', $bsc['status'] ?? null, (json_encode($bsc) ?: ''));
        $ton = $adapter->verify('TON', str_repeat('b',64), 'EQFromRuntimeWallet', 'EQToRuntimeWallet', '12.5');
        $this->assertSame('verified', $ton['status'] ?? null, (json_encode($ton) ?: ''));
        $sol = $adapter->verify('SOL', str_repeat('3',88), 'SolFromRuntimeWallet', 'SolToRuntimeWallet', '12.5');
        $this->assertSame('verified', $sol['status'] ?? null, (json_encode($sol) ?: ''));
        $this->assertGreaterThanOrEqual(5, $this->requestCount('success', 'crypto'));
    }

    public function test_crypto_provider_retries_rejects_mismatch_schema_ssrf_and_bad_input(): void
    {
        $retry = $this->cryptoApiAdapter('retry')->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('verified', $retry['status'] ?? null, (json_encode($retry) ?: ''));
        $this->assertSame(3, $this->requestCountForOperation('retry','crypto','bsc'));

        $this->clearState();
        $fallbackAdapter = $this->cryptoApiAdapter('success');
        config_set('services.crypto.bscscan_url', $this->baseUrl.'/permanent/crypto/bsc/%s');
        config_set('services.crypto.bscscan_fallback_url', $this->baseUrl.'/success/crypto/bsc_fallback/%s');
        $fallback = $fallbackAdapter->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('verified', $fallback['status'] ?? null, (json_encode($fallback) ?: ''));
        $this->assertSame(1, $this->requestCountForOperation('permanent','crypto','bsc'));
        $this->assertSame(1, $this->requestCountForOperation('success','crypto','bsc_fallback'));

        $this->clearState();
        $permanent = $this->cryptoApiAdapter('permanent')->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('error', $permanent['status'] ?? null);
        $this->assertSame(2, $this->requestCount('permanent','crypto'));

        $this->clearState();
        $malformed = $this->cryptoApiAdapter('malformed')->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertNotSame('verified', $malformed['status'] ?? null);

        $this->clearState();
        $mismatch = $this->cryptoApiAdapter('mismatch')->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('mismatch', $mismatch['status'] ?? null);

        $this->clearState();
        $schema = $this->cryptoApiAdapter('schema')->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertNotSame('verified', $schema['status'] ?? null);

        $this->clearState();
        $blocked = $this->cryptoApiAdapter('success', true)->verify('BNB20', '0x'.str_repeat('c',64), '', '0x2222222222222222222222222222222222222222', '12.5');
        $this->assertSame('error', $blocked['status'] ?? null);
        $this->assertSame(0, $this->requestCount('success','crypto'));

        foreach ([
            ['BNB20','bad-hash','','0x2222222222222222222222222222222222222222','12.5'],
            ['UNKNOWN',str_repeat('a',64),'','wallet','12.5'],
            ['TRC20',str_repeat('a',64),'','TToRuntimeWallet','0'],
        ] as $case) {
            $result = $this->cryptoApiAdapter('success')->verify(...$case);
            $this->assertSame('error', $result['status'] ?? null);
        }
        $this->assertSame(0, $this->requestCount('success','crypto'));
    }

    public function test_crypto_explorer_uses_configured_external_url_but_never_false_verifies_html(): void
    {
        config_set('services.crypto.explorer_urls.BNB20', $this->baseUrl.'/success/crypto/explorer/');
        $breaker = m::mock(CircuitBreaker::class);
        $breaker->shouldReceive('call')->once()->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        $adapter = new CryptoExplorerAdapter($this->logger, $breaker);
        $result = $adapter->verify('BNB20', '0x'.str_repeat('c',64), '', '', '12.5');
        $this->assertSame('unavailable', $result['status'] ?? null);
        $this->assertNotSame('verified', $result['status'] ?? null);
    }

    public function test_kavenegar_contract_posts_expected_form_and_accepts_provider_success(): void
    {
        config_set('services.sms.enabled', true);
        config_set('services.sms.provider', 'kavenegar');
        config_set('services.sms.api_key', 'fake-key');
        config_set('services.sms.from', '10001234');
        config_set('services.sms.kavenegar_base_url', $this->baseUrl . '/success/kavenegar');

        $coreLogger = m::mock(Logger::class);
        $coreLogger->shouldIgnoreMissing();
        $orchestrator = m::mock(NotificationOrchestrator::class);
        $orchestrator->shouldReceive('logger')->andReturn($this->logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($this->circuit);
        $adapter = new SmsNotificationAdapter(
            m::mock(\App\Models\User::class),
            $coreLogger,
            $this->circuit,
            $orchestrator,
            m::mock(OutboxServiceInterface::class),
            m::mock(Queue::class)
        );

        $this->assertTrue($adapter->send('09121234567', 'contract پیام'));
        $this->assertSame(1, $this->requestCount('success', 'kavenegar'));
        $request = $this->lastRequest('success', 'kavenegar');
        parse_str((string) $request['body'], $form);
        $this->assertSame('09121234567', $form['receptor'] ?? null);
        $this->assertSame('10001234', $form['sender'] ?? null);
        $this->assertSame('contract پیام', $form['message'] ?? null);
    }

    public function test_melipayamak_contract_posts_credentials_sender_recipient_and_message(): void
    {
        $adapter = $this->smsAdapter('melipayamak', 'success');
        $this->assertTrue($adapter->send('09121234567', 'پیام قرارداد ملی پیامک'));
        $this->assertSame(1, $this->requestCount('success', 'melipayamak'));
        $request = $this->lastRequest('success', 'melipayamak');
        parse_str((string)$request['body'], $form);
        $this->assertSame('fake-sms-user', $form['username'] ?? null);
        $this->assertSame('fake-sms-key', $form['password'] ?? null);
        $this->assertSame('09121234567', $form['to'] ?? null);
        $this->assertSame('10001234', $form['from'] ?? null);
        $this->assertSame('پیام قرارداد ملی پیامک', $form['text'] ?? null);
        $this->assertSame('0', $form['isFlash'] ?? null);
    }

    public function test_idehpayam_contract_posts_json_and_api_key_header(): void
    {
        $adapter = $this->smsAdapter('idehpayam', 'success');
        $this->assertTrue($adapter->send('09121234567', 'پیام قرارداد ایده پیام'));
        $this->assertSame(1, $this->requestCount('success', 'idehpayam'));
        $request = $this->lastRequest('success', 'idehpayam');
        $payload = $this->decodeArray($request['body']);
        $this->assertSame('09121234567', $payload['receptor'] ?? null);
        $this->assertSame('10001234', $payload['sender'] ?? null);
        $this->assertSame('پیام قرارداد ایده پیام', $payload['message'] ?? null);
        $this->assertSame('fake-sms-key', $request['headers']['ApiKey'] ?? null);
        $this->assertSame('application/json', $request['headers']['Content-Type'] ?? null);
    }

    /** @dataProvider remainingSmsProvider */
    public function test_remaining_sms_providers_retry_transient_and_fail_fast_on_permanent_or_malformed(string $provider): void
    {
        $this->assertTrue($this->smsAdapter($provider, 'retry')->send('09121234567', 'retry پیام'));
        $this->assertSame(3, $this->requestCount('retry', $provider));

        $this->clearState();
        $this->assertFalse($this->smsAdapter($provider, 'permanent')->send('09121234567', 'permanent پیام'));
        $this->assertSame(1, $this->requestCount('permanent', $provider));

        $this->clearState();
        $this->assertFalse($this->smsAdapter($provider, 'malformed')->send('09121234567', 'malformed پیام'));
        $this->assertSame(1, $this->requestCount('malformed', $provider));
    }

    /** @return list<array{string}> */
    public function remainingSmsProvider(): array
    {
        return [['melipayamak'],['idehpayam']];
    }

    public function test_remaining_sms_providers_reject_loopback_before_transport(): void
    {
        foreach (['melipayamak','idehpayam'] as $provider) {
            config_set('services.sms.enabled', true);
            config_set('services.sms.provider', $provider);
            config_set('services.sms.api_key', 'fake-sms-key');
            config_set('services.sms.username', 'fake-sms-user');
            config_set('services.sms.from', '10001234');
            config_set('services.sms.' . $provider . '_base_url', 'http://127.0.0.1:8092/success/' . $provider);
            $this->assertFalse($this->newSmsAdapter()->send('09121234567', 'ssrf'));
            $this->assertSame(0, $this->requestCount('success', $provider));
        }
    }

    public function test_fcm_oauth_and_message_dispatch_contract_cache_token_and_preserve_payload(): void
    {
        /** @var array<string, mixed> $cacheValues */
        $cacheValues = [];
        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturnUsing(static function(string $key) use (&$cacheValues) { return $cacheValues[$key] ?? null; });
        $cache->shouldReceive('put')->andReturnUsing(static function(string $key, mixed $value) use (&$cacheValues): bool { $cacheValues[$key]=$value; return true; });
        $cache->shouldReceive('forget')->andReturnUsing(static function(string $key) use (&$cacheValues): bool { unset($cacheValues[$key]); return true; });
        $adapter = $this->fcmAdapter('success', $cache, m::mock(\Core\Database::class));

        $this->assertTrue($adapter->sendToToken('fcm-token-contract-000000000001', 'عنوان', 'متن پیام', ['count'=>12,'ignored'=>['nested']], null, 'https://merchant.example/open'));
        $this->assertSame(2, $this->requestCount('success', 'fcm'));
        $oauth = $this->lastRequestMatching('success', 'fcm', 'oauth');
        parse_str((string)$oauth['body'], $oauthForm);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $oauthForm['grant_type'] ?? null);
        $this->assertSame(3, count(explode('.', str_value($oauthForm['assertion'] ?? ''))));

        $send = $this->lastRequestMatching('success', 'fcm', 'messages_send');
        $this->assertSame('Bearer fake-fcm-oauth-token', $send['headers']['Authorization'] ?? null);
        $payload = $this->decodeArray($send['body']);
        $message = $this->requireArray($payload['message'] ?? null);
        $notification = $this->requireArray($message['notification'] ?? null);
        $data = $this->requireArray($message['data'] ?? null);
        $webpush = $this->requireArray($message['webpush'] ?? null);
        $fcmOptions = $this->requireArray($webpush['fcm_options'] ?? null);
        $this->assertSame('fcm-token-contract-000000000001', $message['token'] ?? null);
        $this->assertSame('عنوان', $notification['title'] ?? null);
        $this->assertSame('12', $data['count'] ?? null);
        $this->assertArrayNotHasKey('ignored', $data);
        $this->assertSame('https://merchant.example/open', $fcmOptions['link'] ?? null);

        $this->assertTrue($adapter->sendToToken('fcm-token-contract-000000000002', 'دوم', 'پیام دوم'));
        $this->assertSame(1, $this->requestCountForOperation('success','fcm','oauth'));
        $this->assertSame(2, $this->requestCountForOperation('success','fcm','messages_send'));
    }

    public function test_fcm_unregistered_token_is_purged_after_real_provider_response(): void
    {
        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('forget')->andReturn(true);
        $db = m::mock(\Core\Database::class);
        $db->shouldReceive('query')
            ->once()
            ->with('DELETE FROM user_devices WHERE fcm_token = ?', ['dead-fcm-token-000000000001'])
            ->andReturn(m::mock(\PDOStatement::class));
        $adapter = $this->fcmAdapter('unregistered', $cache, $db, 'success');

        $this->assertFalse($adapter->sendToToken('dead-fcm-token-000000000001', 'عنوان', 'متن'));
        $this->assertSame(1, $this->requestCountForOperation('success','fcm','oauth'));
        $this->assertSame(1, $this->requestCountForOperation('unregistered','fcm','messages_send'));
    }

    public function test_telegram_and_webhook_alert_contracts_use_real_transport_retry_and_ssrf_guards(): void
    {
        config_set('services.telegram.api_base_url', $this->baseUrl . '/retry/telegram');
        $telegram = (object)['id'=>1,'channel_type'=>'telegram','config'=>json_encode([
            'bot_token'=>'123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcd','chat_id'=>'-100123456',
        ], JSON_THROW_ON_ERROR)];
        $webhook = (object)['id'=>2,'channel_type'=>'webhook','config'=>json_encode([
            'url'=>$this->baseUrl.'/success/webhook/alert',
        ], JSON_THROW_ON_ERROR)];
        $notification = m::mock(\App\Models\Notification::class);
        $notification->shouldReceive('getActiveChannelsBySeverity')->once()->with('critical')->andReturn([$telegram,$webhook]);
        $notification->shouldReceive('logHistory')->twice()->withArgs(static fn(int $id,string $type,string $title,string $message,string $status): bool => $type==='alert' && $status==='sent');
        $adapter = $this->logNotificationAdapter($notification);
        $adapter->sendAlert('Runtime alert','Provider contract','critical');
        $this->assertSame(3, $this->requestCount('retry','telegram'));
        $this->assertSame(1, $this->requestCount('success','webhook'));
        $telegramRequest = $this->lastRequest('retry','telegram');
        $this->assertSame('-100123456', $telegramRequest['post']['chat_id'] ?? null);
        $this->assertStringContainsString('Runtime alert', str_value($telegramRequest['post']['text'] ?? ''));
        $webhookRequest = $this->lastRequest('success','webhook');
        $payload = $this->decodeArray($webhookRequest['body']);
        $this->assertSame('critical', $payload['severity'] ?? null);

        $this->clearState();
        $blocked = (object)['id'=>3,'channel_type'=>'webhook','config'=>json_encode(['url'=>'http://127.0.0.1:8092/success/webhook/blocked'], JSON_THROW_ON_ERROR)];
        $blockedModel = m::mock(\App\Models\Notification::class);
        $blockedModel->shouldReceive('getActiveChannelsBySeverity')->once()->andReturn([$blocked]);
        $blockedModel->shouldReceive('logHistory')->once()->with(3,'alert','Blocked','No transport','failed');
        $this->logNotificationAdapter($blockedModel)->sendAlert('Blocked','No transport','high');
        $this->assertSame(0, $this->requestCount('success','webhook'));
    }

    public function test_deepface_success_and_multipart_authorization_contract(): void
    {
        config_set('services.deepface.api_url', $this->baseUrl . '/success/deepface');
        config_set('services.deepface.api_token', 'fake-ai-token');
        config_set('services.deepface.timeout', 2);
        config_set('services.deepface.verify_ssl', false);
        $file = $this->tempImage();
        try {
            $result = (new DeepFaceKycAdapter($this->logger, $this->circuit))->analyzeImage($file);
            $this->assertTrue((bool) ($result['success'] ?? false), (json_encode($result) ?: ''));
            $this->assertTrue((bool) ($result['is_valid'] ?? false));
            $this->assertSame(0.987, $result['confidence'] ?? null);
            $request = $this->lastRequest('success', 'deepface');
            $this->assertSame('Bearer fake-ai-token', $request['headers']['Authorization'] ?? null);
            $this->assertSame('contract-face.png', $request['files']['image']['name'] ?? null);
            $this->assertGreaterThan(0, (int) ($request['files']['image']['size'] ?? 0));
        } finally {
            @unlink($file);
        }
    }

    public function test_deepface_retries_503_and_malformed_json_fails_safe(): void
    {
        $file = $this->tempImage();
        try {
            config_set('services.deepface.api_url', $this->baseUrl . '/retry/deepface');
            config_set('services.deepface.api_token', 'fake-ai-token');
            config_set('services.deepface.timeout', 2);
            config_set('services.deepface.verify_ssl', false);
            $retry = (new DeepFaceKycAdapter($this->logger, $this->circuit))->analyzeImage($file);
            $this->assertTrue((bool) ($retry['success'] ?? false), (json_encode($retry) ?: ''));
            $this->assertSame(3, $this->requestCount('retry', 'deepface'));

            $this->clearState();
            config_set('services.deepface.api_url', $this->baseUrl . '/malformed/deepface');
            $malformed = (new DeepFaceKycAdapter($this->logger, $this->circuit))->analyzeImage($file);
            $this->assertFalse((bool) ($malformed['success'] ?? true), (json_encode($malformed) ?: ''));
            $this->assertTrue((bool) ($malformed['is_valid'] ?? false), 'Malformed AI response must fall back to manual review.');
            $this->assertSame(1, $this->requestCount('malformed', 'deepface'));
        } finally {
            @unlink($file);
        }
    }

    public function test_provider_adapters_reject_loopback_urls_before_http_call(): void
    {
        config_set('payment.idpay.api_url', 'http://127.0.0.1:8092/success/idpay/v1.1');
        $this->assertFalse((bool) ($this->idPayGateway()->createPayment('12500', 'ssrf', 'https://merchant.example/callback')['success'] ?? true));

        config_set('services.jibit.api_key', 'fake-jibit-key');
        config_set('services.jibit.api_secret', 'fake-jibit-secret');
        config_set('services.jibit.base_url', 'http://127.0.0.1:8092/success/jibit/v1/');
        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(null);
        $jibitBreaker = m::mock(\App\Contracts\CircuitBreakerInterface::class);
        $jibitBreaker->shouldReceive('call')->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        $jibit = new JibitInquiryAdapter($this->logger, $cache, $jibitBreaker);
        $this->assertFalse((bool) ($jibit->inquireIban('IR820540102680020817909002')['success'] ?? true));

        config_set('services.deepface.api_url', 'http://127.0.0.1:8092/success/deepface');
        config_set('services.deepface.api_token', 'fake-ai-token');
        $image = $this->tempImage();
        try {
            $this->assertFalse((bool)((new DeepFaceKycAdapter($this->logger, $this->circuit))->analyzeImage($image)['success'] ?? true));
        } finally {
            @unlink($image);
        }

        config_set('payment.zarinpal.api_url', 'http://127.0.0.1:8092/success/zarinpal/v4/payment');
        $this->assertFalse((bool)($this->zarinPalGateway()->createPayment('12500', 'ssrf', 'https://merchant.example/callback')['success'] ?? true));
        config_set('payment.nextpay.token_url', 'http://127.0.0.1:8092/success/nextpay/token');
        $this->assertFalse((bool)($this->nextPayGateway()->createPayment('12500', 'ssrf', 'https://merchant.example/callback')['success'] ?? true));
        config_set('payment.dgpay.request_url', 'http://127.0.0.1:8092/success/dgpay/request');
        $this->assertFalse((bool)($this->dgPayGateway()->createPayment('12500', 'ssrf', 'https://merchant.example/callback')['success'] ?? true));

        $this->assertSame(0, $this->requestCount('success', 'idpay'));
        $this->assertSame(0, $this->requestCount('success', 'jibit'));
        $this->assertSame(0, $this->requestCount('success', 'deepface'));
        $this->assertSame(0, $this->requestCount('success', 'zarinpal'));
        $this->assertSame(0, $this->requestCount('success', 'nextpay'));
        $this->assertSame(0, $this->requestCount('success', 'dgpay'));
    }

    private function idPayGateway(): IDPayGateway
    {
        $model = m::mock(PaymentGateway::class);
        $model->shouldReceive('getActiveGateway')->with('idpay')->andReturn((object) [
            'api_key' => 'fake-idpay-key',
            'is_test_mode' => true,
        ]);
        return new IDPayGateway($model, $this->circuit, $this->logger);
    }

    private function captchaService(): CaptchaService
    {
        $settings=m::mock(\App\Services\Settings\AppSettings::class);
        $settings->shouldReceive('get')->andReturnUsing(static fn(string $key,mixed $default=null)=>match($key){
            'recaptcha_secret_key'=>'fake-recaptcha-secret','recaptcha_v3_threshold'=>0.5,default=>$default,
        });
        return new CaptchaService(
            $this->logger,
            $this->lenientMock(\App\Models\CaptchaLog::class),
            $settings,
            $this->lenientMock(\Core\Session::class),
            new \Core\PathResolver(dirname(__DIR__, 2)),
            $this->circuit
        );
    }

    /** @return array{string,array{keys:list<array<string,string>>}} */
    private function googleJwtFixture(string $kid): array
    {
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $details=openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $rsa=is_array($details['rsa']??null)?$details['rsa']:[];
        $n=$this->base64Url(str_value($rsa['n']??''));
        $e=$this->base64Url(str_value($rsa['e']??''));
        $header=$this->base64Url(json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>$kid],JSON_THROW_ON_ERROR));
        $payload=$this->base64Url(json_encode([
            'iss'=>'https://accounts.google.com','aud'=>'runtime-client','sub'=>'google-user-1',
            'email'=>'contract@example.test','iat'=>time(),'exp'=>time()+3600,
        ],JSON_THROW_ON_ERROR));
        $input=$header.'.'.$payload; $signature='';
        $this->assertTrue(openssl_sign($input,$signature,$key,OPENSSL_ALGO_SHA256));
        return [$input.'.'.$this->base64Url($signature),['keys'=>[[
            'kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>$kid,'n'=>$n,'e'=>$e,
        ]]]];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private function logNotificationAdapter(\App\Models\Notification $notification): LogNotificationAdapter
    {
        $orchestrator = m::mock(NotificationOrchestrator::class);
        $orchestrator->shouldReceive('logger')->andReturn($this->logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($this->circuit);
        return new LogNotificationAdapter(
            $notification,
            m::mock(\App\Models\SystemTelemetryModel::class),
            $this->lenientMock(Logger::class),
            $this->circuit,
            $orchestrator
        );
    }

    private function configureVandar(string $scenario): void
    {
        config_set('services.vandar.api_token', 'fake-vandar-token');
        config_set('services.vandar.business', 'runtime-business');
        config_set('services.vandar.base_url', $this->baseUrl . '/' . $scenario . '/vandar');
        config_set('services.vandar.timeout', 2);
    }

    private function vandarAdapter(): VandarInquiryAdapter
    {
        $cache = m::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->byDefault()->andReturn(true);
        $breaker = m::mock(\App\Contracts\CircuitBreakerInterface::class);
        $breaker->shouldReceive('call')->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        return new VandarInquiryAdapter($this->logger, $cache, $breaker);
    }

    private function cryptoApiAdapter(string $scenario, bool $loopback = false): CryptoApiAdapter
    {
        $base = $loopback ? 'http://127.0.0.1:8092' : $this->baseUrl;
        config_set('services.crypto.tronscan_transaction_url', $base.'/'.$scenario.'/crypto/tron_tx/%s');
        config_set('services.crypto.trongrid_events_url', $base.'/'.$scenario.'/crypto/tron_events/%s');
        config_set('services.crypto.tronscan_status_url', $base.'/'.$scenario.'/crypto/tron_status');
        config_set('services.crypto.trongrid_block_url', $base.'/'.$scenario.'/crypto/tron_block');
        config_set('services.crypto.bscscan_url', $base.'/'.$scenario.'/crypto/bsc/%s');
        config_set('services.crypto.bscscan_fallback_url', $base.'/'.$scenario.'/crypto/bsc_fallback/%s');
        config_set('services.crypto.toncenter_url', $base.'/'.$scenario.'/crypto/ton');
        config_set('services.crypto.toncenter_fallback_url', $base.'/'.$scenario.'/crypto/ton_fallback');
        $settings = m::mock(\App\Services\Settings\AppSettings::class);
        $settings->shouldReceive('get')->andReturnUsing(static function(string $key, mixed $default = null) use ($base, $scenario) {
            return match ($key) {
                'solana_rpc_url' => $base.'/'.$scenario.'/crypto/solana',
                'bsc_rpc_url' => $base.'/'.$scenario.'/crypto/bsc_rpc',
                'crypto_api_timeout', 'solana_api_timeout' => 2,
                'crypto_min_confirmations_trc20', 'crypto_min_confirmations_bnb20' => 12,
                'crypto_provider_requests_per_minute' => 120,
                'crypto_api_allowed_hosts' => '',
                default => $default,
            };
        });
        $breaker = m::mock(CircuitBreaker::class);
        $breaker->shouldReceive('call')->andReturnUsing(static fn(string $name, callable $operation) => $operation());
        return new CryptoApiAdapter($this->logger, $settings, $breaker);
    }

    private function fcmAdapter(
        string $dispatchScenario,
        CacheInterface $cache,
        \Core\Database $db,
        string $oauthScenario = 'success'
    ): FcmNotificationAdapter {
        config_set('services.fcm.project_id', 'runtime-project');
        config_set('services.fcm.service_account_json', $this->fcmServiceAccount());
        config_set('services.fcm.oauth_url', $this->baseUrl . '/' . $oauthScenario . '/fcm/oauth');
        config_set('services.fcm.endpoint', $this->baseUrl . '/' . $dispatchScenario . '/fcm/v1/projects/%s/messages_send');
        $orchestrator = m::mock(NotificationOrchestrator::class);
        $orchestrator->shouldReceive('logger')->andReturn($this->logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($this->circuit);
        $orchestrator->shouldReceive('cache')->andReturn($cache);
        $metrics = m::mock(\App\Contracts\MetricsCollectorInterface::class);
        $metrics->shouldIgnoreMissing();
        return new FcmNotificationAdapter($metrics, $orchestrator, $db);
    }

    private function fcmServiceAccount(): string
    {
        if ($this->fcmServiceAccountFile !== null) return $this->fcmServiceAccountFile;
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $path = sys_get_temp_dir() . '/fcm-contract-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode([
            'client_email'=>'contract-fcm@runtime-project.iam.gserviceaccount.com',
            'private_key'=>$privateKey,
        ], JSON_THROW_ON_ERROR));
        $this->fcmServiceAccountFile = $path;
        return $path;
    }

    private function smsAdapter(string $provider, string $scenario): SmsNotificationAdapter
    {
        config_set('services.sms.enabled', true);
        config_set('services.sms.provider', $provider);
        config_set('services.sms.api_key', 'fake-sms-key');
        config_set('services.sms.username', 'fake-sms-user');
        config_set('services.sms.from', '10001234');
        config_set('services.sms.' . $provider . '_base_url', $this->baseUrl . '/' . $scenario . '/' . $provider);
        return $this->newSmsAdapter();
    }

    private function newSmsAdapter(): SmsNotificationAdapter
    {
        $coreLogger = m::mock(Logger::class);
        $coreLogger->shouldIgnoreMissing();
        $orchestrator = m::mock(NotificationOrchestrator::class);
        $orchestrator->shouldReceive('logger')->andReturn($this->logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($this->circuit);
        return new SmsNotificationAdapter(
            m::mock(\App\Models\User::class),
            $coreLogger,
            $this->circuit,
            $orchestrator,
            m::mock(OutboxServiceInterface::class),
            m::mock(Queue::class)
        );
    }

    private function zarinPalGateway(): ZarinPalGateway
    {
        $model = m::mock(PaymentGateway::class);
        $model->shouldReceive('getActiveGateway')->with('zarinpal')->andReturn((object)[
            'merchant_id'=>'fake-zarinpal-merchant','is_test_mode'=>false,
        ]);
        return new ZarinPalGateway($model, $this->circuit, $this->logger);
    }

    private function nextPayGateway(): NextPayGateway
    {
        $model = m::mock(PaymentGateway::class);
        $model->shouldReceive('getActiveGateway')->with('nextpay')->andReturn((object)[
            'api_key'=>'fake-nextpay-key','is_test_mode'=>false,
        ]);
        return new NextPayGateway($model, $this->circuit, $this->logger);
    }

    private function dgPayGateway(): DgPayGateway
    {
        $model = m::mock(PaymentGateway::class);
        $model->shouldReceive('getActiveGateway')->with('dgpay')->andReturn((object)[
            'merchant_id'=>'fake-dgpay-merchant','is_test_mode'=>false,
        ]);
        return new DgPayGateway($model, $this->circuit, $this->logger);
    }

    private function configureZarinPal(string $scenario): void
    {
        config_set('payment.zarinpal.api_url', $this->baseUrl . '/' . $scenario . '/zarinpal/v4/payment');
        config_set('payment.zarinpal.payment_url', 'https://gateway.example.test/zarinpal/pay');
    }

    private function configureNextPay(string $scenario): void
    {
        config_set('payment.nextpay.token_url', $this->baseUrl . '/' . $scenario . '/nextpay/token');
        config_set('payment.nextpay.verify_url', $this->baseUrl . '/' . $scenario . '/nextpay/verify');
        config_set('payment.nextpay.payment_url', 'https://gateway.example.test/nextpay/pay');
    }

    private function configureDgPay(string $scenario): void
    {
        config_set('payment.dgpay.request_url', $this->baseUrl . '/' . $scenario . '/dgpay/request');
        config_set('payment.dgpay.verify_url', $this->baseUrl . '/' . $scenario . '/dgpay/verify');
        config_set('payment.dgpay.payment_url', 'https://gateway.example.test/dgpay/pay');
    }

    private function configurePaymentProvider(string $provider, string $scenario): void
    {
        match ($provider) {
            'zarinpal' => $this->configureZarinPal($scenario),
            'nextpay' => $this->configureNextPay($scenario),
            'dgpay' => $this->configureDgPay($scenario),
            default => throw new \InvalidArgumentException('Unknown payment provider fixture'),
        };
    }

    private function paymentGateway(string $provider): \App\Contracts\PaymentGatewayInterface
    {
        return match ($provider) {
            'zarinpal' => $this->zarinPalGateway(),
            'nextpay' => $this->nextPayGateway(),
            'dgpay' => $this->dgPayGateway(),
            default => throw new \InvalidArgumentException('Unknown payment provider fixture'),
        };
    }

    private function clearState(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }
    }

    /** @return array<int|string, mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<int|string, mixed> */
    private function requireArray(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    private function requestCount(string $scenario, string $provider): int
    {
        $sum = 0;
        foreach (glob($this->stateDir . '/' . $scenario . '_' . $provider . '_*.count') ?: [] as $file) {
            $sum += (int) file_get_contents($file);
        }
        return $sum;
    }

    private function requestCountForOperation(string $scenario, string $provider, string $operationFragment): int
    {
        $sum = 0;
        foreach (glob($this->stateDir . '/' . $scenario . '_' . $provider . '_*' . $operationFragment . '*.count') ?: [] as $file) {
            $sum += (int)file_get_contents($file);
        }
        return $sum;
    }

    /** @return ProviderRequest */
    private function lastRequest(string $scenario, string $provider): array
    {
        $files = glob($this->stateDir . '/' . $scenario . '_' . $provider . '_*.last.json') ?: [];
        $this->assertNotEmpty($files, "No fake request recorded for {$scenario}/{$provider}");
        usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $path = end($files);
        $this->assertIsString($path);
        $json = file_get_contents($path);
        $this->assertIsString($json);
        /** @var ProviderRequest $request */
        $request = $this->decodeArray($json);
        return $request;
    }

    /** @return ProviderRequest */
    private function lastRequestMatching(string $scenario, string $provider, string $operationFragment): array
    {
        $files = glob($this->stateDir . '/' . $scenario . '_' . $provider . '_*' . $operationFragment . '*.last.json') ?: [];
        $this->assertNotEmpty($files, "No matching request recorded for {$operationFragment}");
        $path = end($files);
        $this->assertIsString($path);
        $json = file_get_contents($path);
        $this->assertIsString($json);
        /** @var ProviderRequest $request */
        $request = $this->decodeArray($json);
        return $request;
    }

    private function tempImage(): string
    {
        $path = sys_get_temp_dir() . '/contract-face.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        file_put_contents($path, $png);
        return $path;
    }
}
