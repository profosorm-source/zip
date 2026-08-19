<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست اصلاحات ماژول Auth
 */
/**
 * @group architecture
 */
class AuthModuleFixTest extends TestCase
{
    // ─── Fix 1: ResetPasswordJob — passwordService inject ──────────────

    public function testResetPasswordJobInjectsPasswordService(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\ResetPasswordJob::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertNotNull($ctor, 'ResetPasswordJob باید constructor داشته باشه');

        $params = $ctor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('passwordService', $names,
            'ResetPasswordJob باید passwordService inject کنه');
    }

    public function testResetPasswordJobHasPasswordServiceProperty(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\ResetPasswordJob::class);
        $this->assertTrue($ref->hasProperty('passwordService'),
            'ResetPasswordJob باید پراپرتی passwordService داشته باشه');
    }

    // ─── Fix 2: ProcessRegistrationJob — userService inject ────────────

    public function testProcessRegistrationJobInjectsUserService(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\ProcessRegistrationJob::class);
        
        // Check if userService is injected via constructor OR #[Inject] property
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $ctorNames = [];
        if ($ctor) {
            $params = $ctor->getParameters();
            $ctorNames = array_map(fn($p) => $p->getName(), $params);
        }
        
        $hasProperty = $ref->hasProperty('userService');
        $hasCtorParam = in_array('userService', $ctorNames);
        
        // Check if the property has #[Inject] attribute
        $hasInject = false;
        if ($hasProperty) {
            $prop = $ref->getProperty('userService');
            $attrs = $prop->getAttributes(\Core\Attributes\Inject::class);
            $hasInject = count($attrs) > 0;
        }

        $this->assertTrue(
            $hasCtorParam || ($hasProperty && $hasInject),
            'ProcessRegistrationJob باید userService رو از طریق constructor یا #[Inject] property inject کنه'
        );
    }


    // ─── Fix 3: TwoFactorService — clientIp() method ───────────────────

    public function testTwoFactorServiceHasClientIpMethod(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\Auth\TwoFactorService::class, 'clientIp'),
            'TwoFactorService باید متد clientIp داشته باشه'
        );
    }


    // ─── Fix 4: ApiAuthMiddleware — logger inject ──────────────────────

    public function testApiAuthMiddlewareInjectsLogger(): void
    {
        $ref = new \ReflectionClass(\App\Middleware\ApiAuthMiddleware::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains('logger', $names,
            'ApiAuthMiddleware باید logger inject کنه');
    }

    public function testApiAuthMiddlewareHasLoggerProperty(): void
    {
        $ref = new \ReflectionClass(\App\Middleware\ApiAuthMiddleware::class);
        $this->assertTrue($ref->hasProperty('logger'),
            'ApiAuthMiddleware باید پراپرتی logger داشته باشه');
    }


    // ─── Fix 5: PasswordRecoveryService — no plaintext password ────────



    // ─── Fix 9: OAuthService — clientIp uses helper ────────────────────


    // ─── Cross-check: syntax validation via reflection ─────────────────

    public function testAllAuthFilesLoadable(): void
    {
        $classes = [
            \App\Jobs\Auth\ResetPasswordJob::class,
            \App\Jobs\Auth\ProcessRegistrationJob::class,
            \App\Services\Auth\TwoFactorService::class,
            \App\Middleware\ApiAuthMiddleware::class,
            \App\Services\Auth\PasswordRecoveryService::class,
            \App\Services\Auth\OAuthService::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                class_exists($class),
                "کلاس {$class} باید loadable باشه"
            );
        }
    }
}
