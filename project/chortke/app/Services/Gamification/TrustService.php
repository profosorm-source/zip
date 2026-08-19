<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\ModuleContext;
use App\Enums\ScoreDomain;
use App\Contracts\LoggerInterface;
use App\Domain\Gamification\Strategies\TrustEvaluationStrategy;
use App\Services\ScoreService;
use Core\Database;

/**
 * سرویس مدیریت اعتماد و سلامت کاربر (Trust Score)
 * مسئولیت: افزایش یا کاهش امتیاز اعتماد کاربران در ماژول‌های مختلف جهت تشخیص تقلب
 */
class TrustService
{
    private \App\Contracts\LoggerInterface $logger;
    private ScoreService $scoreService;
    private TrustEvaluationStrategy $trustStrategy;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private Database $db;
    public function __construct(
        Database $db,
        \App\Contracts\LoggerInterface $logger,
        ScoreService $scoreService,
        TrustEvaluationStrategy $trustStrategy,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->scoreService = $scoreService;
        $this->trustStrategy = $trustStrategy;
        $this->outbox = $outbox;

        
    }

    /**
     * ارزیابی و اعمال تغییرات Trust کاربر
     */
    /**
     * @param object $user
     * @param array<string, mixed> $payload
     */
    public function evaluate(object $user, ModuleContext $context, string $actionType, array $payload = []): bool
    {
        $userData = get_object_vars($user);
        $userId = int_value($userData['id'] ?? 0);
        if ($userId <= 0) throw new \InvalidArgumentException('Trust evaluation requires a valid user id.');
        $payload['action'] = $actionType;
        $delta = $this->trustStrategy->calculate($user, $context, $payload);

        if ($delta == 0.0) {
            return false;
        }

        try {
            $domain = ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_TRUST, $context);

            // 🛡️ M-2 Fix: استفاده از ScoreService.applyDelta به جای scoreModel->addEvent مستقیم
            // این باعث می‌شود Idempotency, Rate Limit, AntiFraud, Redis Cache, و Distributed Lock اعمال شوند
            $idemKey = "trust:{$userId}:{$domain}:{$actionType}";
            $this->scoreService->applyDelta('user', $userId, $domain, $delta, $actionType, [], $idemKey);

            $currentTrust = $this->scoreService->getScore('user', $userId, $domain);

            // شلیک رویداد در صورت افت شدید Trust (مثلا برای مسدودسازی خودکار کاربر متقلب)
            if ($delta < 0 && $currentTrust < -50.0) {
                $this->outbox?->recordEvent(new \Core\GenericEvent([
                    'user_id' => $userId,
                    'context' => $context->value,
                    'current_trust' => $currentTrust,
                    'event_name' => 'trust.critical_drop'
                ]));
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('trust_service.evaluation_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * دریافت امتیاز اعتماد فعلی کاربر در یک ماژول خاص
     *
     * 🛡️ M-2 Fix: استفاده از ScoreService برای خواندن امتیاز
     *
     * @param object $user
     */
    public function getTrustScore(object $user, ModuleContext $context): float
    {
        $userData = get_object_vars($user);
        $userId = int_value($userData['id'] ?? 0);
        return $this->scoreService->getScore('user', $userId, ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_TRUST, $context));
    }

    /**
     * بازیابی هفتگی امتیاز اعتماد — Batch operation for cron.
     *
     * برای کاربران فعال اخیر، امتیاز اعتماد را به‌تدریج بازیابی می‌کند
     * و کاربران با rejection مکرر را جریمه soft_excess می‌کند.
     *
     * @param ModuleContext $context ماژول هدف (مثلاً SOCIAL_TASKS)
     */
    /**
     * @return array<string, mixed>
     */
    public function recoverWeekly(ModuleContext $context): array
    {
        $recovered = 0;
        $penalized = 0;
        $domain = ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_TRUST, $context);

        try {
            // کاربران فعال در ۷ روز گذشته با score >= 0 — بازیابی تدریجی
            $activeUsers = $this->db->fetchAll(
                "SELECT DISTINCT user_id FROM social_task_executions
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND score >= 0
                 LIMIT 1000"
            );

            foreach ($activeUsers as $row) {
                $userId = (int) $row->user_id;
                try {
                    $currentScore = $this->scoreService->getScore('user', $userId, $domain);
                    // Recovery: +2 per active week, capped at 100
                    if ($currentScore < 100) {
                        $this->scoreService->applyDelta(
                            'user', $userId, $domain, 2.0,
                            'weekly_recovery', [],
                            "trust:recovery:{$userId}:" . date('YW')
                        );
                        $recovered++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('trust.recovery.user_failed', [
                        'user_id' => $userId, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // کاربران با ≥۳ rejection در هفته — جریمه soft_excess
            $excessUsers = $this->db->fetchAll(
                "SELECT user_id, COUNT(*) as reject_count
                 FROM social_task_executions
                 WHERE status = 'rejected'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY user_id
                 HAVING reject_count >= 3
                 LIMIT 500"
            );

            foreach ($excessUsers as $row) {
                $userId = (int) $row->user_id;
                $count  = (int) ($row->reject_count ?? 0);
                try {
                    $penalty = min(-5.0 * ($count - 2), -2.0);
                    $this->scoreService->applyDelta(
                        'user', $userId, $domain, $penalty,
                        'soft_excess_penalty',
                        ['reject_count' => $count],
                        "trust:penalty:{$userId}:" . date('YW')
                    );
                    $penalized++;
                } catch (\Throwable $e) {
                    $this->logger->warning('trust.penalty.user_failed', [
                        'user_id' => $userId, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('trust.weekly_recovery.completed', [
                'context'    => $context->value,
                'recovered'  => $recovered,
                'penalized'  => $penalized,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('trust.weekly_recovery.failed', [
                'error' => $e->getMessage(),
            ]);
            return ['recovered' => $recovered, 'penalized' => $penalized, 'error' => $e->getMessage()];
        }

        return ['recovered' => $recovered, 'penalized' => $penalized];
    }
}
