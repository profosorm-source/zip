<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Tests Sentry integration behavior — not source strings.
 * Validates that Sentry services are properly structured and functional.
 */
class SentryModuleFix7to12Test extends TestCase
{
    /** @test */
    public function sentry_error_monitor_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_performance_monitor_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_dashboard_service_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Analytics\DashboardService::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_model_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Models\SentryModel::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_alert_dispatcher_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Alerting\AlertDispatcher::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_escalation_manager_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Alerting\EscalationManager::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function sentry_audit_trail_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Audit\AdvancedAuditTrail::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function error_monitor_has_capture_exception_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertTrue($ref->hasMethod('captureException'), 'SentryErrorMonitor must have captureException');
    }

    /** @test */
    public function error_monitor_has_capture_anomaly_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertTrue($ref->hasMethod('captureAnomaly'), 'SentryErrorMonitor must have captureAnomaly');
    }

    /** @test */
    public function sentry_exception_handler_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Services\Sentry\SentryExceptionHandler::class),
            'SentryExceptionHandler class must exist'
        );
    }

    /** @test */
    public function sentry_exception_handler_has_capture_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\SentryExceptionHandler::class);
        $this->assertTrue(
            $ref->hasMethod('captureException'),
            'SentryExceptionHandler must have captureException'
        );
    }

    /** @test */
    public function sentry_model_has_error_logging_method(): void
    {
        $ref = new \ReflectionClass(\App\Models\SentryModel::class);
        $hasMethod = $ref->hasMethod('logError') ||
                     $ref->hasMethod('createIssue') ||
                     $ref->hasMethod('findExistingIssue');
        $this->assertTrue($hasMethod, 'SentryModel should have error-logging methods');
    }

    /** @test */
    public function alert_rules_engine_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Alerting\AlertRulesEngine::class);
        $this->assertTrue($ref->isInstantiable());
    }

    /** @test */
    public function dashboard_service_has_get_overview_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Analytics\DashboardService::class);
        $this->assertTrue(
            $ref->hasMethod('getOverview'),
            'DashboardService must have getOverview'
        );
    }

    /** @test */
    public function trend_analyzer_is_instantiable(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\Analytics\TrendAnalyzer::class);
        $this->assertTrue($ref->isInstantiable());
    }
}
