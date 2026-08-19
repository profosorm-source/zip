<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Auth\PasswordRecoveryService;
use App\Services\User\UserService;
use Core\RateLimiter;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class PasswordRecoveryServiceBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_password_verification_accepts_match_and_rejects_wrong_secret(): void
    {
        $service=$this->service();
        $hash=password_hash('test123',PASSWORD_DEFAULT);
        $this->assertTrue($service->verifyPassword('test123',$hash));
        $this->assertFalse($service->verifyPassword('wrong',$hash));
    }

    public function test_dummy_hash_is_valid_and_never_matches_attacker_input(): void
    {
        $hash=$this->service()->getDummyHash();
        $this->assertTrue(password_get_info($hash)['algo']!==null);
        $this->assertFalse(password_verify('attacker-controlled',$hash));
    }

    private function service(): PasswordRecoveryService
    {
        return new PasswordRecoveryService(
            $this->lenientMock(SecurityModel::class),$this->lenientMock(User::class),
            $this->lenientMock(UserService::class),$this->lenientMock(RateLimiter::class),
            $this->lenientMock(LoggerInterface::class),null
        );
    }
}
