<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست اصلاحات ۱ تا ۶ ماژول Sentry
 */
/**
 * @group architecture
 */
class SentryModuleFix1to6Test extends TestCase
{
    // ─── Fix 1: SentryModel — Logger & AppSettings injection ───────────────

    public function testSentryModelConstructorRequiresLoggerAndAppSettings(): void
    {
        $ref = new \ReflectionClass(\App\Models\SentryModel::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();

        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('db', $names, 'SentryModel باید Database inject کنه');
        $this->assertContains('logger', $names, 'SentryModel باید Logger inject کنه');
        $this->assertContains('appSettings', $names, 'SentryModel باید AppSettings inject کنه');
    }

    public function testSentryModelHasLoggerProperty(): void
    {
        $ref = new \ReflectionClass(\App\Models\SentryModel::class);
        $this->assertTrue($ref->hasProperty('logger'), 'SentryModel باید پراپرتی logger داشته باشه');
        $prop = $ref->getProperty('logger');
        $this->assertTrue($prop->isPrivate(), 'logger باید private باشه');
    }

    public function testSentryModelHasAppSettingsProperty(): void
    {
        $ref = new \ReflectionClass(\App\Models\SentryModel::class);
        $this->assertTrue($ref->hasProperty('appSettings'), 'SentryModel باید پراپرتی appSettings داشته باشه');
    }

    // ─── Fix 2: EscalationManager.getStatistics — return type ──────────────

    public function testGetEscalationStatisticsReturnsObject(): void
    {
        $ref = new \ReflectionMethod(\App\Models\SentryModel::class, 'getEscalationStatistics');
        $returnType = $ref->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType, 'getEscalationStatistics باید return type داشته باشه');
        $this->assertEquals('object', $returnType->getName(), 'باید object برگردونه نه array');
    }


    // ─── Fix 3: getAuditCount — alias 'at' ────────────────────────────────


    // ─── Fix 4: DatabaseErrorReporter — userId in interface ────────────────

    public function testDatabaseErrorReporterCaptureExceptionHasUserId(): void
    {
        $ref = new \ReflectionMethod(\App\Contracts\DatabaseErrorReporter::class, 'captureException');
        $params = $ref->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains('userId', $names, 'captureException باید userId داشته باشه');

        $userIdParam = $params[array_search('userId', $names)];
        $this->assertTrue($userIdParam->isDefaultValueAvailable(), 'userId باید optional باشه');
        $this->assertNull($userIdParam->getDefaultValue(), 'default userId باید null باشه');
    }

    public function testDatabaseErrorReporterCaptureMessageHasUserId(): void
    {
        $ref = new \ReflectionMethod(\App\Contracts\DatabaseErrorReporter::class, 'captureMessage');
        $params = $ref->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains('userId', $names, 'captureMessage باید userId داشته باشه');
    }

    public function testSentryErrorMonitorImplementsInterface(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertTrue(
            $ref->implementsInterface(\App\Contracts\DatabaseErrorReporter::class),
            'SentryErrorMonitor باید DatabaseErrorReporter رو implement کنه'
        );
    }

    // ─── Fix 5: Missing methods in Controller dependencies ─────────────────

    public function testDashboardServiceHasGetTopErrorSources(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\Sentry\Analytics\DashboardService::class, 'getTopErrorSources'),
            'DashboardService باید متد getTopErrorSources داشته باشه'
        );
    }

    public function testAlertRulesEngineHasGetActiveAlerts(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\Sentry\Alerting\AlertRulesEngine::class, 'getActiveAlerts'),
            'AlertRulesEngine باید متد getActiveAlerts داشته باشه'
        );
    }

    public function testAlertRulesEngineHasGetAlertRules(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\Sentry\Alerting\AlertRulesEngine::class, 'getAlertRules'),
            'AlertRulesEngine باید متد getAlertRules داشته باشه'
        );
    }

    public function testSentryModelHasGetActiveAlerts(): void
    {
        $this->assertTrue(
            method_exists(\App\Models\SentryModel::class, 'getActiveAlerts'),
            'SentryModel باید متد getActiveAlerts داشته باشه'
        );
    }

    public function testSentryModelHasGetTopErrorSources(): void
    {
        $this->assertTrue(
            method_exists(\App\Models\SentryModel::class, 'getTopErrorSources'),
            'SentryModel باید متد getTopErrorSources داشته باشه'
        );
    }

    // ─── Fix 6: TrendAnalyzer column mapping ───────────────────────────────


    // ─── Cross-check: SentryAdminController dependencies completeness ──────

}
