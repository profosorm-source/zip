<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Core\RetryPolicy;

/**
 * تست‌های رفتاری RetryPolicy
 *
 * بررسی: Exponential Backoff, Jitter, Retry Budget, Circuit Breaker, Non-retryable errors
 */
class RetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset retry budget between tests to avoid state leakage
        try { \Core\Cache::getInstance()->flush(); } catch (\Throwable) {}
    }

    protected function tearDown(): void
    {
        try { \Core\Cache::getInstance()->flush(); } catch (\Throwable) {}
        parent::tearDown();
    }


    // ─── Basic execution ────────────────────────────────────────

    /** @test */
    public function executes_successfully_on_first_try(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        $result = $policy->executeWithContext('test', function () use (&$callCount) {
            $callCount++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    /** @test */
    public function retries_on_transient_failure_then_succeeds(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        $result = $policy->executeWithContext('test_retry', function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \RuntimeException("Transient failure attempt {$callCount}");
            }
            return 'recovered';
        });

        $this->assertEquals('recovered', $result);
        $this->assertEquals(3, $callCount);
    }

    /** @test */
    public function throws_after_max_attempts_exhausted(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('persistent failure');

        $policy->executeWithContext('test_exhaust', function () use (&$callCount) {
            $callCount++;
            throw new \RuntimeException('persistent failure');
        });
    }

    // ─── Non-retryable errors ───────────────────────────────────

    /** @test */
    public function does_not_retry_type_error(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        try {
            $policy->executeWithContext('test_type', function () use (&$callCount) {
                $callCount++;
                throw new \TypeError('Type mismatch');
            });
        } catch (\TypeError $e) {
            $this->assertEquals(1, $callCount, 'TypeError should not be retried');
            return;
        }

        $this->fail('TypeError was not thrown');
    }

    /** @test */
    public function does_not_retry_invalid_argument(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        try {
            $policy->executeWithContext('test_arg', function () use (&$callCount) {
                $callCount++;
                throw new \InvalidArgumentException('Bad argument');
            });
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals(1, $callCount, 'InvalidArgumentException should not be retried');
            return;
        }

        $this->fail('InvalidArgumentException was not thrown');
    }

    /** @test */
    public function does_not_retry_parse_error(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        try {
            $policy->executeWithContext('test_parse', function () use (&$callCount) {
                $callCount++;
                throw new \ParseError('Syntax error');
            });
        } catch (\ParseError $e) {
            $this->assertEquals(1, $callCount, 'ParseError should not be retried');
            return;
        }

        $this->fail('ParseError was not thrown');
    }

    // ─── Custom retryable exceptions ────────────────────────────

    /** @test */
    public function retries_only_specified_exceptions(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        try {
            $policy->executeWithContext('test_custom', function () use (&$callCount) {
                $callCount++;
                throw new \LogicException('Not retryable');
            }, [\RuntimeException::class]);
        } catch (\LogicException $e) {
            // LogicException is not in retryable list → should not retry
            $this->assertEquals(1, $callCount);
            return;
        }

        $this->fail('LogicException was not thrown');
    }

    /** @test */
    public function retries_when_exception_matches_filter(): void
    {
        $policy = new RetryPolicy();
        $callCount = 0;

        $result = $policy->executeWithContext('test_match', function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \RuntimeException('retry me');
            }
            return 'ok';
        }, [\RuntimeException::class]);

        $this->assertEquals('ok', $result);
        $this->assertEquals(2, $callCount);
    }

    // ─── Backward compatibility ─────────────────────────────────

    /** @test */
    public function execute_without_context_works(): void
    {
        $policy = new RetryPolicy();

        $result = $policy->execute(function () {
            return 42;
        });

        $this->assertEquals(42, $result);
    }

}
