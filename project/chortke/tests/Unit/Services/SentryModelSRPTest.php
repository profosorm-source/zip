<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\SentryModel;
use App\Contracts\Sentry\SentryErrorRepositoryInterface;
use App\Contracts\Sentry\SentryPerformanceRepositoryInterface;
use App\Contracts\Sentry\SentryAlertRepositoryInterface;
use App\Contracts\Sentry\SentryEscalationRepositoryInterface;
use App\Contracts\Sentry\SentryQueueRepositoryInterface;
use App\Contracts\Sentry\SentryAuditRepositoryInterface;
use App\Contracts\Sentry\SentryLogRepositoryInterface;

/**
 * تست SRP Refactor برای SentryModel
 *
 * این تست‌ها تضمین می‌کنند که:
 *  1. هر Interface در مسیر صحیح تعریف شده
 *  2. SentryModel همه Interface ها را implement می‌کند
 *  3. تمام متدهای اعلام‌شده در Interface موجود هستند
 *  4. Signature های متدها صحیح است
 */
class SentryModelSRPTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // ۱. SentryModel همه Interface ها را implement می‌کند
    // ──────────────────────────────────────────────────────────────

    public function test_SentryModel_implements_SentryErrorRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryErrorRepositoryInterface::class, true),
            'SentryModel باید SentryErrorRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryPerformanceRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryPerformanceRepositoryInterface::class, true),
            'SentryModel باید SentryPerformanceRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryAlertRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryAlertRepositoryInterface::class, true),
            'SentryModel باید SentryAlertRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryEscalationRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryEscalationRepositoryInterface::class, true),
            'SentryModel باید SentryEscalationRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryQueueRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryQueueRepositoryInterface::class, true),
            'SentryModel باید SentryQueueRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryAuditRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryAuditRepositoryInterface::class, true),
            'SentryModel باید SentryAuditRepositoryInterface را implement کند'
        );
    }

    public function test_SentryModel_implements_SentryLogRepositoryInterface(): void
    {
        $this->assertTrue(
            is_a(SentryModel::class, SentryLogRepositoryInterface::class, true),
            'SentryModel باید SentryLogRepositoryInterface را implement کند'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // ۲. Interface ها در namespace صحیح هستند
    // ──────────────────────────────────────────────────────────────

    public function test_all_sentry_interfaces_exist(): void
    {
        $interfaces = [
            SentryErrorRepositoryInterface::class,
            SentryPerformanceRepositoryInterface::class,
            SentryAlertRepositoryInterface::class,
            SentryEscalationRepositoryInterface::class,
            SentryQueueRepositoryInterface::class,
            SentryAuditRepositoryInterface::class,
            SentryLogRepositoryInterface::class,
        ];

        foreach ($interfaces as $interface) {
            $this->assertTrue(
                interface_exists($interface),
                "Interface {$interface} باید وجود داشته باشد"
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ۳. متدهای SentryErrorRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider errorRepositoryMethodsProvider */
    public function test_SentryModel_has_error_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function errorRepositoryMethodsProvider(): array
    {
        return [
            ['findExistingIssue'],
            ['createIssue'],
            ['updateIssueStats'],
            ['storeEventRecord'],
            ['getErrorStats'],
            ['getUserData'],
            ['getTrendingIssues'],
            ['getRecentSentryEvents'],
            ['getDailySummary'],
            ['getPreviousDaySummary'],
            ['getUptimeStatus'],
            ['getErrorDistributionByLevel'],
            ['getErrorTimeSeries'],
            ['getIssuesCount'],
            ['getIssuesPaged'],
            ['getIssueWithEvents'],
            ['resolveSentryIssue'],
            ['muteSentryIssue'],
            ['getErrorHistoricalData'],
            ['getErrorHotspots'],
            ['getTopErrorSources'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۴. متدهای SentryPerformanceRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider performanceRepositoryMethodsProvider */
    public function test_SentryModel_has_performance_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function performanceRepositoryMethodsProvider(): array
    {
        return [
            ['storePerformanceTransaction'],
            ['getPerformanceAggregates'],
            ['getSlowestTransactions'],
            ['getP95ResponseTime'],
            ['getHealthMetricsBundle'],
            ['getPerformanceStatsSummary'],
            ['getPerformanceTimeSeries'],
            ['getTopSlowestEndpoints'],
            ['getPerformanceHistoricalData'],
            ['getWeeklyPerformanceAvg'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۵. متدهای SentryAlertRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider alertRepositoryMethodsProvider */
    public function test_SentryModel_has_alert_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function alertRepositoryMethodsProvider(): array
    {
        return [
            ['getLastAlert'],
            ['storeAlert'],
            ['getActiveChannels'],
            ['recordNotificationHistory'],
            ['markAlertAsSent'],
            ['getActiveAlerts'],
            ['getActiveRules'],
            ['getRuleStatus'],
            ['updateRuleLastTriggered'],
            ['getMetricValue'],
            ['getNotificationChannelsForSettings'],
            ['getAlertRulesForSettings'],
            ['getActiveAlertsForDashboard'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۶. متدهای SentryEscalationRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider escalationRepositoryMethodsProvider */
    public function test_SentryModel_has_escalation_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function escalationRepositoryMethodsProvider(): array
    {
        return [
            ['getPendingEscalations'],
            ['escalateIssue'],
            ['escalateAlert'],
            ['acknowledgeAlert'],
            ['autoResolveErrorAlerts'],
            ['getEscalationStatistics'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۷. متدهای SentryQueueRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider queueRepositoryMethodsProvider */
    public function test_SentryModel_has_queue_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function queueRepositoryMethodsProvider(): array
    {
        return [
            ['getFailedJobsSummary'],
            ['getFailedJobQueueCounts'],
            ['getFailedJobsCount'],
            ['getOutboxDLQSummary'],
            ['getOutboxDLQList'],
            ['getFailedJobsPaged'],
            ['getFailedJobById'],
            ['retryFailedJob'],
            ['forgetFailedJob'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۸. متدهای SentryAuditRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider auditRepositoryMethodsProvider */
    public function test_SentryModel_has_audit_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function auditRepositoryMethodsProvider(): array
    {
        return [
            ['getAuditCount'],
            ['searchAuditRecords'],
            ['getAuditEventsByCategory'],
            ['getAuditUserActivity'],
            ['getAuditAccessPatterns'],
            ['getAuditFailedOperations'],
            ['deleteOldAuditRecords'],
            ['getOldAuditRecords'],
            ['getAuditRecordById'],
            ['getActivityTimeline'],
            ['getAuditReportSummary'],
            ['getAuditCriticalEvents'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۹. متدهای SentryLogRepositoryInterface موجودند
    // ──────────────────────────────────────────────────────────────

    /** @dataProvider logRepositoryMethodsProvider */
    public function test_SentryModel_has_log_repository_method(string $method): void
    {
        $this->assertTrue(
            method_exists(SentryModel::class, $method),
            "SentryModel باید متد {$method} داشته باشد"
        );
    }

    /** @return list<array{0:string}> */
    public static function logRepositoryMethodsProvider(): array
    {
        return [
            ['checkTableExists'],
            ['countTableRows'],
            ['avgTableColumn'],
            ['getErrorLogs'],
            ['findErrorById'],
            ['getSimilarErrors'],
            ['getTopErrorLogs'],
            ['getLastCronRun'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ۱۰. Signature های کلیدی صحیح هستند
    // ──────────────────────────────────────────────────────────────

    public function test_findExistingIssue_signature(): void
    {
        $ref = new \ReflectionMethod(SentryModel::class, 'findExistingIssue');
        $params = $ref->getParameters();

        $this->assertCount(2, $params, 'findExistingIssue باید ۲ پارامتر داشته باشد');
        $this->assertEquals('fingerprint', $params[0]->getName());
        $this->assertEquals('environment', $params[1]->getName());

        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType, 'باید return type داشته باشد');
        $this->assertTrue($returnType->allowsNull(), 'باید nullable object برگرداند');
    }

    private function namedReturnType(\ReflectionMethod $method): \ReflectionNamedType
    {
        $type = $method->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        return $type;
    }

    public function test_getMetricValue_signature(): void
    {
        $ref = new \ReflectionMethod(SentryModel::class, 'getMetricValue');
        $params = $ref->getParameters();

        $this->assertCount(2, $params, 'getMetricValue باید ۲ پارامتر داشته باشد');
        $this->assertEquals('type', $params[0]->getName());
        $this->assertEquals('minutes', $params[1]->getName());

        $returnType = $this->namedReturnType($ref);
        $this->assertEquals('float', $returnType->getName());
    }

    public function test_storePerformanceTransaction_returns_bool(): void
    {
        $ref = new \ReflectionMethod(SentryModel::class, 'storePerformanceTransaction');
        $returnType = $this->namedReturnType($ref);
        $this->assertEquals('bool', $returnType->getName());
    }

    public function test_getHealthMetricsBundle_returns_object(): void
    {
        $ref = new \ReflectionMethod(SentryModel::class, 'getHealthMetricsBundle');
        $returnType = $this->namedReturnType($ref);
        $this->assertEquals('object', $returnType->getName());
        $this->assertFalse($returnType->allowsNull(), 'getHealthMetricsBundle نباید nullable باشد');
    }

    public function test_acknowledgeAlert_signature(): void
    {
        $ref = new \ReflectionMethod(SentryModel::class, 'acknowledgeAlert');
        $params = $ref->getParameters();

        $this->assertGreaterThanOrEqual(3, count($params));
        // پارامتر userId باید nullable باشد
        $this->assertTrue($params[1]->allowsNull(), 'userId باید nullable باشد');
    }

    // ──────────────────────────────────────────────────────────────
    // ۱۱. Interface ها فقط public متد اعلام می‌کنند
    // ──────────────────────────────────────────────────────────────

    public function test_all_interface_methods_are_public(): void
    {
        $interfaces = [
            SentryErrorRepositoryInterface::class,
            SentryPerformanceRepositoryInterface::class,
            SentryAlertRepositoryInterface::class,
            SentryEscalationRepositoryInterface::class,
            SentryQueueRepositoryInterface::class,
            SentryAuditRepositoryInterface::class,
            SentryLogRepositoryInterface::class,
        ];

        foreach ($interfaces as $interface) {
            $ref = new \ReflectionClass($interface);
            foreach ($ref->getMethods() as $method) {
                $this->assertTrue(
                    $method->isPublic(),
                    "متد {$interface}::{$method->getName()} باید public باشد"
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ۱۲. تعداد متدهای SentryModel
    // ──────────────────────────────────────────────────────────────

    public function test_SentryModel_method_count_is_documented(): void
    {
        $ref = new \ReflectionClass(SentryModel::class);
        $ownMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn($m) => $m->getDeclaringClass()->getName() === SentryModel::class
        );

        // SentryModel دارای ≥۷ (public متدهای خودش)
        $this->assertGreaterThan(
            40,
            count($ownMethods),
            'SentryModel باید بیش از ۴۰ متد public داشته باشد (God Object که در فاز بعد شکسته خواهد شد)'
        );
    }
}
