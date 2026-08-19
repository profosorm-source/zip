<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ScoreDeltaAppendedEvent;
use App\Events\ScoreUpdatedEvent;
use Core\Database;
use Core\Cache;
use App\Services\Cache\CacheInvalidationService;

/**
 * ScoreProjectionListener
 * 
 * Asynchronously projects score deltas to the user_scores read model 
 * and handles cache invalidation.
 */
class ScoreProjectionListener
{
    private Database $db;
    private Cache $cache;
    private CacheInvalidationService $cacheInvalidation;
    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        Database $db,
        Cache $cache,
        CacheInvalidationService $cacheInvalidation,
        \App\Contracts\LoggerInterface $logger,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->cacheInvalidation = $cacheInvalidation;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    public function handle(ScoreDeltaAppendedEvent $event): void
    {
        // 1. Update Read Model (user_scores table)
        if ($event->entityType === 'user') {
            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at) 
                VALUES (?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                score = score + VALUES(score), updated_at = NOW()
            ");
            $stmt->execute([$event->entityId, $event->domain, $event->delta]);

            // Fetch new total score to dispatch standard ScoreUpdatedEvent for gamification triggers
            try {
                $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = ? LIMIT 1");
                $stmt->execute([$event->entityId, $event->domain]);
                $val = $stmt->fetchColumn();
                $newScore = $val !== false ? (float)$val : null;
                $oldScore = $newScore !== null ? $newScore - $event->delta : 0.0;
                
                if ($newScore !== null) {
                    $this->outbox?->record('score', $event->entityId, ScoreUpdatedEvent::class, [
                        'entity_id' => $event->entityId,
                        'old_score' => (float)$oldScore,
                        'new_score' => (float)$newScore,
                        'source' => $event->source,
                    ]);
                }
            } catch (\Throwable $e) {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ScoreProjectionListener.handle']);
            $this->logger->warning('scoreprojectionlistener.operation_failed', ['error' => $e->getMessage()]);
        }
        }

        // 2. Invalidate Caches
        $this->invalidateScoreCache($event->entityType, $event->entityId, $event->domain);
    }

    private function invalidateScoreCache(string $entityType, int $entityId, string $domain): void
    {
        // 🛡️ M-1 Fix: Redis از Hash key استفاده می‌کند (score:user:123) نه String key (score:user:123:task)
        // پس باید HDEL روی فیلد خاص هش بزنیم، نه DEL روی کلید اشتباه
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                $cacheKey = "score:{$entityType}:{$entityId}";
                // حذف فقط فیلد این domain از هش → بقیه فیلدها دست‌نخورده می‌مانند
                $redis->hDel($cacheKey, $domain);
                // ریست TTL
                $redis->expire($cacheKey, 3600);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ScoreProjectionListener.invalidateScoreCache']);
            $this->logger->warning('scoreprojectionlistener.operation_failed', ['error' => $e->getMessage()]);
        }

        $this->cacheInvalidation->invalidateScore($entityId, $domain);
    }
}
