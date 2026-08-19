<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Models\SocialTaskModel;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Enums\ScoreDomain;
use App\Services\ScoreService;
use App\Services\Shared\IdempotencyService;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Services\User\UserService;
use App\Services\OutboxService;
use App\Services\Interaction\RatingService as InteractionRatingService;
use App\Services\SocialAccountService;
use App\Models\SocialTaskAnalyticsModel;
use App\Services\AntiFraud\TaskExecutionEvaluatorService;

/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 */
/**
 * @phpstan-type SocialTaskInput array<string, mixed>
 * @phpstan-type SocialTaskFilters array<string, mixed>
 * @phpstan-type SocialTaskResult array{success: bool, message: string, ...}
 * @phpstan-type StartExecutionResult array{success: bool, message: string, execution_id?: int, status?: string, expected_time?: int, target_url?: mixed, task_type?: string, rate_limit_seconds?: int}
 * @phpstan-type SocialTaskListResult array{tasks: list<\stdClass>, restriction_level: string, trust_score: float}
 * @phpstan-type AdminListResult array{0: list<\stdClass>, 1: int}
 * @phpstan-type ExecutionRow object{id: int|string, ad_id: int|string, executor_id: int|string, status: string, task_type?: string|null, decision?: string|null}
 * @phpstan-type AdRow object{id: int|string, user_id: int|string, price_per_task: int|float|string, currency: string, status: string}
 */
class SocialTaskService
{
    // تسک‌های یوتیوب جدا هستند
    private const EXCLUDED_PLATFORMS_FROM_SOCIAL = ['youtube'];

    private \App\Contracts\LoggerInterface $logger;
    private SocialTaskModel $model;
    private SocialAccountService $socialAccountService;
    private \Core\Database $db;
    private TrustService $trust;
    private SilentAntiFraudService $antiFraud;
    private UserService $userService;
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private \Core\Container $container;
    private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService;
    private \App\Services\AdSystemManager $adSystemManager;
    private InteractionRatingService $interactionRatingService;
    private ScoreService $scoreService;
    private IdempotencyService $idempotencyService;
    private ?\App\Jobs\SocialTask\StartSocialTaskExecutionJob $startExecutionJob;
    private ?\App\Jobs\SocialTask\SubmitSocialTaskExecutionJob $submitExecutionJob;
    private ?\App\Jobs\SocialTask\ApproveSocialTaskExecutionJob $approveJob;
    private ?\App\Jobs\SocialTask\RejectSocialTaskExecutionJob $rejectJob;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        SocialTaskModel $model,
        TrustService $trust,
        SilentAntiFraudService $antiFraud,
        UserService $userService,
        SocialAccountService $socialAccountService,
        InteractionRatingService $interactionRatingService,
        ScoreService $scoreService,
        IdempotencyService $idempotencyService,
        \Core\Container $container,
        \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService,
        \App\Services\AdSystemManager $adSystemManager,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\App\Jobs\SocialTask\StartSocialTaskExecutionJob $startExecutionJob = null,
        ?\App\Jobs\SocialTask\SubmitSocialTaskExecutionJob $submitExecutionJob = null,
        ?\App\Jobs\SocialTask\ApproveSocialTaskExecutionJob $approveJob = null,
        ?\App\Jobs\SocialTask\RejectSocialTaskExecutionJob $rejectJob = null,
    ) {
        $this->logger = $logger;
        $this->model = $model;
        $this->db = $model->getDb();
        $this->trust = $trust;
        $this->antiFraud = $antiFraud;
        $this->userService = $userService;
        $this->socialAccountService = $socialAccountService;
        $this->interactionRatingService = $interactionRatingService;
        $this->scoreService = $scoreService;
        $this->idempotencyService = $idempotencyService;
        $this->container = $container;
        $this->adsBudgetSettlementService = $adsBudgetSettlementService;
        $this->adSystemManager = $adSystemManager;
        $this->outbox = $outbox;
        $this->startExecutionJob = $startExecutionJob;
        $this->submitExecutionJob = $submitExecutionJob;
        $this->approveJob = $approveJob;
        $this->rejectJob = $rejectJob;
    }

    /** @return SocialTaskResult */
    private function normalizeSocialResult(mixed $result, string $fallback = 'عملیات تسک اجتماعی انجام نشد'): array
    {
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Social-task command must return an associative result array');
        }
        foreach (array_keys($result) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Social-task command result must use string keys');
            }
        }
        $result['success'] = (bool)($result['success'] ?? false);
        $result['message'] = is_string($result['message'] ?? null) ? $result['message'] : $fallback;
        /** @var SocialTaskResult $result */
        return $result;
    }

    /** @param array<string, mixed> $result */
    private function resultMessage(array $result, string $fallback): string
    {
        return is_string($result['message'] ?? null) ? $result['message'] : $fallback;
    }

    /** @param SocialTaskInput $input */
    private function inputString(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? null;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /** @param SocialTaskInput $input */
    private function inputDecimal(array $input, string $key, string $default = '0'): string
    {
        $value = $input[$key] ?? null;
        if (!is_scalar($value)) return $default;
        $value = trim((string)$value);
        return $value !== '' && is_numeric($value) ? $value : $default;
    }

    /** @param SocialTaskInput $input */
    private function inputPositiveInt(array $input, string $key, int $default = 0): int
    {
        $value = $input[$key] ?? null;
        return is_scalar($value) && is_numeric((string)$value) ? max(0, (int)$value) : $default;
    }

    /** @return ExecutionRow */
    private function requireExecutionRow(object $execution): object
    {
        $values = get_object_vars($execution);
        foreach (['id', 'ad_id', 'executor_id', 'status'] as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
                throw new \UnexpectedValueException("Invalid social-task execution row: {$key}");
            }
        }
        /** @var ExecutionRow $execution */
        return $execution;
    }

    /** @return AdRow */
    private function requireAdRow(object $ad): object
    {
        $values = get_object_vars($ad);
        foreach (['id', 'user_id', 'price_per_task', 'currency', 'status'] as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
                throw new \UnexpectedValueException("Invalid social-task ad row: {$key}");
            }
        }
        /** @var AdRow $ad */
        return $ad;
    }

    /**
     * لیست تسک‌های فعال برای کاربر با اعمال فیلتر نامحسوس
     */
    /**
         * @param SocialTaskFilters $filters
         * @return SocialTaskListResult
         */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 20): array
    {
        $restriction = $this->antiFraud->getRestrictionLevel($userId);
        $effectiveLimit = $this->antiFraud->filterTaskCount($userId, $limit);

        // Construction of Clean Filter Map for centralized Filterable Trait processing
        $mappedFilters = [];

        $platform = $this->inputString($filters, 'platform');
        if ($platform !== '') $mappedFilters['platform'] = $platform;
        $taskType = $this->inputString($filters, 'task_type');
        if ($taskType !== '') $mappedFilters['task_type'] = $taskType;
        $minimumReward = $this->inputDecimal($filters, 'min_reward');
        if (bccomp($minimumReward, '0', 8) > 0) $mappedFilters['min_reward'] = $minimumReward;
        $maximumReward = $this->inputDecimal($filters, 'max_reward');
        if (bccomp($maximumReward, '0', 8) > 0) $mappedFilters['max_reward'] = $maximumReward;

        $medianReward = $this->model->getMedianReward();
        if (empty($filters['is_mobile'])) {
            $mappedFilters['budget_cap'] = $medianReward;
        }

        $search = $this->inputString($filters, 'search');
        if ($search !== '') $mappedFilters['search'] = $search;

        $orderBy = match ($this->inputString($filters, 'sort', 'random')) {
            'price_desc' => 'sa.price_per_task DESC',
            'price_asc'  => 'sa.price_per_task ASC',
            'newest'     => 'sa.created_at DESC',
            default      => 'RAND()',
        };

        // High level secure dispatch to overhauled Model method
        $tasks = $this->model->getActiveAds(
            $userId, 
            $mappedFilters, 
            $orderBy, 
            $effectiveLimit,
            self::EXCLUDED_PLATFORMS_FROM_SOCIAL
        );

        // ✅ FIX N+1 QUERY: Fetch trust score once, not per task
        $userObj = $this->userService->findById($userId);
        $userTrustScore = $userObj ? $this->trust->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;
        
        foreach ($tasks as &$task) {
            if (!$task instanceof \stdClass || !isset($task->price_per_task) || !is_scalar($task->price_per_task)) {
                throw new \UnexpectedValueException('Active social-task query returned an invalid row');
            }
            $task->display_reward = $this->antiFraud->adjustedReward($userId, (string)$task->price_per_task);
            $task->trust_display = $userTrustScore;
        }
unset($task);

        return [
            'tasks' => $tasks,
            'restriction_level' => is_string($restriction['level'] ?? null) ? $restriction['level'] : 'normal',
            'trust_score' => $userTrustScore, // Use same cached value
        ];
    }

    /**
     * @param SocialTaskInput $data
     * @return SocialTaskResult
     */
    public function createTask(int $advertiserId, array $data): array
    {
        $platform = $this->inputString($data, 'platform', 'instagram');
        if (in_array($platform, self::EXCLUDED_PLATFORMS_FROM_SOCIAL, true)) {
            return ['success' => false, 'message' => 'تسک YouTube از SocialTask جدا است.'];
        }

        $taskType = $this->inputString($data, 'task_type', 'follow');
        $targetUrl = $this->inputString($data, 'target_url', $this->inputString($data, 'link', ''));
        $reward = $this->inputDecimal($data, 'reward_amount', $this->inputDecimal($data, 'price_per_task', '0'));
        $quantity = $this->inputPositiveInt($data, 'total_quantity', $this->inputPositiveInt($data, 'total_count', 0));
        $currency = $this->inputString($data, 'currency', 'irt');

        if ($targetUrl === '' || bccomp($reward, '0', 8) <= 0 || $quantity <= 0) {
            return ['success' => false, 'message' => 'اطلاعات مالی یا هدف تسک معتبر نیست.'];
        }

        // Financial hold, ad creation and escrow binding are one atomic flow in
        // AdSystemManager. The outer idempotency key makes a repeated HTTP request
        // replay the same result instead of allocating a second campaign hold.
        $idempotencyKey = $this->inputString($data, 'idempotency_key');
        if ($idempotencyKey === '') {
            return ['success' => false, 'message' => 'کلید یکتای ایجاد تسک الزامی است.'];
        }
        $payload = [
            'platform' => $platform,
            'task_type' => $taskType,
            'target_url' => $targetUrl,
            'reward_amount' => $reward,
            'total_quantity' => $quantity,
            'currency' => $currency,
        ];
        try {
            $result = $this->idempotencyService->execute(
                'social_task.create',
                $advertiserId,
                $payload,
                function () use ($advertiserId, $platform, $taskType, $targetUrl, $reward, $quantity, $currency, $data): array {
                    $created = $this->adSystemManager->create('social_task', $advertiserId, [
                        'platform' => $platform,
                        'task_type' => $taskType,
                        'title' => $this->inputString($data, 'title', "تسک {$taskType}"),
                        'description' => $this->inputString($data, 'description', ''),
                        'link' => $targetUrl,
                        'target_url' => $targetUrl,
                        'price_per_task' => $reward,
                        'total_count' => $quantity,
                        'total_budget' => bcmul($reward, (string)$quantity, 8),
                        'currency' => $currency,
                    ]);
                    $adId = $created['ad_id'];
                    if (!is_int($adId) && !(is_string($adId) && ctype_digit($adId))) {
                        throw new \RuntimeException('ایجاد آگهی اجتماعی شناسهٔ معتبر برنگرداند');
                    }
                    return [
                        'success' => true,
                        'message' => 'تسک اجتماعی ایجاد شد و بودجه در escrow مرکزی نگهداری شد.',
                        'task_id' => (int)$adId,
                        'ad_id' => (int)$adId,
                    ];
                },
                $idempotencyKey
            );
            return $this->normalizeSocialResult($result);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $advertiserId, [
                'operation' => 'social_task.createTask',
            ]);
            return ['success' => false, 'message' => 'خطا در ایجاد تسک: ' . $e->getMessage()];
        }
    }

    private function startExecutionJob(): \App\Jobs\SocialTask\StartSocialTaskExecutionJob
    {
        if ($this->startExecutionJob !== null) {
            return $this->startExecutionJob;
        }
        $job = $this->container->make(\App\Jobs\SocialTask\StartSocialTaskExecutionJob::class);
        if (!$job instanceof \App\Jobs\SocialTask\StartSocialTaskExecutionJob) {
            throw new \RuntimeException('StartSocialTaskExecutionJob container binding is invalid');
        }
        return $job;
    }

    private function submitExecutionJob(): \App\Jobs\SocialTask\SubmitSocialTaskExecutionJob
    {
        if ($this->submitExecutionJob !== null) {
            return $this->submitExecutionJob;
        }
        $job = $this->container->make(\App\Jobs\SocialTask\SubmitSocialTaskExecutionJob::class);
        if (!$job instanceof \App\Jobs\SocialTask\SubmitSocialTaskExecutionJob) {
            throw new \RuntimeException('SubmitSocialTaskExecutionJob container binding is invalid');
        }
        return $job;
    }

    private function approveExecutionJob(): \App\Jobs\SocialTask\ApproveSocialTaskExecutionJob
    {
        if ($this->approveJob !== null) {
            return $this->approveJob;
        }
        $job = $this->container->make(\App\Jobs\SocialTask\ApproveSocialTaskExecutionJob::class);
        if (!$job instanceof \App\Jobs\SocialTask\ApproveSocialTaskExecutionJob) {
            throw new \RuntimeException('ApproveSocialTaskExecutionJob container binding is invalid');
        }
        return $job;
    }

    private function rejectExecutionJob(): \App\Jobs\SocialTask\RejectSocialTaskExecutionJob
    {
        if ($this->rejectJob !== null) {
            return $this->rejectJob;
        }
        $job = $this->container->make(\App\Jobs\SocialTask\RejectSocialTaskExecutionJob::class);
        if (!$job instanceof \App\Jobs\SocialTask\RejectSocialTaskExecutionJob) {
            throw new \RuntimeException('RejectSocialTaskExecutionJob container binding is invalid');
        }
        return $job;
    }

    /**
         * @param SocialTaskInput $data
         * @return SocialTaskResult
         */
    public function executeTask(int $userId, array $data): array
    {
        return $this->startExecution($userId, $this->inputPositiveInt($data, 'ad_id', $this->inputPositiveInt($data, 'task_id', 0)), $data);
    }

    /** @return SocialTaskResult */
    public function adminRejectAd(int $adminId, int $adId, string $reason): array
    {
        // PRIMARY: admin SocialTask reject delegates to unified Ads settlement so campaign escrow is refunded.
        $reason = trim((string)$reason) !== '' ? trim((string)$reason) : 'رد از پنل تخصصی SocialTask';
        try {
            $result = $this->adsBudgetSettlement()->applyAdminAction($adId, 'reject', $adminId, $reason);
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => $this->resultMessage($result, !empty($result['success']) ? 'تبلیغ با موفقیت رد شد' : 'رد تبلیغ انجام نشد'),
                'refund' => $result['refund_amount'] ?? 0,
            ];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'social_task.adminRejectAd',
                'ad_id'     => $adId,
            ]);
            return ['success' => false, 'message' => 'خطا در رد تبلیغ: ' . $e->getMessage()];
        }
    }

    /** @return SocialTaskResult */
    public function adminCancelAd(int $adminId, int $adId): array
    {
        // PRIMARY: cancel delegates to unified Ads settlement. The old Saga path referenced a non-existing context and
        // dispatched refund events without reliably settling social_task_budget escrow.
        try {
            $result = $this->adsBudgetSettlement()->applyAdminAction($adId, 'cancel', $adminId, 'لغو از پنل تخصصی SocialTask');
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => $this->resultMessage($result, !empty($result['success']) ? 'تبلیغ لغو شد' : 'لغو تبلیغ انجام نشد'),
                'refund' => $result['refund_amount'] ?? 0,
                'currency' => 'irt',
            ];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'social_task.adminCancelAd',
                'ad_id'     => $adId,
            ]);
            return ['success' => false, 'message' => 'خطا در لغو تبلیغ: ' . $e->getMessage()];
        }
    }

    /** @return SocialTaskResult */
    public function adminFlagExecution(int $adminId, int $executionId, string $note = ''): array
    {
        try {
            $exec = $this->model->getExecutionById($executionId);
            if (!$exec) return ['success' => false, 'message' => 'اجرا یافت نشد'];
            $exec = $this->requireExecutionRow($exec);

            if (in_array($exec->status, ['expired', 'cancelled'], true)) {
                return ['success' => false, 'message' => 'این اجرا قابل علامت‌گذاری نیست'];
            }

            $this->model->flagExecution($executionId, $note);
            return ['success' => true, 'message' => 'اجرا برای بررسی علامت‌گذاری شد'];
        } catch (\Throwable $e) {
            // LOW-12: Log exceptions inside catch blocks to preserve diagnostic records
            $this->logger->error('social.execution.flagging_failed', [
                'execution_id' => $executionId,
                'admin_id'     => $adminId,
                'error'        => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در علامت‌گذاری اجرا'];
        }
    }

    /** @return SocialTaskResult */
    public function adminOverrideExecution(int $adminId, int $executionId, string $decision, string $reason): array
    {
        try {
            if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
                return ['success' => false, 'message' => 'تصمیم معتبر نیست'];
            }

            $reason = trim((string)$reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل override الزامی است'];

            $exec = $this->model->getExecutionById($executionId, false); // No FOR UPDATE needed without transaction

                if (!$exec) {
                    return ['success' => false, 'message' => 'اجرا یافت نشد'];
                }
                $exec = $this->requireExecutionRow($exec);

                $this->model->updateExecutionStatus($executionId, $decision, [
                    'decision' => $decision,
                    'override_reason' => $reason,
                    'overridden_by' => $adminId,
                    'overridden_at' => date('Y-m-d H:i:s')
                ]);

                if (in_array($decision, ['approved', 'soft_approved'], true)) {
                    $ad = $this->model->getAdById((int)$exec->ad_id);
                    if ($ad) {
                        $ad = $this->requireAdRow($ad);
                        $payout = (string)$ad->price_per_task;
                        $currency = $ad->currency;
                        $this->outbox?->record('social_task', $executionId, 'social_task.reward_approved', [
                            'user_id' => (int)$exec->executor_id,
                            'execution_id' => $executionId,
                            'ad_id' => $ad->id,
                            'task_type' => $exec->task_type ?? null,
                            'decision' => $decision,
                            'reward_amount' => $payout,
                            'currency' => $currency
                        ]);
                    }
                }

                return ['success' => true, 'message' => 'تصمیم با موفقیت override شد', 'old_decision' => $exec->decision ?? null, 'new_decision' => $decision];
        } catch (\Throwable $e) {
            $this->logger->error('social.admin_override_execution_failed', [
                'admin_id' => $adminId,
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation'    => 'social_task.adminOverrideExecution',
                'execution_id' => $executionId,
                'decision'     => $decision,
            ]);
            return ['success' => false, 'message' => 'خطا در override تصمیم'];
        }
    }

    /** @return SocialTaskResult */
    public function adminAdjustTrust(int $adminId, int $userId, float $delta, string $reason): array
    {
        try {
            $reason = trim((string)$reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل الزامی است'];
            if ($delta == 0.0) return ['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد'];

            $executorObj = $this->userService->findById($userId);
            if (!$executorObj) {
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            $domain = ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_TRUST, ModuleContext::SOCIAL_TASKS);
            $oldTrust = $this->scoreService->getScore('user', $userId, $domain);
            $applied = $this->scoreService->applyDelta(
                'user', $userId, $domain, $delta, 'manual_adjustment',
                ['reason' => $reason, 'admin_id' => $adminId],
                'social_task:manual_trust:' . $adminId . ':' . $userId . ':' . hash('sha256', $reason . '|' . $delta)
            );
            if (!$applied) throw new \RuntimeException('اعمال تغییر امتیاز اعتماد ناموفق بود');
            $newTrust = $this->scoreService->getScore('user', $userId, $domain);

            return ['success' => true, 'message' => 'امتیاز اعتماد با موفقیت تغییر کرد', 'old_trust' => $oldTrust, 'new_trust' => $newTrust];
        } catch (\Throwable $e) {
            $this->logger->error('social.admin_adjust_trust_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'social_task.adminAdjustTrust',
                'user_id'   => $userId,
                'delta'     => $delta,
            ]);
            return ['success' => false, 'message' => 'خطا در تغییر امتیاز اعتماد'];
        }
    }

    
    /** @param SocialTaskInput $signals */
    public function recordBehaviorSignals(int $executionId, int $userId, array $signals): bool
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec) return false;
        $exec = $this->requireExecutionRow($exec);
        if ((int)$exec->executor_id !== $userId) return false;

        $behaviorData = $this->model->getBehaviorData($executionId);
        $prevData = $behaviorData ? (array)(json_decode($behaviorData, true) ?? []) ?: [] : [];

        $merged = $this->mergeBehaviorSignals($prevData, $signals);
        $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) return false;
        $this->model->updateExecutionBehavior($executionId, $encoded);

        return true;
    }

        /**
         * @param SocialTaskInput $context
         * @return StartExecutionResult
         */
    public function startExecution(int $userId, int $adId, array $context = []): array
    {
        $job = $this->startExecutionJob();
        /** @var StartExecutionResult $result */
        $result = $this->normalizeSocialResult($job->handle($userId, $adId, $context));

        if ($result['success']) {
            $executionId = isset($result['execution_id']) ? $result['execution_id'] : null;
            if (!is_int($executionId) || $executionId <= 0) {
                throw new \UnexpectedValueException('Successful social-task start result must contain a positive execution_id');
            }
            $result['execution_id'] = $executionId;
        }

        /** @var StartExecutionResult $result */
        return $result;
    }


    /**
     * Section 8.2 — Idempotent shim. Repeated submits for the same
     * (userId, executionId) return the same cached result.
     */
    /**
         * @param SocialTaskInput $payload
         * @return SocialTaskResult
         */
    public function submitExecution(int $userId, int $executionId, array $payload = []): array
    {
        $job = $this->submitExecutionJob();
        return $this->normalizeSocialResult($job->handle($userId, $executionId, $payload));
    }

    /**
     * API compatibility alias used by SocialTaskApiController routes.
     */
    /**
         * @param SocialTaskInput $context
         * @return SocialTaskResult
         */
    public function startTask(int $userId, int $adId, array $context = []): array
    {
        return $this->startExecution($userId, $adId, $context);
    }

    /**
     * API compatibility alias used by SocialTaskApiController routes.
     */
    /**
         * @param SocialTaskInput $payload
         * @return SocialTaskResult
         */
    public function submitTask(int $userId, int $executionId, array $payload = []): array
    {
        return $this->submitExecution($userId, $executionId, $payload);
    }


    /** @return SocialTaskResult */
    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $job = $this->approveExecutionJob();
        return $this->normalizeSocialResult($job->handle($advertiserId, $executionId));
    }


    /** @return SocialTaskResult */
    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        $job = $this->rejectExecutionJob();
        return $this->normalizeSocialResult($job->handle($advertiserId, $executionId, $reason));
    }


        public function getAdById(int $adId): ?object
    {
        return $this->model->getAdById($adId);
    }

    /** @return list<\App\Models\SocialAccount> */
    public function getUserAccounts(int $userId): array
    {
        return $this->socialAccountService->getByUser($userId);
    }

    /** @return SocialTaskResult */
    public function addAccount(int $userId, string $platform, string $username, string $accessToken = ''): array
    {
        return $this->normalizeSocialResult($this->socialAccountService->register($userId, [
            'platform' => $platform,
            'account_handle' => $username,
            'access_token' => $accessToken,
        ]));
    }

    public function getExecutionById(int $executionId): ?object
    {
        return $this->model->getExecutionById($executionId);
    }

    public function getExecutionWithAd(int $executionId, int $userId): ?\stdClass
    {
        return $this->model->getExecutionWithAd($executionId, $userId);
    }

    public function getExecutorStats(int $userId): object
    {
        return $this->model->getExecutorStats($userId) ?: (object)['total' => 0, 'approved' => 0, 'soft_approved' => 0, 'rejected' => 0, 'avg_score' => 0, 'success_rate' => 0];
    }

    public function getAdvertiserAdStats(int $advertiserId, int $adId): ?object
    {
        return $this->model->getAdvertiserAdStats($adId, $advertiserId);
    }

    /** @return list<\stdClass> */
    public function getExecutorHistory(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getExecutorHistory($userId, $limit, $offset);
    }

    public function processAutoApprovals(): int
    {
        try {
            $stmt = $this->db->prepare("UPDATE social_task_executions SET status = 'approved', auto_approved_at = NOW() WHERE status = 'submitted' AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }



    

    private function sanitizeSearch(string $str): string
    {
        return (string)preg_replace('/[^\p{L}\p{N}\s]/u', '', $str) ?: '';
    }

    /**
     * @param SocialTaskInput $prev
     * @param SocialTaskInput $new
     * @return SocialTaskInput
     */
    private function mergeBehaviorSignals(array $prev, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_numeric($value) && isset($prev[$key]) && is_numeric($prev[$key])) {
                $prev[$key] = (float)$prev[$key] + (float)$value;
            } else {
                $prev[$key] = $value;
            }
        }
        return $prev;
    }

    /**
     * گزارش تخلف تسک شبکه اجتماعی (سوشیال تسک)
     */
    /** @return SocialTaskResult */
    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        return ['success' => false, 'message' => 'سرویس گزارش‌دهی موقتا در دسترس نیست'];
    }

    /**
     * امتیازدهی به تسک شبکه اجتماعی (سوشیال تسک)
     */
    /** @return SocialTaskResult */
    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            // استفاده از InteractionRatingService موجود از طریق Container
            $ratingService = $this->interactionRatingService;
            $ok = $ratingService->rate(
                $raterId,
                'social_task',
                $adId,
                ModuleContext::SOCIAL_TASKS,
                $stars
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            // Dispatch ایونت برای آپدیت Score از طریق SocialTaskEventListeners
            $this->outbox?->record('social_task', $adId, 'social_task.rated', [
                'user_id' => $raterId,
                'ad_id'   => $adId,
                'stars'   => $stars,
                'advertiser_id' => (int)($ad->user_id ?? 0),
            ]);

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $raterId, [
                'operation' => 'social_task.rateTask',
                'ad_id'     => $adId,
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
    public function getExecutionForUser(int $executionId, int $userId): ?object
    {
        return $this->model->getExecutionWithAd($executionId, $userId);
    }

    public function getExecutionForAdvertiser(int $userId, int $executionId): ?object
    {
        return $this->model->getExecutionWithAdForAdvertiser($executionId, $userId);
    }

    /** @return list<\stdClass> */
    public function getMyAds(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getByAdvertiser($userId, $limit, $offset);
    }

    /** @return list<\stdClass> */
    public function getAdExecutions(int $adId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getExecutionsByAd($adId, $limit, $offset);
    }

    /** @return SocialTaskResult */
    public function toggleAdStatus(int $userId, int $adId, string $status): array
    {
        $valid = ['active', 'paused', 'cancelled'];
        if (!in_array($status, $valid)) {
            return ['success' => false, 'message' => 'وضعیت درخواستی معتبر نیست'];
        }

        $ad = $this->model->getAdById($adId);
        if (!$ad) return ['success' => false, 'message' => 'آگهی یافت نشد یا دسترسی غیرمجاز'];
        $ad = $this->requireAdRow($ad);
        if ((int)$ad->user_id !== $userId) return ['success' => false, 'message' => 'آگهی یافت نشد یا دسترسی غیرمجاز'];

        $action = match ($status) {
            'active' => $ad->status === 'paused' ? 'resume' : 'approve',
            'paused' => 'pause',
            'cancelled' => 'cancel',
        };

        // ADVERTISER_ONLY compatibility path: keep owner check above, delegate the actual status/refund mechanics.
        $result = $this->adsBudgetSettlement()->applyAdminAction($adId, $action, $userId, 'تغییر وضعیت توسط تبلیغ‌دهنده SocialTask');
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => $this->resultMessage($result, !empty($result['success']) ? "وضعیت آگهی به {$status} تغییر یافت" : 'تغییر وضعیت انجام نشد'),
        ];
    }

    /** @return array{total_executions: int, approved_count: int, avg_rating: float, rating_count: int} */
    public function getAdvertiserSummary(int $userId): array
    {
        $stats = $this->model->getWeeklyExecutionStats($userId); // Simplification for dashboard
        $rating = $this->model->getAvgRating($userId, 'executor');

        return [
            'total_executions' => $stats->total ?? 0,
            'approved_count' => $stats->good_tasks ?? 0,
            'avg_rating' => round(floatval($rating->avg_stars ?? 0), 2),
            'rating_count' => (int) ($rating->total_ratings ?? 0),
        ];
    }

    /**
         * @param SocialTaskFilters $filters
         * @return array{total: int, items: list<\stdClass>}
         */
    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        // Using the native DB builder via the model, adhering to query standards.
        $query = $this->model->getDb()->table('social_ads')
            ->select('id', 'title', 'description', 'platform', 'task_type', 'reward', 'status', 'created_at')
            ->where('status', '=', 'active');

        $queryText = $this->inputString($filters, 'q');
        if ($queryText !== '') {
            $like = '%' . $this->sanitizeSearch($queryText) . '%';
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('description', 'LIKE', $like);
            });
        }

        $platform = $this->inputString($filters, 'platform');
        if ($platform !== '') $query->where('platform', '=', e($platform, ENT_QUOTES, 'UTF-8'));
        $taskType = $this->inputString($filters, 'task_type');
        if ($taskType !== '') $query->where('task_type', '=', e($taskType, ENT_QUOTES, 'UTF-8'));
        $minimumReward = $this->inputDecimal($filters, 'min_reward');
        if (bccomp($minimumReward, '0', 8) > 0) $query->where('reward', '>=', $minimumReward);
        $maximumReward = $this->inputDecimal($filters, 'max_reward');
        if (bccomp($maximumReward, '0', 8) > 0) $query->where('reward', '<=', $maximumReward);

        // Calculate Order By sequence
        $sort = $this->inputString($filters, 'sort', 'newest');
        [$sortCol, $sortDir] = match ($sort) {
            'oldest' => ['created_at', 'ASC'],
            'reward_high' => ['reward', 'DESC'],
            'reward_low' => ['reward', 'ASC'],
            default => ['created_at', 'DESC'],
        };

        return [
            'total' => $query->count(), // Atomic query counting
            'items' => (clone $query)->orderBy($sortCol, $sortDir)
                ->limit($limit)
                ->offset($offset)
                ->get()
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Admin helpers — delegated from SocialTaskModel / raw queries
    // ─────────────────────────────────────────────────────────────

    /**
         * @param SocialTaskFilters $filters
         * @return AdminListResult
         */
    public function getAdsForAdmin(array $filters, int $limit, int $offset): array
    {
        $params = [];
        $statusFilter = '';
        $platformFilter = '';
        $searchFilter = '';

        $status = $this->inputString($filters, 'status');
        if ($status !== '') {
            $statusFilter = " AND a.status = ?";
            $params[] = $status;
        }
        $platform = $this->inputString($filters, 'platform');
        if ($platform !== '') {
            $platformFilter = " AND a.platform = ?";
            $params[] = $platform;
        }
        $search = $this->inputString($filters, 'search');
        if ($search !== '') {
            $searchFilter = " AND (a.title LIKE ? OR a.description LIKE ?)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $listParams = array_merge($params, [$limit, $offset]);

        $q = $this->db->query(
            "SELECT a.*, u.full_name as user_name, u.email as user_email
             FROM ads a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.type IN ('social','social_task') AND a.deleted_at IS NULL
             {$statusFilter} {$platformFilter} {$searchFilter}
             ORDER BY a.created_at DESC LIMIT ? OFFSET ?",
            $listParams
        );
        $ads = $q->fetchAll(\PDO::FETCH_OBJ) ?: [];

        $countRow = $this->db->fetch(
            "SELECT COUNT(*) as c FROM ads a
             WHERE a.type IN ('social','social_task') AND a.deleted_at IS NULL
             {$statusFilter} {$platformFilter} {$searchFilter}",
            $params
        );
        $total = (int)($countRow?->c ?? 0);
        return [$ads, $total];
    }

    /** @return array{total: int, active: int, pending: int, completed: int} */
    public function getAdStatsForAdmin(): array
    {
        $row = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM ads WHERE type = 'social_task' AND deleted_at IS NULL"
        );
        return [
            'total' => (int)($row?->total ?? 0),
            'active' => (int)($row?->active ?? 0),
            'pending' => (int)($row?->pending ?? 0),
            'completed' => (int)($row?->completed ?? 0),
        ];
    }

    public function getAdByIdForAdmin(int $adId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT a.*, u.full_name as user_name, u.email as user_email
             FROM ads a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.id = ? AND a.type = 'social_task' AND a.deleted_at IS NULL",
            [$adId]
        ) ?: null;
    }

    /**
         * @param SocialTaskFilters $filters
         * @return AdminListResult
         */
    public function getExecutionsForAdmin(array $filters, int $limit, int $offset): array
    {
        $params = [];
        $statusFilter = '';
        $platformFilter = '';

        if (!empty($filters['decision'])) {
            $statusFilter = " AND e.status = ?";
            $params[] = $filters['decision'];
        }
        if (!empty($filters['platform'])) {
            $platformFilter = " AND a.platform = ?";
            $params[] = $filters['platform'];
        }

        $listParams = array_merge($params, [$limit, $offset]);

        $q = $this->db->query(
            "SELECT e.*, a.title as ad_title, u.full_name as executor_name, u.email as executor_email
             FROM social_task_executions e
             LEFT JOIN ads a ON a.id = e.ad_id
             LEFT JOIN users u ON u.id = e.executor_id
             WHERE 1=1 {$statusFilter} {$platformFilter}
             ORDER BY e.created_at DESC LIMIT ? OFFSET ?",
            $listParams
        );
        $executions = $q->fetchAll(\PDO::FETCH_OBJ) ?: [];

        $countRow = $this->db->fetch(
            "SELECT COUNT(*) as c FROM social_task_executions e
             LEFT JOIN ads a ON a.id = e.ad_id WHERE 1=1 {$statusFilter} {$platformFilter}",
            $params
        );
        $total = (int)($countRow?->c ?? 0);
        return [$executions, $total];
    }

    /** @return array{total: int, approved: int, rejected: int, submitted: int} */
    public function getExecutionStatsForAdmin(): array
    {
        $row = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted
             FROM social_task_executions"
        );
        return [
            'total' => (int)($row?->total ?? 0),
            'approved' => (int)($row?->approved ?? 0),
            'rejected' => (int)($row?->rejected ?? 0),
            'submitted' => (int)($row?->submitted ?? 0),
        ];
    }

    public function getExecutionByIdForAdmin(int $id): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT e.*, a.title as ad_title, u.full_name as executor_name, u.email as executor_email
             FROM social_task_executions e
             LEFT JOIN ads a ON a.id = e.ad_id
             LEFT JOIN users u ON u.id = e.executor_id
             WHERE e.id = ?",
            [$id]
        ) ?: null;
    }

    /** @return list<\stdClass> */
    public function getLowTrustUsersForAdmin(int $limit, int $offset): array
    {
        return $this->db->query(
            "SELECT
                u.id AS user_id,
                u.username,
                u.full_name,
                u.email,
                COALESCE(u.fraud_score, 0) AS trust_score,
                0 AS total_execs,
                0 AS rejected_execs,
                u.updated_at
             FROM users u
             WHERE u.status = 'active'
             ORDER BY COALESCE(u.fraud_score, 0) ASC, u.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countLowTrustUsersForAdmin(): int
    {
        return (int)($this->db->fetch("SELECT COUNT(*) as c FROM users WHERE status = 'active'")->c ?? 0);
    }

    /** @return array{total_users: int, high_trust: int, mid_trust: int, low_trust: int, avg_trust: float} */
    public function getTrustStatsForAdmin(): array
    {
        $total = $this->countLowTrustUsersForAdmin();
        return [
            'total_users' => $total,
            'high_trust' => 0,
            'mid_trust' => $total,
            'low_trust' => 0,
            'avg_trust' => 50.0,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getTrustHistoryForAdmin(int $userId): array
    {
        return [];
    }

    /** @return array<string, int> */
    public function getFullStatsForAdmin(): array
    {
        return array_merge($this->getAdStatsForAdmin(), $this->getExecutionStatsForAdmin());
    }

    /** @return SocialTaskResult */
    public function adminChangeAdStatus(int $adminId, int $adId, string $status): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) return ['success' => false, 'message' => 'تبلیغ یافت نشد'];

        $status = strtolower(trim((string)$status));
        $action = match ($status) {
            'paused' => 'pause',
            'active', 'approved' => ((string)($ad->status ?? '') === 'paused' ? 'resume' : 'approve'),
            'cancelled' => 'cancel',
            'rejected' => 'reject',
            default => null,
        };
        if ($action === null) {
            return ['success' => false, 'message' => 'وضعیت درخواستی معتبر نیست'];
        }

        try {
            $result = $this->adsBudgetSettlement()->applyAdminAction(
                $adId,
                $action,
                $adminId,
                'تغییر وضعیت از پنل تخصصی SocialTask'
            );
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => $this->resultMessage($result, !empty($result['success']) ? 'وضعیت تبلیغ تغییر یافت' : 'تغییر وضعیت انجام نشد'),
            ];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'social_task.adminChangeAdStatus',
                'ad_id'     => $adId,
                'status'    => $status,
            ]);
            return ['success' => false, 'message' => 'خطا در تغییر وضعیت تبلیغ: ' . $e->getMessage()];
        }
    }

    private function adsBudgetSettlement(): \App\Services\Ads\AdsBudgetSettlementService
    {
        // Lazy: AdsBudgetSettlementService وابستگی سنگین دارد و فقط در مسیر Admin Action لازم است
        return $this->adsBudgetSettlementService;
    }

    /**
     * به‌روزرسانی حساب اجتماعی کاربر (مالکیت کنترل می‌شود).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateUserAccount(int $userId, int $accountId, array $data): array
    {
        return $this->socialAccountService->updateByUser($accountId, $userId, $data);
    }

    /**
     * حذف حساب اجتماعی کاربر (مالکیت کنترل می‌شود).
     *
     * @return array{success: bool, message: string}
     */
    public function deleteUserAccount(int $userId, int $accountId): array
    {
        return $this->socialAccountService->delete($accountId, $userId);
    }

    /** @return array{0: list<\stdClass>, 1: int} */
    public function getUserAds(int $userId, int $limit, int $offset): array
    {
        $ads = $this->model->getByAdvertiser($userId, $limit, $offset);
        $total = $this->model->countByAdvertiser($userId);
        return [$ads, $total];
    }

    /**
     * ایجاد تبلیغ اجتماعی توسط تبلیغ‌دهنده.
     *
     * @param SocialTaskInput $data
     * @return SocialTaskResult
     */
    public function createUserAd(int $userId, array $data): array
    {
        return $this->createTask($userId, $data);
    }

    /** @return SocialTaskResult */
    public function pauseUserAd(int $userId, int $adId): array
    {
        return $this->toggleAdStatus($userId, $adId, 'paused');
    }

    /** @return SocialTaskResult */
    public function resumeUserAd(int $userId, int $adId): array
    {
        return $this->toggleAdStatus($userId, $adId, 'active');
    }

    /** @return SocialTaskResult */
    public function cancelUserAd(int $userId, int $adId): array
    {
        return $this->toggleAdStatus($userId, $adId, 'cancelled');
    }

    /** @return array{0: list<\stdClass>, 1: int} */
    public function getAvailableTasksForExecutor(int $limit, int $offset): array
    {
        return $this->model->getAvailableTasks($limit, $offset);
    }

    /** @return array{0: list<\stdClass>, 1: int} */
    public function getUserExecutionHistory(int $userId, int $limit, int $offset): array
    {
        $history = $this->model->getExecutorHistory($userId, $limit, $offset);
        $total = $this->model->countExecutorHistory($userId);
        return [$history, $total];
    }

}

