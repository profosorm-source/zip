<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Models\SeoExecution;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\AntiFraud\SeoFraudDetector;
use App\Services\Seo\AdsSeoService;
use App\Services\SeoPayoutService;
use App\Services\Settings\AppSettings;
use Core\EventDispatcher;
use Core\TransactionWrapper;

class ProcessSeoTaskAsyncJob
{
    public function __construct(
        private AdsSeoService $adsService,
        private FraudGuardService $fraudGuard,
        private SeoFraudDetector $fraudDetector,
        private LoggerInterface $logger,
        private SeoPayoutService $payoutService,
        private AppSettings $appSettings,
        private WalletServiceInterface $walletService,
        private TransactionWrapper $transactionWrapper,
        private SeoExecution $executionModel,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null,
        private ?\App\Services\EscrowService $escrowService = null
    ) {}

/**
 * @param array<string, mixed> $engagementData
 * @return array<string, mixed>
 */
public function handle(int $executionId, int $userId, int $adId, array $engagementData): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function () use ($executionId, $userId, $adId, $engagementData) {
                $execution = $this->executionModel->findByIdForUpdate($executionId);
                if (!$execution || (int)$execution->user_id !== $userId || (int)$execution->ad_id !== $adId) {
                    return ['success' => false, 'message' => 'اجرای تسک یافت نشد.'];
                }

                if ((string)$execution->status !== 'started') {
                    return ['success' => false, 'message' => 'این تسک قبلاً پردازش شده یا قابل تکمیل نیست.'];
                }

                $ad = $this->adsService->getAdForUpdate($adId);
                if (!$ad || (string)$ad->status !== 'active') {
                    return ['success' => false, 'message' => 'آگهی فعال یافت نشد.'];
                }

                if (!isset($engagementData['duration'], $engagementData['scroll_depth'], $engagementData['interactions'])) {
                    $this->adsService->rejectExecution($executionId, 'داده‌های تعامل ناقص است');
                    return ['success' => false, 'message' => 'داده‌های تعامل ناقص است.'];
                }

                // Root fix (H-10): the payout-driving score must be anchored to a
                // server-observed source, not solely to client-reported metrics.
                // The server authoritatively knows when the execution started, so we
                // (a) reject completions that arrive faster than the required minimum
                // real elapsed time, and (b) clamp the client-reported duration to the
                // real server-measured elapsed time so it cannot be inflated for payout.
                $targetDurationSec = max(10, (int)($ad->target_duration ?? 60));
                $minRealSeconds = max(5, (int)floor($targetDurationSec * 0.25));
                $serverElapsed = $this->executionModel->secondsSinceStart($executionId);
                if ($serverElapsed < $minRealSeconds) {
                    $this->adsService->rejectExecution(
                        $executionId,
                        "زمان واقعی سپری‌شده ({$serverElapsed}s) کمتر از حد مجاز ({$minRealSeconds}s) است",
                        30
                    );
                    $this->logger->warning('seo_task.server_elapsed_too_short', [
                        'user_id'        => $userId,
                        'execution_id'   => $executionId,
                        'server_elapsed' => $serverElapsed,
                        'min_required'   => $minRealSeconds,
                    ]);
                    return ['success' => false, 'message' => 'زمان کافی روی این تسک سپری نشده است.'];
                }

                // Clamp client-claimed timings to the server-measured elapsed time so a
                // manipulated client cannot inflate the time-based portion of the score.
                $engagementData['duration'] = min(int_value($engagementData['duration'] ?? 0), $serverElapsed);
                if (isset($engagementData['active_time'])) {
                    $engagementData['active_time'] = min(int_value($engagementData['active_time']), $serverElapsed);
                }

                $scores = $this->calculateEngagementScore($engagementData, $ad);

                // Root fix (H-10, completion): validate start→complete context continuity
                // on the server side and thread the server-issued session token into the
                // fraud evaluation (evidence binding). A completion arriving from a
                // materially different client context than the one that started the task
                // is a strong manipulation/replay signal, so it raises the server-computed
                // fraud score. Fingerprint is masked (/24 + session + app salt), so a
                // legitimate network change alone stays below the block threshold.
                $currentFingerprint = function_exists('generate_device_fingerprint')
                    ? generate_device_fingerprint()
                    : md5(get_user_agent() . get_client_ip());
                $startFingerprint = (string)($execution->device_fingerprint ?? '');
                $contextMismatch = $startFingerprint !== '' && !hash_equals($startFingerprint, $currentFingerprint);
                if ($contextMismatch) {
                    $existingFlags = is_array($scores['fraud_flags'] ?? null) ? $scores['fraud_flags'] : [];
                    $existingFlags[] = 'context_fingerprint_mismatch';
                    $scores['fraud_flags'] = $existingFlags;
                    $scores['fraud_score'] = min(100, int_value($scores['fraud_score'] ?? 0) + 40);
                    $this->logger->warning('seo_task.context_mismatch', [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                    ]);
                }

                $risk = $this->fraudGuard->checkAction($userId, 'task.seo', [
                    'ad_id'            => (int)$ad->id,
                    'execution_id'     => $executionId,
                    'session_id'       => (string)($execution->session_id ?? ''),
                    'engagement_data'  => $engagementData,
                    'context_mismatch' => $contextMismatch,
                    'server_elapsed'   => $serverElapsed,
                ]);

                $riskDetails = $risk['details'];
                $seoFraud = $riskDetails['seo_fraud'] ?? null;
                if (!is_array($seoFraud)
                    || !is_bool($seoFraud['is_fraud'] ?? null)
                    || !is_array($seoFraud['flags'] ?? null)
                    || array_filter($seoFraud['flags'], 'is_string') !== $seoFraud['flags']) {
                    throw new \UnexpectedValueException('FraudGuard violated the task.seo result contract.');
                }
                $isFraud = $seoFraud['is_fraud'];

                if (empty($risk['allowed']) || $isFraud) {
                    $flags = is_array($seoFraud['flags'] ?? null) ? $seoFraud['flags'] : ['blocked_by_security_policy'];
                    $this->adsService->markExecutionAsFraud($executionId, $flags);
                    $this->fraudDetector->addToBlacklist($userId, implode(', ', $flags));
                    $this->logger->warning('seo_task.blocked_by_fraud_guard', [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                        'reason'       => $risk['reason'] ?? 'fraud_detected',
                    ]);
                    return ['success' => false, 'message' => 'تعامل شما معتبر تشخیص داده نشد.', 'fraud_detected' => true];
                }

                if (int_value($scores['fraud_score'] ?? 0) >= 85) {
                    $flagsData = is_array($scores['fraud_flags'] ?? null) ? $scores['fraud_flags'] : ['seo_severe_behavior_anomaly'];
                    $this->adsService->markExecutionAsFraud($executionId, $flagsData);
                    return [
                        'success' => false,
                        'message' => 'تعامل شما برای این تسک معتبر تشخیص داده نشد.',
                        'fraud_detected' => true,
                        'score_breakdown' => $scores,
                    ];
                }

                $minScore = float_value($ad->min_score ?? 40);
                $finalScore = float_value($scores['final_score'] ?? 0);
                if ($finalScore < $minScore) {
                    $this->adsService->rejectExecution($executionId, "امتیاز کمتر از حد مجاز ({$minScore})", int_value($scores['fraud_score'] ?? 0));
                    return ['success' => false, 'message' => "امتیاز شما ({$finalScore}) کمتر از حداقل مجاز است."];
                }

                $payoutResult = $this->payoutService->calculatePayout(int_value($ad->id), $finalScore);
                if (empty($payoutResult['can_pay'])) {
                    $this->adsService->rejectExecution($executionId, str_value($payoutResult['message'] ?? 'امکان پرداخت وجود ندارد'));
                    return ['success' => false, 'message' => $payoutResult['message'] ?? 'امکان پرداخت وجود ندارد.'];
                }

                $payout = str_value($payoutResult['payout']);

                if (!$this->adsService->completeExecution($executionId, $scores, $payout)) {
                    throw new \RuntimeException('این تسک قبلاً تکمیل یا لغو شده است.');
                }

                if (!$this->payoutService->deductFromBudget((int)$ad->id, $payout)) {
                    throw new \RuntimeException('موجودی امانی آگهی کافی نیست.');
                }

                $adCurrency = strtolower((string)($ad->currency ?? $this->appSettings->get('currency_mode', 'irt')));
                if (!in_array($adCurrency, ['irt', 'usdt'], true)) {
                    $adCurrency = 'irt';
                }

                $walletResult = null;
                $escrowService = $this->escrowService;
                if ($escrowService) {
                    $escrow = $escrowService->getByOrder((int)$ad->id, 'seo_ad_budget');
                } else {
                    $escrow = null;
                }
                if ($escrow && $escrowService) {
                    $release = $escrowService->partialRelease((int)$escrow->id, $userId, $payout, 'seo_task_reward_' . $executionId);
                    if (empty($release['ok'])) {
                        throw new \RuntimeException($release['error'] ?? 'خطا در آزادسازی وجه SEO از escrow.');
                    }
                    $walletResult = ['success' => true, 'transaction_id' => 'escrow_seo_release_' . (int)$escrow->id . '_' . $executionId];
                } else {
                    $walletResult = $this->walletService->depositInTransaction(
                        $userId,
                        $payout,
                        $adCurrency,
                        [
                            'source' => 'seo_task_reward',
                            'description' => 'تسک SEO - ' . (string)($ad->title ?? ''),
                            'execution_id' => $executionId,
                            'ad_id' => (int)$ad->id,
                            'idempotency_key' => 'seo_task_reward_' . $executionId,
                        ]
                    );
                }

                if (empty($walletResult['success'])) {
                    throw new \RuntimeException('خطا در واریز پاداش.');
                }

                $userRecord = $this->adsService->getUser($userId);
                if ($userRecord && !empty($userRecord->referred_by)) {
                    $this->outbox?->record('referral', $userId, 'referral.commission.process', [
                        'referrer_id' => (int)$userRecord->referred_by,
                        'amount' => $payout,
                        'currency' => $adCurrency,
                        'source_user_id' => $userId,
                        'context' => [
                            'action' => 'seo_task_reward',
                            'executor_id' => $userId,
                            'execution_id' => $executionId,
                        ],
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'تسک با موفقیت تایید شد و پاداش واریز گردید.',
                    'payout' => $payout,
                    'score' => $finalScore,
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('seo_task.process_failed', [
                'user_id' => $userId,
                'execution_id' => $executionId,
                'ad_id' => $adId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در پردازش تسک SEO: ' . $e->getMessage()];
        }
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
private function calculateEngagementScore(array $data, object $ad): array
    {
        $targetDuration = max(10, (int)($ad->target_duration ?? 60));
        $duration = max(0, min(3600, int_value($data['duration'] ?? 0)));
        $activeTime = max($duration, int_value($data['active_time'] ?? 0));
        $scrollDepth = max(0.0, min(100.0, float_value($data['scroll_depth'] ?? $data['scrollDepth'] ?? 0)));
        $interactions = max(0, int_value($data['interactions'] ?? 0));
        $behavior = is_array($data['behavior'] ?? null) ? $data['behavior'] : [];
        $scrollSpeed = max(0.0, float_value($data['scroll_speed'] ?? ($behavior['scroll_speed'] ?? 0)));
        $mousePattern = str_value($data['mouse_pattern'] ?? ($behavior['mouse_pattern'] ?? 'normal'));
        $pauseCount = max(0, int_value($data['pause_count'] ?? ($behavior['pause_count'] ?? 0)));
        $focusBlurCount = max(0, int_value($data['focus_blur_count'] ?? ($behavior['focus_blur_count'] ?? 0)));
        $clientMode = str_value($data['client_mode'] ?? ($behavior['client_mode'] ?? 'web'));
        $targetOpened = !empty($data['target_opened']) || !empty($behavior['target_opened']);
        $interactionTypes = $data['interaction_types'] ?? ($behavior['interaction_types'] ?? []);
        if (!is_array($interactionTypes)) {
            $interactionTypes = [];
        }
        $interactionTypes = array_values(array_unique(array_map('strval', $interactionTypes)));
        if (in_array('external_open', $interactionTypes, true) || in_array('return_to_task', $interactionTypes, true)) {
            $targetOpened = true;
        }

        $ratio = min(1.0, $duration / max(1, $targetDuration));
        $timeScore = round($ratio * 30.0, 2);

        $scrollScore = match (true) {
            $scrollDepth >= 80 => 25.0,
            $scrollDepth >= 50 => 18.0,
            $scrollDepth >= 20 => 10.0,
            default => 0.0,
        };

        $typeBonus = min(8.0, count($interactionTypes) * 2.0);
        $interactionScore = min(25.0, match (true) {
            $interactions >= 7 => 18.0,
            $interactions >= 4 => 13.0,
            $interactions >= 1 => 7.0,
            default => 0.0,
        } + $typeBonus);

        $qualityScore = 20.0;
        $fraudScore = 0;
        $flags = [];

        if (!$targetOpened) {
            $qualityScore -= 8.0;
            $fraudScore += 15;
            $flags[] = 'target_not_opened';
        }
        if ($duration < max(5, (int)floor($targetDuration * 0.25))) {
            $qualityScore -= 7.0;
            $fraudScore += 30;
            $flags[] = 'too_short_duration';
        }
        if ($scrollSpeed > 5000) {
            $qualityScore -= 7.0;
            $fraudScore += 25;
            $flags[] = 'unnatural_scroll_speed';
        } elseif ($scrollSpeed > 3000) {
            $qualityScore -= 3.0;
            $fraudScore += 10;
            $flags[] = 'high_scroll_speed';
        }
        if ($mousePattern === 'linear') {
            $qualityScore -= 5.0;
            $fraudScore += 15;
            $flags[] = 'linear_mouse_pattern';
        }
        if ($pauseCount < 1 && $duration >= 20) {
            $qualityScore -= 4.0;
            $fraudScore += 10;
            $flags[] = 'no_pause';
        }
        if (count($interactionTypes) < 1) {
            $qualityScore -= 4.0;
            $fraudScore += 10;
            $flags[] = 'no_interaction_type';
        }
        if ($focusBlurCount > 8) {
            $qualityScore -= 4.0;
            $fraudScore += 10;
            $flags[] = 'excessive_focus_switch';
        }
        $qualityScore = max(0.0, $qualityScore);

        $finalScore = max(0.0, min(100.0, $timeScore + $scrollScore + $interactionScore + $qualityScore));
        $fraudScore = max(0, min(100, $fraudScore + (int)(max(0, 40 - $finalScore) / 2)));

        return [
            'time_score' => $timeScore,
            'scroll_score' => $scrollScore,
            'interaction_score' => $interactionScore,
            'quality_score' => round($qualityScore, 2),
            'final_score' => round($finalScore, 2),
            'fraud_score' => (int)round($fraudScore),
            'fraud_flags' => $flags,
            'target_duration' => $targetDuration,
            'engagement_data' => [
                'duration' => $duration,
                'active_time' => $activeTime,
                'target_duration' => $targetDuration,
                'scroll_depth' => $scrollDepth,
                'interactions' => $interactions,
                'scroll_speed' => $scrollSpeed,
                'mouse_pattern' => $mousePattern,
                'pause_count' => $pauseCount,
                'interaction_types' => $interactionTypes,
                'target_opened' => $targetOpened,
                'focus_blur_count' => $focusBlurCount,
                'client_mode' => $clientMode,
            ],
        ];
    }

}
