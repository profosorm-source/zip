<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\BaseController;

/**
 * Structural tests for root-level controllers (not User/Admin/Api)
 */
/**
 * @group architecture
 */
class RootControllersStructuralTest extends TestCase
{
    /** @return list<string> */
    private function rootControllers(): array
    {
        return [
            'App\Controllers\BannerController',
            'App\Controllers\CaptchaController',
            'App\Controllers\ContactController',
            'App\Controllers\FaviconController',
            'App\Controllers\FileController',
            'App\Controllers\HomeController',
            'App\Controllers\MetricsController',
            'App\Controllers\OAuthController',
            'App\Controllers\PageController',
            'App\Controllers\PaymentController',
            'App\Controllers\RobotsController',
            'App\Controllers\SearchController',
            'App\Controllers\SitemapController',
        ];
    }

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

    // ── Critical method existence ───────────────────────────

    public function testHomeControllerHasIndex(): void
    {
        $this->assertTrue(method_exists('App\Controllers\HomeController', 'index'));
    }

    public function testPaymentControllerHasRequest(): void
    {
        $ref = new \ReflectionClass('App\Controllers\PaymentController');
        $this->assertTrue($ref->hasMethod('request'));
        $this->assertTrue($ref->hasMethod('callback'));
    }

    public function testOAuthControllerHasProviders(): void
    {
        $ref = new \ReflectionClass('App\Controllers\OAuthController');
        $this->assertTrue($ref->hasMethod('loginGoogle'));
        $this->assertTrue($ref->hasMethod('callbackGoogle'));
    }

    public function testContactControllerHasSend(): void
    {
        $this->assertTrue(method_exists('App\Controllers\ContactController', 'send'));
    }

    public function testSearchControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\SearchController');
        $this->assertTrue($ref->hasMethod('userSearch') || $ref->hasMethod('adminSearch'));
    }

    public function testCaptchaControllerHasRefresh(): void
    {
        $this->assertTrue(method_exists('App\Controllers\CaptchaController', 'refresh'));
    }

    public function testPageControllerHasStaticPages(): void
    {
        $ref = new \ReflectionClass('App\Controllers\PageController');
        foreach (['show', 'about', 'terms', 'privacy'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "PageController.$m missing");
        }
    }

    public function testMetricsControllerHasMethods(): void
    {
        $ref = new \ReflectionClass('App\Controllers\MetricsController');
        $this->assertTrue($ref->hasMethod('metrics') || $ref->hasMethod('health'));
    }

    public function testSitemapControllerHasIndex(): void
    {
        $this->assertTrue(method_exists('App\Controllers\SitemapController', 'index'));
    }

    public function testRobotsControllerHasIndex(): void
    {
        $this->assertTrue(method_exists('App\Controllers\RobotsController', 'index'));
    }

    // ── Provider ────────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public function controllerClassProvider(): array
    {
        $result = [];
        foreach ($this->rootControllers() as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $result[$short] = [$class];
        }
        return $result;
    }
}
