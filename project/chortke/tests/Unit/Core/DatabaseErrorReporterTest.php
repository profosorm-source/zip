<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست DIP refactor — Database.php دیگه به App\Services\Sentry وابسته نیست
 */
/**
 * @group architecture
 */
class DatabaseErrorReporterTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @test */
    public function database_error_reporter_interface_has_required_methods(): void
    {
        $ref = new \ReflectionClass(\App\Contracts\DatabaseErrorReporter::class);
        $this->assertTrue($ref->isInterface());
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods());
        $this->assertContains('captureException', $methods);
        $this->assertContains('captureMessage', $methods);
    }

    /** @test */
    public function sentry_error_monitor_implements_interface(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertTrue($ref->implementsInterface(\App\Contracts\DatabaseErrorReporter::class));
    }

    /** @test */
    public function database_set_error_reporter_accepts_interface(): void
    {
        $ref = new \ReflectionClass(\Core\Database::class);
        $method = $ref->getMethod('setErrorReporter');
        $param = $method->getParameters()[0];
        $type = $param->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertEquals('App\\Contracts\\DatabaseErrorReporter', $type->getName());
    }

    /** @test */
    public function database_has_no_set_sentry_monitor_method(): void
    {
        $ref = new \ReflectionClass(\Core\Database::class);
        $this->assertFalse($ref->hasMethod('setSentryMonitor'));
    }

    /** @test */
    public function database_has_no_get_sentry_monitor_method(): void
    {
        $ref = new \ReflectionClass(\Core\Database::class);
        $this->assertFalse($ref->hasMethod('getSentryMonitor'));
    }

    /** @test */
    public function capture_exception_interface_signature(): void
    {
        $ref = new \ReflectionClass(\App\Contracts\DatabaseErrorReporter::class);
        $method = $ref->getMethod('captureException');
        $params = $method->getParameters();

        $this->assertEquals('exception', $params[0]->getName());
        $this->assertEquals('context', $params[1]->getName());
        $this->assertEquals('level', $params[2]->getName());
    }

    /** @test */
    public function capture_message_interface_signature(): void
    {
        $ref = new \ReflectionClass(\App\Contracts\DatabaseErrorReporter::class);
        $method = $ref->getMethod('captureMessage');
        $params = $method->getParameters();

        $this->assertEquals('message', $params[0]->getName());
        $this->assertEquals('level', $params[1]->getName());
        $this->assertEquals('context', $params[2]->getName());
    }

}
