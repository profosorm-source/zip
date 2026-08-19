<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست اصلاحات سیستم جستجو
 */
/**
 * @group architecture
 */
class SearchSystemFixTest extends TestCase
{
    // ─── Fix 3.1: getAuditFailedOperations — FULLTEXT بجای LIKE ────────



    // ─── Fix 3.2: Deep Offset Pagination — سقف offset ──────────────────



    // ─── Fix: deactivateOwner — واقعاً deactivate میکنه ────────────────


    // ─── Cross-check: CQRS architecture integrity ──────────────────────

    public function testSearchProjectionRepositoryExists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Search\SearchProjectionRepository::class));
    }

    public function testSearchIndexerExists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Search\SearchIndexer::class));
    }

    public function testSearchProjectionListenerExists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Search\SearchProjectionListener::class));
    }




    public function testAllSearchFilesLoadable(): void
    {
        $classes = [
            \App\Services\Search\SearchProjectionRepository::class,
            \App\Services\Search\SearchProjectionListener::class,
            \App\Services\Search\SearchIndexer::class,
            \App\Services\Search\SchemaInspector::class,
            \App\Services\Search\SearchOrchestrator::class,
            \App\Services\Search\SearchResult::class,
            \App\Services\Search\SearchQuery::class,
            \App\Services\Search\AbstractSearchGateway::class,
            \App\Services\Search\ModuleSearchGateway::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "کلاس {$class} باید loadable باشه");
        }
    }
}
