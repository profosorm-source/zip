<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Validators\LoginRequest;
use PHPUnit\Framework\TestCase;

class LoginRequestTest extends TestCase
{
    public function testValidEmailAndPasswordPasses(): void
    {
        $data = [
            'email' => 'User@Example.com',
            'password' => 'my-secure-password'
        ];

        $request = new LoginRequest($data);

        $this->assertTrue($request->validate());
        $this->assertFalse($request->fails());
        
        $validated = $request->validated();
        $this->assertSame('user@example.com', $validated['email']); // Normalized to lowercase
        $this->assertSame('my-secure-password', $validated['password']);
    }

    public function testValidIdentifierAndPasswordPasses(): void
    {
        $data = [
            'identifier' => 'SomeUser',
            'password' => 'my-secure-password'
        ];

        $request = new LoginRequest($data);

        $this->assertTrue($request->validate());
        $this->assertFalse($request->fails());
        
        $validated = $request->validated();
        $this->assertSame('someuser', $validated['identifier']); // Normalized to lowercase
    }

    public function testMissingEmailAndIdentifierFails(): void
    {
        $data = [
            'password' => 'my-secure-password'
        ];

        $request = new LoginRequest($data);

        $this->assertFalse($request->validate());
        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('login', $request->errors());
    }

    public function testMissingPasswordFails(): void
    {
        $data = [
            'email' => 'user@example.com'
        ];

        $request = new LoginRequest($data);

        $this->assertFalse($request->validate());
        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('password', $request->errors());
    }

    public function testExtremelyLongEmailFails(): void
    {
        $data = [
            'email' => str_repeat('a', 256) . '@example.com',
            'password' => 'my-secure-password'
        ];

        $request = new LoginRequest($data);

        $this->assertFalse($request->validate());
        $this->assertTrue($request->fails());
    }

    public function testExtremelyLongPasswordFailsToPreventBcryptCpuExhaustion(): void
    {
        $data = [
            'email' => 'user@example.com',
            'password' => str_repeat('p', 256)
        ];

        $request = new LoginRequest($data);

        $this->assertFalse($request->validate());
        $this->assertTrue($request->fails());
    }
}
