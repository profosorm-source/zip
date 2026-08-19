<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Models\SocialTaskExecutionModel;
use App\Services\Shared\IdempotencyService;
use App\Services\SocialTask\BehaviorAnalysisService;
use App\Services\SocialTask\CameraVerificationService;

class SubmitSocialTaskExecutionJob
{
    private const AUTO_APPROVE_SCORE = 70.0;
    private const REJECT_SCORE = 45.0;

    public function __construct(
        private SocialTaskExecutionModel $executionModel,
        private IdempotencyService $idempotencyService,
        private BehaviorAnalysisService $behaviorAnalysis,
        private CameraVerificationService $cameraVerification,
        private ApproveSocialTaskExecutionJob $approveJob,
        private RejectSocialTaskExecutionJob $rejectJob
    ) {}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
public function handle(int $userId, int $executionId, array $payload = []): array
    {
        $explicitKey = str_value($payload['idempotency_key'] ?? '');
        if ($explicitKey === '') {
            $explicitKey = 'social_task_complete_' . $userId . '_' . $executionId . '_' . md5((string)json_encode([
                'active_time' => $payload['active_time'] ?? null,
                'behavior_signals' => $payload['behavior_signals'] ?? null,
                'camera_token' => $payload['camera_token'] ?? null,
            ], JSON_UNESCAPED_UNICODE));
        }

        return $this->idempotencyService->execute(
            'social_task.complete',
            $userId,
            ['execution_id' => $executionId, 'idempotency_key' => $explicitKey],
            function () use ($userId, $executionId, $payload) {
                $exec = $this->executionModel->getExecutionWithAd($executionId, $userId, false);
                if (!$exec) {
                    return ['success' => false, 'message' => 'رکورد اجرا یافت نشد.'];
                }
                if (!in_array((string)$exec->status, ['in_progress', 'started'], true)) {
                    return ['success' => false, 'message' => 'وضعیت اجرا برای محاسبه نتیجه مناسب نیست.'];
                }

                // SocialTask در معماری جدید proofless است: کاربر متن/لینک/اسکرین‌شات ارسال نمی‌کند.
                // تصمیم بر اساس الگوی انسانی، زمان فعال، تعامل، و در صورت نیاز camera verification گرفته می‌شود.
                $behaviorData = $this->decodeBehaviorData($exec->behavior_data ?? null);
                if (!empty($payload['behavior_signals']) && is_array($payload['behavior_signals'])) {
                    $behaviorData = array_merge($behaviorData, $payload['behavior_signals']);
                }

                $started = strtotime((string)($exec->started_at ?? $exec->created_at));
                $activeTime = isset($payload['active_time']) ? max(0, int_value($payload['active_time'])) : max(0, time() - ($started ?: time()));
                $expectedTime = max(1, (int)($exec->expected_time ?? $behaviorData['expected_time'] ?? 30));
                $activeTime = max($activeTime, (int)($behaviorData['active_time'] ?? 0), (int)($behaviorData['session_duration'] ?? 0));
                $behaviorData['active_time'] = $activeTime;
                $behaviorData['session_duration'] = max((int)($behaviorData['session_duration'] ?? 0), $activeTime);
                $behaviorData['expected_time'] = $expectedTime;

                $analysis = $this->behaviorAnalysis->analyze($behaviorData);
                $behaviorScore = float_value($analysis['behavior_score'] ?? 50);
                $patterns = $analysis['patterns'] ?? [];
                $isMobileApp = ($behaviorData['client_mode'] ?? '') === 'mobile_app' || !empty($behaviorData['is_mobile']);
                $cameraScore = isset($behaviorData['camera_score']) ? (int)$behaviorData['camera_score'] : null;

                if ($isMobileApp && $cameraScore === null && $this->cameraVerification->isRequired($executionId, $behaviorScore, $behaviorData)) {
                    $requestId = $this->cameraVerification->createRequest($executionId, $userId, 'mobile_camera', [
                        'client_mode' => $behaviorData['client_mode'] ?? 'mobile_app',
                        'client_version' => $behaviorData['client_version'] ?? null,
                    ]);
                    $this->executionModel->updateExecutionStatus($executionId, (string)$exec->status, [
                        'behavior_data' => json_encode($behaviorData, JSON_UNESCAPED_UNICODE),
                        'behavior_score' => $behaviorScore,
                        'verification_required' => 1,
                        'verification_method' => 'mobile_camera',
                        'verification_requested_at' => date('Y-m-d H:i:s'),
                        'flag_review' => 1,
                        'flag_note' => 'mobile_camera_required',
                    ]);
                    return [
                        'success' => false,
                        'message' => 'رفتار این اجرا نیاز به تأیید دوربین موبایل دارد. تصویر خام ذخیره یا ارسال نمی‌شود؛ فقط نتیجه تحلیل به امتیاز اضافه می‌شود.',
                        'camera_required' => true,
                        'camera_request_id' => $requestId,
                        'behavior_score' => $behaviorScore,
                        'patterns' => $patterns,
                    ];
                }

                $score = $this->calculateFinalScore(
                    behaviorScore: $behaviorScore,
                    activeTime: $activeTime,
                    expectedTime: $expectedTime,
                    behaviorData: $behaviorData,
                    cameraScore: $cameraScore
                );

                $finalScore = float_value($score['final_score'] ?? 0.0);
                $data = [
                    'active_time' => $activeTime,
                    'behavior_data' => json_encode($behaviorData, JSON_UNESCAPED_UNICODE),
                    'task_score' => (int)round($finalScore),
                    'anti_fraud_score' => (int)round(100.0 - $finalScore),
                    'decision' => $score['decision'],
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'behavior_score' => $score['behavior_score'],
                    'time_score' => $score['time_score'],
                    'interaction_score' => $score['interaction_score'],
                    'camera_score' => $cameraScore,
                    'final_score' => $score['final_score'],
                    'score_breakdown' => json_encode($score, JSON_UNESCAPED_UNICODE),
                    'verification_required' => $cameraScore !== null ? 1 : 0,
                    'verification_method' => $cameraScore !== null ? 'mobile_camera' : null,
                    'verification_completed_at' => $cameraScore !== null ? date('Y-m-d H:i:s') : null,
                    'client_mode' => $behaviorData['client_mode'] ?? ($isMobileApp ? 'mobile_app' : 'web'),
                    'client_version' => $behaviorData['client_version'] ?? null,
                ];

                if ($score['final_score'] >= self::AUTO_APPROVE_SCORE) {
                    $this->executionModel->updateExecutionStatus($executionId, 'submitted', $data);
                    $approve = $this->approveJob->handle((int)$exec->user_id, $executionId);
                    return array_merge($approve, [
                        'status' => !empty($approve['success']) ? 'approved' : 'submitted',
                        'execution_id' => $executionId,
                        'task_score' => $score['final_score'],
                        'score_breakdown' => $score,
                    ]);
                }

                if ($score['final_score'] < self::REJECT_SCORE) {
                    $this->executionModel->updateExecutionStatus($executionId, 'submitted', array_merge($data, [
                        'flag_review' => 1,
                        'flag_note' => 'auto_reject_low_score',
                    ]));
                    $reject = $this->rejectJob->handle((int)$exec->user_id, $executionId, 'امتیاز اعتبارسنجی تسک اجتماعی کمتر از حد مجاز بود.');
                    return array_merge($reject, [
                        'status' => !empty($reject['success']) ? 'rejected' : 'submitted',
                        'execution_id' => $executionId,
                        'task_score' => $score['final_score'],
                        'score_breakdown' => $score,
                    ]);
                }

                $this->executionModel->updateExecutionStatus($executionId, 'submitted', array_merge($data, [
                    'flag_review' => 1,
                    'flag_note' => 'manual_review_score_' . round($finalScore, 2),
                ]));

                return [
                    'success' => true,
                    'message' => 'نتیجه تسک بدون نیاز به مدرک کاربر محاسبه شد و برای بررسی تکمیلی ثبت شد.',
                    'status' => 'submitted',
                    'execution_id' => $executionId,
                    'task_score' => $score['final_score'],
                    'score_breakdown' => $score,
                ];
            },
            $explicitKey
        );
    }

    /** @return array<string, mixed> */
private function decodeBehaviorData(?string $json): array
    {
        if (!$json) return [];
        $data = (array)(json_decode($json, true) ?? []);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $behaviorData
     * @return array<string, mixed>
     */
    private function calculateFinalScore(float $behaviorScore, int $activeTime, int $expectedTime, array $behaviorData, ?int $cameraScore): array
    {
        $timeRatio = min(1.0, $activeTime / max(1, $expectedTime));
        $timeScore = $timeRatio * 100;

        $tap = int_value($behaviorData['tap_count'] ?? 0);
        $scroll = int_value($behaviorData['scroll_count'] ?? 0);
        $swipe = int_value($behaviorData['swipe_count'] ?? 0);
        $hesitation = int_value($behaviorData['hesitation_count'] ?? 0);
        $naturalDelay = int_value($behaviorData['natural_delay_count'] ?? 0);
        $interactionTypes = ($tap > 0 ? 1 : 0) + ($scroll > 0 ? 1 : 0) + ($swipe > 0 ? 1 : 0);
        $interactionScore = min(100.0, ($interactionTypes * 25.0) + min(25.0, ($tap + $scroll + $swipe) * 3.0) + min(25.0, ($hesitation + $naturalDelay) * 5.0));

        if ($cameraScore !== null) {
            $final = ($behaviorScore * 0.35) + ($timeScore * 0.20) + ($interactionScore * 0.15) + (max(0, min(100, $cameraScore)) * 0.30);
            $decision = $final >= self::AUTO_APPROVE_SCORE ? 'auto_approved_with_mobile_camera' : ($final < self::REJECT_SCORE ? 'auto_rejected_with_mobile_camera' : 'manual_review_with_mobile_camera');
        } else {
            $final = ($behaviorScore * 0.55) + ($timeScore * 0.25) + ($interactionScore * 0.20);
            $decision = $final >= self::AUTO_APPROVE_SCORE ? 'auto_approved' : ($final < self::REJECT_SCORE ? 'auto_rejected' : 'manual_review');
        }

        return [
            'final_score' => round(max(0.0, min(100.0, $final)), 2),
            'behavior_score' => round($behaviorScore, 2),
            'time_score' => round($timeScore, 2),
            'interaction_score' => round($interactionScore, 2),
            'camera_score' => $cameraScore,
            'decision' => $decision,
            'thresholds' => [
                'auto_approve' => self::AUTO_APPROVE_SCORE,
                'reject' => self::REJECT_SCORE,
            ],
        ];
    }
}
