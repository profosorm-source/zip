<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست اصلاحات سیستم Score/Rating
 */
/**
 * @group architecture
 */
class ScoreRatingFixTest extends TestCase
{
    // ─── Fix 4.1: Rating.createOnce — removed, uses DistributedLockService instead ─




    // ─── Fix 4.1b: ScoreCommandService already uses DistributedLockService ─


    // ─── Fix 4.2: Negative score floor ─────────────────────────────────



    // ─── Architecture checks ───────────────────────────────────────────

    public function testScoreServiceFacadeExists(): void
    {
        $ref = new \ReflectionClass(\App\Services\ScoreService::class);
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertContains('applyDelta', $methods);
        $this->assertContains('getScore', $methods);
    }

    public function testScoreDomainEnumHasNormalize(): void
    {
        $this->assertTrue(
            method_exists(\App\Enums\ScoreDomain::class, 'normalize'),
            'ScoreDomain باید normalize داشته باشه'
        );
    }

    public function testAllScoreFilesLoadable(): void
    {
        $classes = [
            \App\Services\Score\ScoreCommandService::class,
            \App\Services\Score\ScoreQueryService::class,
            \App\Services\ScoreService::class,
            \App\Models\Score::class,
            \App\Models\Rating::class,
            \App\Enums\ScoreDomain::class,
            \App\Events\ScoreDeltaAppendedEvent::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "کلاس {$class} باید loadable باشه");
        }
    }
}
