<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Api;

use PHPUnit\Framework\TestCase;
use App\Controllers\BaseController;

/**
 * Structural + security tests for ALL API controllers
 */
/**
 * @group architecture
 */
class ApiControllersStructuralTest extends TestCase
{
    /** @return list<string> */
    private function apiControllers(): array
    {
        return [
            'App\Controllers\Api\AppConfigController',
            'App\Controllers\Api\FingerprintController',
            'App\Controllers\Api\HealthCheckController',
            'App\Controllers\Api\InfluencerController',
            'App\Controllers\Api\InteractionApiController',
            'App\Controllers\Api\RealTimeController',
            'App\Controllers\Api\SecurityController',
            'App\Controllers\Api\SocialTaskApiController',
            'App\Controllers\Api\TokenController',
            'App\Controllers\Api\UserController',
            'App\Controllers\Api\VerificationController',
            'App\Controllers\Api\WalletController',
        ];
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerClassExists(string $class): void
    {
        $this->assertTrue(class_exists($class), "$class باید loadable باشه");
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerExtendsBase(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $this->assertTrue(
            $ref->isSubclassOf(BaseController::class),
            "$class باید BaseController extend کنه"
        );
    }

    /** @dataProvider controllerClassProvider */
    public function testControllerNotAbstract(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $this->assertFalse((new \ReflectionClass($class))->isAbstract());
    }

    /** @dataProvider controllerClassProvider */
    public function testNoPrivatePropertyOverridesParentProtected(string $class): void
    {
        if (!class_exists($class)) {
            $this->fail("$class not loadable");
        }
        $ref = new \ReflectionClass($class);
        $parent = $ref->getParentClass();
        if (!$parent) {
            $this->assertTrue(true);
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

        $this->assertEmpty($bad, "$class private override: " . implode(', ', $bad));
    }

    // ── Critical API methods exist ──────────────────────────

    public function testTokenControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\TokenController');
        $this->assertTrue($ref->hasMethod('create') || $ref->hasMethod('issue') || $ref->hasMethod('store'),
            'TokenController باید token creation داشته باشه');
    }

    public function testWalletControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\WalletController');
        $this->assertTrue($ref->hasMethod('balance') || $ref->hasMethod('index'),
            'WalletController باید balance query داشته باشه');
    }

    public function testInfluencerControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\InfluencerController');
        $this->assertTrue($ref->hasMethod('getList'));
        $this->assertTrue($ref->hasMethod('createOrder'));
    }

    public function testSocialTaskApiControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\SocialTaskApiController');
        $this->assertTrue($ref->hasMethod('tasks'));
        $this->assertTrue($ref->hasMethod('startTask'));
        $this->assertTrue($ref->hasMethod('submitTask'));
    }

    public function testVerificationControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\VerificationController');
        $this->assertTrue($ref->hasMethod('generateCode'));
        $this->assertTrue($ref->hasMethod('getStatus'));
        $this->assertTrue($ref->hasMethod('submitProof'));
    }

    public function testBaseApiControllerStructure(): void
    {
        $ref = new \ReflectionClass('App\Controllers\Api\BaseApiController');
        $this->assertTrue($ref->isSubclassOf(BaseController::class));
        foreach (['success','error','validationError','paginationParams'] as $method) {
            $this->assertTrue($ref->hasMethod($method));
        }
    }

    // ── Provider ────────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public function controllerClassProvider(): array
    {
        $result = [];
        foreach ($this->apiControllers() as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $result[$short] = [$class];
        }
        return $result;
    }
}
