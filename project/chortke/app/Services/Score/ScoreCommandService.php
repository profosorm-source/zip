<?php

declare(strict_types=1);

namespace App\Services\Score;

use App\Models\Score as ScoreModel;
use Core\EventDispatcher;
use App\Enums\ScoreDomain;
use App\Events\ScoreDeltaAppendedEvent;
use App\Services\AntiFraud\FraudDetectionService;

/**
 * ScoreCommandService (Write Model / Command Side)
 * 
 * Handles ONLY the addition of score events to the Event Store (score_events ledger).
 * It does NOT update the read projection synchronously to prevent DB locks,
 * but it DOES update the Redis Cache atomic hash.
 * 
 * 🛡️ H-1 Fix: Redis cache update moved INSIDE transactional executor
 * 🛡️ H-2 Fix: Expanded AntiFraud protection for all domains & entity types
 */
class ScoreCommandService
{
    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;
    private ScoreModel $scoreModel;
    private ?FraudDetectionService $fraudService;
    private ?\Core\TransactionWrapper $transactionWrapper;
    private ?\App\Services\OutboxService $outbox;
    private ?\App\Services\DistributedLockService $lockService;
    private \App\Policies\RateLimitPolicy $rateLimitPolicy;

    public function __construct(

        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        ScoreModel $scoreModel,
        \App\Policies\RateLimitPolicy $rateLimitPolicy,
        ?FraudDetectionService $fraudService = null,
        ?\Core\TransactionWrapper $transactionWrapper = null,
        ?\App\Services\OutboxService $outbox = null,
        ?\App\Services\DistributedLockService $lockService = null
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->scoreModel = $scoreModel;
        $this->fraudService = $fraudService;
        $this->transactionWrapper = $transactionWrapper;
        $this->outbox = $outbox;
        $this->lockService = $lockService;
        $this->rateLimitPolicy = $rateLimitPolicy;
    }

    /**
     * Appends a score delta to the event ledger and dispatches an event.
     *
     * 🛡️ H-1: Redis update + Distributed Lock via Redis (DistributedLockService)
     * 🛡️ H-2: AntiFraud for ALL domains, velocity check, alternating pattern, delta cap
     */
    /**
     * @param array<string, mixed> $meta
     */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = [], ?string $idempotencyKey = null): bool
    {
        // 🛡️ Idempotency Check (Redis SETNX)
        if ($idempotencyKey) {
            $idemKey = "score_idemp:{$idempotencyKey}";
            try {
                $redis = $this->cache->redis();
                if ($redis) {
                    if (!$redis->setnx($idemKey, 1)) {
                        $this->logger->info('score.idempotent_skip', ['idempotency_key' => $idempotencyKey]);
                        return true;
                    }
                    $redis->expire($idemKey, 86400 * 7);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('score.idempotency_cache_failed', ['error' => $e->getMessage()]);
                // intentional: Redis cache operation — non-blocking, failure safe
            }
        }

        // 🛡️ Rate Limit: prevent high-frequency score spam (از طریق RateLimitPolicy یکپارچه)
        try {
            $ratePolicy = $this->rateLimitPolicy;
            $scoreKey = "{$entityType}_{$entityId}_{$domain}";
            if (!$ratePolicy->check('score_apply', $scoreKey)) {
                $this->logger->warning('score.rate_limit_exceeded', ['entity_type' => $entityType, 'entity_id' => $entityId]);
                return false;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('score.rate_limit_policy_failed', ['error' => $e->getMessage()]);
            // Non-blocking: allow in case RateLimitPolicy is not available
        }

        $domain = ScoreDomain::normalize($domain);

        // ═══════════════════════════════════════════════════════════════
        // 🛡️ H-2 Fix: چهار لایه محافظتی AntiFraud
        // ═══════════════════════════════════════════════════════════════

        // 1️⃣ Baseline Fraud Check (همه entityType‌ها، هم delta مثبت و هم منفی)
        if ($this->fraudService !== null) {
            try {
                $fraudScore = $this->fraudService->calculateFraudScore($entityId);

                if ($fraudScore >= 85) {
                    $this->logger->warning('antifraud.score_blocked', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType,
                        'domain' => $domain,
                        'delta' => $delta,
                        'fraud_score' => $fraudScore
                    ]);
                    return false;
                } elseif ($fraudScore >= 50) {
                    $penalty = ($entityType === 'user') ? 0.5 : 0.7;
                    if ($delta < 0) $penalty *= 0.7; // negative delta: lighter penalty
                    $delta *= $penalty;
                    $meta['antifraud_penalty'] = true;
                    $meta['fraud_score'] = $fraudScore;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('antifraud.service_unavailable', ['error' => $e->getMessage()]);
            }
        }

        // 2️⃣ Score Velocity Check: حداکثر ۳۰ درخواست امتیاز در دقیقه
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                $velocityKey = "score_velocity:{$entityType}:{$entityId}";
                $currentCount = int_value($redis->get($velocityKey));
                if ($currentCount >= 30) {
                    $this->logger->warning('antifraud.score_velocity_exceeded', [
                        'entity_id' => $entityId, 'velocity' => $currentCount,
                    ]);
                    return false;
                }
                $redis->multi();
                $redis->incr($velocityKey);
                $redis->expire($velocityKey, 60);
                $redis->exec();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('score.velocity_cache_failed', ['error' => $e->getMessage()]);
            // intentional: Redis cache operation — non-blocking, failure safe
        }

        // 3️⃣ Alternating +/- Detection: شناسایی الگوی نوسانی
        if ($delta != 0) {
            try {
                $redis = $this->cache->redis();
                if ($redis) {
                    $altKey = "score_alt:{$entityType}:{$entityId}:{$domain}";
                    $lastSign = $redis->get($altKey);
                    $currentSign = $delta > 0 ? '+' : '-';
                    if ($lastSign !== false && $lastSign !== $currentSign) {
                        // ✅ Pipeline: incr + expire در یک round-trip
                        $altCountKey = "score_alt_count:{$entityType}:{$entityId}:{$domain}";
                        $redis->multi();
                        $redis->incr($altCountKey);
                        $redis->expire($altCountKey, 300);
                        $results   = $redis->exec();
                        $flipCount = $results[0] ?? 0;
                        if ($flipCount >= 5) {
                            $this->logger->warning('antifraud.score_alternating_pattern', [
                                'entity_id' => $entityId, 'domain' => $domain, 'flip_count' => $flipCount,
                            ]);
                            return false;
                        }
                    } else {
                        $redis->del("score_alt_count:{$entityType}:{$entityId}:{$domain}");
                    }
                    $redis->setex($altKey, 300, $currentSign);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('score.alternating_cache_failed', ['error' => $e->getMessage()]);
                // intentional: Redis cache operation — non-blocking, failure safe
            }
        }

        // 4️⃣ Absolute Delta Cap: سقف ۱۰۰ امتیاز برای هر تراکنش
        if ($delta > 100.0)  $delta = 100.0;
        if ($delta < -100.0) $delta = -100.0;

        // 5️⃣ Negative Floor Guard: مجموع کل امتیاز نباید زیر صفر بره
        // (جلوگیری از تقسیم بر صفر و ناهنجاری در رتبه‌بندی)
        if ($delta < 0) {
            try {
                $currentTotal = $this->scoreModel->getTotal($entityId, $entityType, $domain);
                $projected = $currentTotal + $delta;
                if ($projected < 0) {
                    // Clamp: فقط تا صفر کم کن
                    $delta = -$currentTotal;
                    if ($delta >= 0) {
                        // امتیاز از قبل صفر یا زیره — هیچی کم نکن
                        $this->logger->info('score.negative_floor_hit', [
                            'entity_type' => $entityType,
                            'entity_id' => $entityId,
                            'domain' => $domain,
                            'current' => $currentTotal,
                            'requested_delta' => $delta,
                        ]);
                        return true;
                    }
                    $meta['negative_floor_clamped'] = true;
                    $meta['original_delta'] = $delta;
                }
            } catch (\Throwable $e) {
                // اگه خوندن امتیاز فعلی فیل شد، ادامه بده (fail-open)
                $this->logger->warning('score.floor_check_failed', ['error' => $e->getMessage()]);
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 🛡️ Distributed Lock via Redis (DistributedLockService)
        // ═══════════════════════════════════════════════════════════════
        $lockResource = "score_delta:{$entityType}:{$entityId}:{$domain}";
        $lockToken = null;

        if ($this->lockService) {
            try {
                $lock = $this->lockService->acquire($lockResource, 10, 5);
                if ($lock['acquired']) {
                    $lockToken = $lock['token'];
                } else {
                    $this->logger->warning('score.lock_failed', [
                        'entity_type' => $entityType, 'entity_id' => $entityId, 'domain' => $domain,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('score.lock_exception', ['error' => $e->getMessage()]);
            }
        }

        try {
            // 1. Immutable Event Sourcing + Redis Cache (atomic under transaction)
            $executor = function() use ($entityType, $entityId, $domain, $delta, $source, $meta) {
                $success = $this->scoreModel->addEvent([
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'domain'      => $domain,
                    'delta'       => $delta,
                    'source'      => $source,
                    'meta'        => $meta
                ]);

                if ($success) {
                    // 🛡️ H-1 Fix: Redis update INSIDE executor
                    // ✅ Pipeline: hIncrByFloat + expire در یک round-trip
                    try {
                        $redis = $this->cache->redis();
                        if ($redis) {
                            $cacheKey = "score:{$entityType}:{$entityId}";
                            $redis->multi();
                            $redis->hIncrByFloat($cacheKey, $domain, $delta);
                            $redis->expire($cacheKey, 3600);
                            $redis->exec();
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('score.redis_update_failed', [
                            'entity_type' => $entityType, 'entity_id' => $entityId, 'domain' => $domain,
                        ]);
                    }

                    if ($this->outbox) {
                        $this->outbox->recordEvent(
                            new ScoreDeltaAppendedEvent($entityType, $entityId, $domain, $delta, $source)
                        );
                    }
                }

                return $success;
            };

            $success = $this->transactionWrapper
                ? $this->transactionWrapper->runWithRetry($executor)
                : $executor();

            if ($success && !$this->outbox) {
                $this->logger->warning('score.outbox_unavailable', [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'domain'      => $domain,
                    'message'     => 'OutboxService is not injected — domain event was NOT dispatched',
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'   => 'score.applyDelta',
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'domain'      => $domain,
                'source'      => $source,
            ]);
            throw $e;
        } finally {
            if ($lockToken && $this->lockService) {
                try {
                    $this->lockService->release($lockResource, $lockToken);
                } catch (\Throwable $e) {
                $this->logger->debug('score.lock_release_failed', ['error' => $e->getMessage()]);
                // intentional: Redis cache operation — non-blocking, failure safe
            }
            }
        }
    }

    /**
     * Batch invalidation: Deletes the entire user score hash.
     */
    public function clearScoresCache(string $entityType, int $entityId): void
    {
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                $cacheKey = "score:{$entityType}:{$entityId}";
                $redis->del($cacheKey);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('score.cache_clear_failed', ['error' => $e->getMessage()]);
            // intentional: Redis cache operation — non-blocking, failure safe
        }
    }
}
