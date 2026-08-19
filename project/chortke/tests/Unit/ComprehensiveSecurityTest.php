<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Validators\PasswordPolicy;
use Core\CSRF;
use Core\Encryption;
use Core\Request;
use Core\Session;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/** Executable security contracts; HTTP headers/upload/authorization are covered in E2E. */
final class ComprehensiveSecurityTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_action_csrf_token_is_random_tamper_resistant_and_one_time(): void
    {
        /** @var array<string,mixed> $state */
        $state=[];
        $session=m::mock(Session::class);
        $session->shouldReceive('has')->andReturnUsing(static function(string $key) use (&$state): bool{return array_key_exists($key,$state);});
        $session->shouldReceive('set')->andReturnUsing(static function(string $key,mixed $value) use (&$state): void{$state[$key]=$value;});
        $session->shouldReceive('get')->andReturnUsing(static function(string $key) use (&$state){return $state[$key]??null;});
        $session->shouldReceive('remove')->andReturnUsing(static function(string $key) use (&$state): void{unset($state[$key]);});
        $csrf=new CSRF(
            $session,
            $this->lenientMock(Request::class),
            new \Core\UrlGenerator('http://127.0.0.1:8090', null, null, 'testing')
        );
        $token=$csrf->generateTokenFor('withdraw:91');
        $this->assertSame(64,strlen($token));
        $this->assertFalse($csrf->verifyTokenFor('withdraw:91',$token.'tampered'));
        $this->assertTrue($csrf->verifyTokenFor('withdraw:91',$token));
        $this->assertFalse($csrf->verifyTokenFor('withdraw:91',$token));
    }

    public function test_output_escape_encodes_tags_quotes_and_ampersands(): void
    {
        $escaped=e('<script data-x="1">a&b</script>');
        $this->assertStringNotContainsString('<script',$escaped);
        $this->assertStringContainsString('&lt;script',$escaped);
        $this->assertStringContainsString('&quot;',$escaped);
        $this->assertStringContainsString('&amp;',$escaped);
    }

    public function test_password_policy_rejects_weak_and_accepts_complex_unrelated_password(): void
    {
        $this->assertNotSame([],PasswordPolicy::validate('password',['email'=>'user@example.test']));
        $this->assertSame([],PasswordPolicy::validate('N7!vQ2#zL9@pR4$x',['email'=>'user@example.test','username'=>'runtime_user']));
        $this->assertGreaterThanOrEqual(80,PasswordPolicy::strength('N7!vQ2#zL9@pR4$x'));
    }

    public function test_aead_encryption_round_trips_context_and_rejects_tampering(): void
    {
        $encryption=new Encryption();
        $ciphertext=$encryption->encrypt('secret-value','security.contract');
        $this->assertNotSame('secret-value',$ciphertext);
        $this->assertSame('secret-value',$encryption->decrypt($ciphertext));
        $tampered=substr($ciphertext,0,-2).'XX';
        $this->expectException(\RuntimeException::class);
        $encryption->decrypt($tampered);
    }
}
