<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\User;

use PHPUnit\Framework\TestCase;
use App\Controllers\BaseController;

/**
 * Structural + security tests for ALL User controllers
 * (excluding Auth, Wallet, Withdrawal which have dedicated tests)
 */
/**
 * @group architecture
 */
class UserControllersStructuralTest extends TestCase
{
    /** All User controller classes (non-base, non-already-tested) */
    /** @return list<string> */
    private function userControllers(): array
    {
        return [
            'App\Controllers\User\AdsController',
            'App\Controllers\User\AdtubeController',
            'App\Controllers\User\ApiTokenController',
            'App\Controllers\User\BankCardController',
                        'App\Controllers\User\BugReportController',
            'App\Controllers\User\ContentController',
            'App\Controllers\User\CouponController',
            'App\Controllers\User\CryptoDepositController',
            'App\Controllers\User\CustomTaskController',
            'App\Controllers\User\DashboardController',
            'App\Controllers\User\DisputeController',
            'App\Controllers\User\InfluencerController',
            'App\Controllers\User\InvestmentController',
            'App\Controllers\User\KYCController',
            'App\Controllers\User\LevelController',
            'App\Controllers\User\LotteryController',
            'App\Controllers\User\ManualDepositController',
            'App\Controllers\User\MessageController',
            'App\Controllers\User\NotificationController',
            'App\Controllers\User\PredictionController',
            'App\Controllers\User\ProfileController',
            'App\Controllers\User\ReferralController',
            'App\Controllers\User\SeoController',
            'App\Controllers\User\SessionController',
            'App\Controllers\User\SettingsController',
            'App\Controllers\User\SocialAccountController',
            'App\Controllers\User\SocialTaskController',
            'App\Controllers\User\TaskFeedController',
            'App\Controllers\User\TicketController',
            'App\Controllers\User\TwoFactorController',
            'App\Controllers\User\VitrineController',
        ];
    }

    // ── Structural ──────────────────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testControllerClassExists(string $class): void
    {
        $this->assertTrue(class_exists($class), "$class باید loadable باشه");
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerExtendsBaseController(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $this->assertTrue(
            $ref->isSubclassOf(BaseController::class),
            "$class باید BaseController یا subclass اون رو extend کنه"
        );
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerHasConstructor(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor, "$class باید constructor (خودش یا parent) داشته باشه");
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerNotAbstract(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $this->assertFalse($ref->isAbstract(), "$class نباید abstract باشه");
    }

    // ── Property visibility ─────────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testNoPrivatePropertyOverridesParentProtected(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $parent = $ref->getParentClass();
        if (!$parent) {
            return;
        }

        $parentProtected = [];
        foreach ($parent->getProperties(\ReflectionProperty::IS_PROTECTED) as $prop) {
            $parentProtected[] = $prop->getName();
        }

        $bad = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PRIVATE) as $prop) {
            if ($prop->getDeclaringClass()->getName() === $class
                && in_array($prop->getName(), $parentProtected, true)) {
                $bad[] = $prop->getName();
            }
        }

        $this->assertEmpty(
            $bad,
            "$class private property overrides parent protected: " . implode(', ', $bad)
        );
    }

    // ── Public methods are void ─────────────────────────────

    /** @dataProvider controllerClassProvider */
    public function testPublicMethodsReturnVoidOrResponse(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) continue;
            if ($method->getName() === '__construct') continue;

            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $typeName = $returnType->getName();
                // void, mixed, or response-like types are OK
                $allowed = ['void', 'mixed', 'string', 'array', 'never'];
                $this->assertTrue(
                    in_array($typeName, $allowed, true) || str_contains($typeName, 'Response'),
                    "$class::{$method->getName()} return type '$typeName' ممکنه مشکل باشه"
                );
            }
            // No return type = implicit mixed — acceptable
        }
        $this->assertTrue(true); // guard assertion
    }

    // ── Provider ────────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public function controllerClassProvider(): array
    {
        $result = [];
        foreach ($this->userControllers() as $class) {
            $parts = explode('\\', $class);
            $short = end($parts);
            $this->assertIsString($short);
            $result[$short] = [$class];
        }
        return $result;
    }
}
