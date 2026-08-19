<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست OAuth Jobs — متدهای گمشده + SessionKeys import
 */
/**
 * @group architecture
 */
class AuthOAuthJobsFixTest extends TestCase
{
    public function testOAuthHelperTraitExists(): void
    {
        $this->assertTrue(
            trait_exists(\App\Jobs\Auth\OAuthHelperTrait::class),
            'OAuthHelperTrait باید وجود داشته باشه'
        );
    }

    public function testOAuthHelperTraitHasRequiredMethods(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\OAuthHelperTrait::class);
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods());

        // clientIp و userAgent از ClientInfoTrait میان
        $required = ['clientIp', 'userAgent', 'matchIpSubnet', 'getGoogleToken', 'verifyGoogleIdToken',
                      'getFacebookToken', 'verifyFacebookAccessToken', 'getFacebookUserInfo',
                      'linkOrCreateUser', 'buildRedirectUri'];

        foreach ($required as $m) {
            $this->assertContains($m, $methods, "OAuthHelperTrait باید متد {$m} داشته باشه");
        }
    }


    public function testTwoFactorServiceUsesClientInfoTrait(): void
    {
        $ref = new \ReflectionClass(\App\Services\Auth\TwoFactorService::class);
        $traits = array_keys($ref->getTraits());
        $this->assertContains(
            \App\Traits\ClientInfoTrait::class, $traits,
            'TwoFactorService باید از ClientInfoTrait استفاده کنه'
        );
    }

    public function testOAuthServiceUsesClientInfoTrait(): void
    {
        $ref = new \ReflectionClass(\App\Services\Auth\OAuthService::class);
        $traits = array_keys($ref->getTraits());
        $this->assertContains(
            \App\Traits\ClientInfoTrait::class, $traits,
            'OAuthService باید از ClientInfoTrait استفاده کنه'
        );
    }


    public function testHandleGoogleCallbackJobUsesTrait(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\HandleGoogleCallbackJob::class);
        $traits = array_keys($ref->getTraits());
        $this->assertContains(
            \App\Jobs\Auth\OAuthHelperTrait::class, $traits,
            'HandleGoogleCallbackJob باید OAuthHelperTrait use کنه'
        );
    }

    public function testHandleFacebookCallbackJobUsesTrait(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Auth\HandleFacebookCallbackJob::class);
        $traits = array_keys($ref->getTraits());
        $this->assertContains(
            \App\Jobs\Auth\OAuthHelperTrait::class, $traits,
            'HandleFacebookCallbackJob باید OAuthHelperTrait use کنه'
        );
    }




    public function testAllOAuthJobsLoadable(): void
    {
        $classes = [
            \App\Jobs\Auth\HandleGoogleCallbackJob::class,
            \App\Jobs\Auth\HandleFacebookCallbackJob::class,
            \App\Jobs\Auth\LinkSocialAccountJob::class,
            \App\Jobs\Auth\LinkSocialAccountSafeJob::class,
            \App\Jobs\Auth\UnlinkSocialAccountJob::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "کلاس {$class} باید loadable باشه");
        }
    }
}
