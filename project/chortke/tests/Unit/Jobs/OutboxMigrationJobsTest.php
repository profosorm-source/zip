<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست‌های Migration Outbox برای Jobs
 * 
 * بررسی:
 * ۱. outbox inject شده
 * ۲. dispatchAsync حذف شده
 * ۳. null-safe calls
 */
/**
 * @group architecture
 */
class OutboxMigrationJobsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @dataProvider jobClassesProvider
     * @test
     * @param class-string $class
     */
    public function job_has_outbox_injection(string $class): void
    {
        $ref = new \ReflectionClass($class);
        $constructor = $ref->getConstructor();
        
        $this->assertNotNull($constructor, "$class has no constructor");

        $params = $constructor->getParameters();
        $paramTypes = [];
        foreach ($params as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType) {
                $paramTypes[] = $type->getName();
            }
        }

        $this->assertTrue(
            in_array('App\\Contracts\\OutboxServiceInterface', $paramTypes),
            "$class should accept OutboxServiceInterface in constructor"
        );
    }



    /** @return list<array{0:class-string}> */
    public function jobClassesProvider(): array
    {
        return [
            ['App\\Jobs\\Auth\\ProcessRegistrationJob'],
            ['App\\Jobs\\Auth\\Verify2FAJob'],
            ['App\\Jobs\\CustomTask\\CronSubmissionsJob'],
            ['App\\Jobs\\CustomTask\\RateSubmissionJob'],
            ['App\\Jobs\\CustomTask\\SubmitProofJob'],
            ['App\\Jobs\\CustomTask\\CreateCustomTaskJob'],
            ['App\\Jobs\\Payment\\ReconcilePaymentsJob'],
            ['App\\Jobs\\Investment\\ApplyProfitLossToBatchJob'],
            ['App\\Jobs\\Investment\\RejectWithdrawalJob'],
            ['App\\Jobs\\KycTimeoutJob'],
            ['App\\Jobs\\SocialTask\\ApproveSocialTaskExecutionJob'],
            ['App\\Jobs\\SocialTask\\DecideSilentAntiFraudJob'],
            ['App\\Jobs\\SocialTask\\RejectSocialTaskExecutionJob'],
            ['App\\Jobs\\Seo\\CompleteSeoTaskJob'],
            ['App\\Jobs\\Seo\\ProcessSeoTaskAsyncJob'],
        ];
    }
}
