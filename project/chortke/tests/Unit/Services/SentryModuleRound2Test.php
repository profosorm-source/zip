<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست اصلاحات دور دوم بررسی ماژول Sentry
 */
/**
 * @group architecture
 */
class SentryModuleRound2Test extends TestCase
{
    // ─── Fix A: EscalationManager uses $issue->level (not severity) ────────



    public function testGetNextLevelReturnsValidLevels(): void
    {
        $ref = new \ReflectionMethod(
            \App\Services\Sentry\Alerting\EscalationManager::class,
            'getNextLevel'
        );
        $ref->setAccessible(true);

        $manager = $this->createPartialMock(
            \App\Services\Sentry\Alerting\EscalationManager::class,
            []
        );

        $validLevels = ['info', 'warning', 'error', 'critical', 'fatal'];

        $this->assertEquals('warning', $ref->invoke($manager, 'info'));
        $this->assertEquals('error', $ref->invoke($manager, 'warning'));
        $this->assertEquals('critical', $ref->invoke($manager, 'error'));
        $this->assertEquals('critical', $ref->invoke($manager, 'critical'));

        // هر نتیجه باید یکی از valid levels باشه
        foreach ($validLevels as $level) {
            $result = $ref->invoke($manager, $level);
            $this->assertContains($result, $validLevels,
                "getNextLevel('$level') باید یکی از level‌های معتبر برگردونه");
        }
    }

    // ─── Fix B: escalateIssue — status='escalated' + level upgrade ─────────

    public function testSentryModelHasEscalateIssueMethod(): void
    {
        $this->assertTrue(
            method_exists(\App\Models\SentryModel::class, 'escalateIssue'),
            'SentryModel باید متد escalateIssue داشته باشه'
        );
    }


    public function testEscalateAlertBackwardCompat(): void
    {
        // escalateAlert (deprecated) باید هنوز وجود داشته باشه
        $this->assertTrue(
            method_exists(\App\Models\SentryModel::class, 'escalateAlert'),
            'escalateAlert باید به‌عنوان backward compat وجود داشته باشه'
        );
    }

    // ─── Fix C: SentryErrorMonitor — no dead AuditTrail dependency ─────────

    public function testSentryErrorMonitorDoesNotInjectAuditTrail(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();

        $typeNames = array_map(function ($p) {
            $type = $p->getType();
            return $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }, $params);

        $this->assertNotContains('App\\Services\\AuditTrail', $typeNames,
            'SentryErrorMonitor نباید AuditTrail inject کنه — dead dependency');
    }

    public function testSentryErrorMonitorHasNoAuditTrailProperty(): void
    {
        $ref = new \ReflectionClass(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);
        $this->assertFalse(
            $ref->hasProperty('auditTrail'),
            'SentryErrorMonitor نباید پراپرتی auditTrail داشته باشه'
        );
    }

    // ─── Cross-check: all Service→Model calls still valid after changes ────


}
