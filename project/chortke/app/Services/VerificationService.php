<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\InfluencerModel;
use App\Models\InfluencerVerification;
use Core\Database;
use App\Services\Settings\AppSettings;
use Core\EventDispatcher;

/**
 * VerificationService - Influencer verification without external APIs
 * 
 * Verification Method:
 * 1. User provides Instagram username
 * 2. System generates verification code
 * 3. User posts verification code in specific story/post
 * 4. Admin or system verifies manually
 * 
 * No external API calls - all verification is user-initiated
 */
class VerificationService
{
    private InfluencerModel $profileModel;
    private InfluencerVerification $verificationModel;

    private AppSettings $appSettings;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private \Core\UrlGenerator $urlGenerator;

    /**
     * Centralized toObject (root-cause normalization).
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        InfluencerModel $profileModel,
        InfluencerVerification $verificationModel,
        AppSettings $appSettings,
        EventDispatcher $eventDispatcher,
        \Core\UrlGenerator $urlGenerator
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->appSettings = $appSettings;
        $this->profileModel = $profileModel;
        $this->verificationModel = $verificationModel;
        }

    /**
     * dispatch رویداد sync پروفایل — جایگزین Container::getInstance در InfluencerModel
     * VerificationService مسئول dispatch است نه Model
     * intentional: این event in-process و sync است — نیازی به Outbox ندارد
     */
    /** @param array<string, mixed> $data */
    private function dispatchProfileUpdated(int $profileId, array $data): void
    {
        try {
            $this->eventDispatcher->dispatch('influencer.profile_updated', [
                'id'             => $profileId,
                'user_id'        => $data['user_id'] ?? null,
                'updated_fields' => array_keys($data),
            ]);
        } catch (\Throwable) {
            // Non-blocking — search projection sync failure must not abort verification
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Code Generation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate verification code for influencer profile
     * 
     * Code format: Random alphanumeric 8 characters
     * User must post this in a story/bio to prove profile ownership
     */
    /** @return array<string, mixed> */
    public function generateVerificationCode(int $profileId): array
    {
        try {
            // ✅ Generate random code
            $code = $this->generateRandomCode();

            $existing = $this->verificationModel->findPendingByProfile($profileId);
            if ($existing) {
                return [
                    'ok' => true,
                    'code' => $existing->code,
                    'expires_at' => $existing->expires_at,
                    'message' => 'کد تایید قبلاً برای این پروفایل ایجاد شده است'
                ];
            }

            $this->verificationModel->expirePendingForProfile($profileId);

            $hours = int_value($this->appSettings->get('verification_otp_validity_hours', 24));
            $expiresAt = date('Y-m-d H:i:s', (strtotime('+' . $hours . ' hours') ?: time()));
            $this->verificationModel->createVerification($profileId, $code, $expiresAt);

            $this->logger->info('verification.code.generated', [
                'profile_id' => $profileId,
                'code_hash' => hash('sha256', $code),
            ]);

            return [
                'ok' => true,
                'code' => $code,
                'expires_at' => $expiresAt,
                'message' => 'کد تایید تولید شد. این کد را در کاپشن تصویر/استوری خود قرار دهید.',
                'instructions' => [
                    '۱. یک تصویر یا استوری از پروفایل خود انتشار دهید',
                    '۲. کد زیر را در کاپشن یا توضیح قرار دهید: ' . $code,
                    '۳. پس از انتشار، درخواست تایید را ارسال کنید',
                    '۴. در ظرف ۲۴ ساعت تایید کنید یا کد منقضی می‌شود'
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('verification.code.generation.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تولید کد تایید'];
        }
    }

    /**
     * Generate random alphanumeric code
     */
    private function generateRandomCode(?int $length = null): string
    {
        if ($length === null) {
            $length = int_value($this->appSettings->get('verification_otp_length', 8));
        }
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen((string)$chars) - 1)];
        }
        
        return $code;
    }

    /**
     * Get influencer profile only if owned by the requesting user.
     */
    public function getUserProfile(int $profileId, int $userId): ?object
    {
        return $this->profileModel->findProfileOwnedByUser($profileId, $userId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Manual Verification by Admin/User
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * User submits proof of verification (screenshot of post with code)
     */
    /**
     * @param array<string, mixed> $clientSignals
     * @return array<string, mixed>
     */
    public function submitVerificationProof(int $profileId, int $userId, string $proofUrl, ?string $screenshotPath = null, array $clientSignals = []): array
    {
        try {
            $verification = $this->verificationModel->findPendingByProfile($profileId);
            if (!$verification) {
                return ['ok' => false, 'error' => 'کد تایید معتبر یافت نشد'];
            }

            if (empty($proofUrl) || !$this->isValidProofUrl($proofUrl)) {
                return ['ok' => false, 'error' => 'URL اثبات نامعتبر است'];
            }

            $profile = $this->toObject($this->profileModel->find($profileId));
        if (!$profile) { 
        return ['ok' => false, 'error' => 'پروفایل اینفلوئنسر یافت نشد'];
            }
            if ((int)($profile->user_id ?? 0) !== $userId) {
                return ['ok' => false, 'error' => 'دسترسی غیرمجاز'];
            }

            $auto = $this->evaluateScreenshotProof($profile, $verification, $proofUrl, $screenshotPath, $clientSignals);
            $proofData = [
                'post_url' => $proofUrl,
                'screenshot_path' => $screenshotPath,
                'method' => 'screenshot_verification',
                'auto_verification' => $auto,
                'client_signals' => $clientSignals,
            ];

            if (($auto['decision'] ?? '') === 'auto_verified') {
                $this->db->beginTransaction();
                $this->verificationModel->updateStatus((int)$verification->id, 'approved', [
                    'post_url' => $proofUrl,
                    'proof_url' => $screenshotPath ?: $proofUrl,
                    'proof_data' => json_encode($proofData, JSON_UNESCAPED_UNICODE),
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'approved_at' => date('Y-m-d H:i:s'),
                    // L-15 FIX: an automatic approval has no human actor. Writing admin_id = 0
                    // forged a non-existent admin in the audit trail and made system decisions
                    // indistinguishable from manual ones. Both columns are nullable, so the
                    // absence of an actor is now represented honestly as NULL.
                    'admin_id' => null,
                ]);
                $this->profileModel->updateProfile($profileId, [
                    'status' => InfluencerModel::STATUS_VERIFIED,
                    'verification_post_url' => $proofUrl,
                    'verified_at' => date('Y-m-d H:i:s'),
                    'verified_by' => null,
                ]);
                $this->db->commit();
                $this->dispatchProfileUpdated($profileId, ['status' => InfluencerModel::STATUS_VERIFIED]);

                $this->logger->info('verification.proof.auto_verified', [
                    'profile_id' => $profileId,
                    'user_id' => $userId,
                    'verification_id' => $verification->id,
                    'score' => $auto['score'] ?? null,
                ]);

                return [
                    'ok' => true,
                    'auto_verified' => true,
                    'message' => 'پیج شما با بررسی اسکرین‌شات به‌صورت خودکار تأیید شد.',
                    'verification_id' => $verification->id,
                    'score' => $auto['score'] ?? null,
                ];
            }

            $profileUpdateData = ['status' => InfluencerModel::STATUS_PENDING_ADMIN_REVIEW, 'verification_post_url' => $proofUrl];
            $this->profileModel->updateProfile($profileId, $profileUpdateData);
            $this->dispatchProfileUpdated($profileId, $profileUpdateData);

            $this->verificationModel->updateStatus((int)$verification->id, 'submitted', [
                'post_url' => $proofUrl,
                'proof_url' => $screenshotPath ?: $proofUrl,
                'proof_data' => json_encode($proofData, JSON_UNESCAPED_UNICODE),
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logger->info('verification.proof.submitted_for_admin', [
                'profile_id' => $profileId,
                'user_id' => $userId,
                'verification_id' => $verification->id,
                'score' => $auto['score'] ?? null,
                'reason' => $auto['reason'] ?? null,
            ]);

            return [
                'ok' => true,
                'auto_verified' => false,
                'message' => 'مدرک شما ثبت شد. چون تایید خودکار قطعی نبود، برای بررسی مدیر ارسال شد.',
                'verification_id' => $verification->id,
                'score' => $auto['score'] ?? null,
            ];
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('verification.proof.submission.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در ارسال اثبات'];
        }
    }

    /**
     * @param array<string, mixed> $clientSignals
     * @return array<string, mixed>
     */
    private function evaluateScreenshotProof(object $profile, object $verification, string $proofUrl, ?string $screenshotPath, array $clientSignals = []): array
    {
        $score = 0;
        $reasons = [];
        $code = strtoupper((string)($verification->code ?? ''));
        $visibleCode = $clientSignals['visible_code'] ?? '';
        $declaredCode = strtoupper(trim(is_scalar($visibleCode) ? (string)$visibleCode : ''));

        if ($screenshotPath) {
            $score += 40;
            $reasons[] = 'screenshot_uploaded';
        }

        if ($this->proofUrlMatchesPlatform((string)($profile->platform ?? ''), $proofUrl)) {
            $score += 25;
            $reasons[] = 'proof_url_matches_platform';
        }

        if ($code !== '' && $declaredCode !== '' && hash_equals($code, $declaredCode)) {
            $score += 35;
            $reasons[] = 'verification_code_declared_visible';
        }

        $score = max(0, min(100, $score));
        return [
            'score' => $score,
            'decision' => $score >= 85 ? 'auto_verified' : 'admin_review',
            'reason' => implode(',', $reasons),
            'requires_admin_review' => $score < 85,
        ];
    }

    private function proofUrlMatchesPlatform(string $platform, string $proofUrl): bool
    {
        $host = strtolower((string)(parse_url($proofUrl, PHP_URL_HOST) ?: ''));
        return match (strtolower((string)$platform)) {
            'instagram' => str_contains($host, 'instagram.com'),
            'telegram' => str_contains($host, 't.me') || str_contains($host, 'telegram.me'),
            default => filter_var($proofUrl, FILTER_VALIDATE_URL) !== false,
        };
    }

    /**
     * Get pending verification requests for admin review
     */
    /** @return list<\stdClass> */
    public function getVerificationRequests(int $limit = 50, int $offset = 0): array
    {
        return $this->verificationModel->getSubmittedRequests($limit, $offset);
    }

    public function countVerificationRequests(): int
    {
        return $this->verificationModel->countSubmittedRequests();
    }

    public function getVerificationById(int $verificationId): ?object
    {
        $v = $this->toObject($this->verificationModel->findById($verificationId));
        if (!$v) { return null; }
        return $v;
    }

    public function getPendingVerificationByProfile(int $profileId): ?\stdClass
    {
        return $this->verificationModel->findSubmittedByProfile($profileId);
    }

    /**
     * Admin approves verification
     */
    /** @return array<string, mixed> */
    public function approveVerification(int $verificationId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->verificationModel->findByIdForUpdate($verificationId);
            if (!$verification || $verification->status !== 'submitted') {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'تایید معتبر نیست'];
            }

            $profile = $this->profileModel->findProfileForUpdate($verification->profile_id);
            if (!$profile) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'پروفایل یافت نشد'];
            }

            $this->verificationModel->updateStatus($verificationId, 'approved', [
                'approved_at' => date('Y-m-d H:i:s'),
                'admin_id' => $adminId,
            ]);

            $approveData = ['status' => InfluencerModel::STATUS_VERIFIED, 'verified_at' => date('Y-m-d H:i:s'), 'verified_by' => $adminId];
            $this->profileModel->updateProfile($verification->profile_id, $approveData);

            $this->db->commit();
            $this->dispatchProfileUpdated((int)$verification->profile_id, $approveData);

            $this->logger->info('verification.approved', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'verification_id' => $verificationId
            ]);

            return ['ok' => true, 'message' => 'تایید پذیرفته شد'];
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('verification.approval.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تایید'];
        }
    }

    /**
     * Admin rejects verification
     */
    /** @return array<string, mixed> */
    public function rejectVerification(int $verificationId, int $adminId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->verificationModel->findByIdForUpdate($verificationId);
            if (!$verification) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'تایید یافت نشد'];
            }

            $profile = $this->profileModel->findProfileForUpdate($verification->profile_id);
            if (!$profile) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'پروفایل یافت نشد'];
            }

            $this->verificationModel->updateStatus($verificationId, 'rejected', [
                'admin_id' => $adminId,
                'rejection_reason' => $reason,
            ]);

            $rejectData = ['status' => InfluencerModel::STATUS_PENDING, 'rejection_reason' => $reason];
            $this->profileModel->updateProfile($verification->profile_id, $rejectData);

            $this->db->commit();
            $this->dispatchProfileUpdated((int)$verification->profile_id, $rejectData);

            $this->logger->info('verification.rejected', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'reason' => $reason
            ]);

            return ['ok' => true, 'message' => 'تایید رد شد'];
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('verification.rejection.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در رد تایید'];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Status & History
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get verification status for profile
     */
    /** @return array<string, mixed> */
    public function getVerificationStatus(int $profileId): array
    {
        $verification = $this->verificationModel->findLatestByProfile($profileId);

        if (!$verification) {
            return [
                'status' => 'not_started',
                'message' => 'تایید هنوز شروع نشده'
            ];
        }

        return [
            'status' => $verification->status,
            'code' => $verification->status === 'pending' ? $verification->code : null,
            'expires_at' => $verification->expires_at,
            'submitted_at' => $verification->submitted_at,
            'approved_at' => $verification->approved_at,
            'rejection_reason' => $verification->rejection_reason,
            'message' => $this->getStatusMessage($verification->status),
        ];
    }

    /**
     * Get human-readable status message
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'pending' => 'منتظر ارسال اثبات',
            'submitted' => 'منتظر تایید مدیر',
            'approved' => 'تایید شده ✓',
            'rejected' => 'رد شده',
            'expired' => 'کد منقضی شده',
            default => 'نامشخص'
        };
    }

    /**
     * Get verification history for profile
     */
    /** @return array<int, array<string, mixed>> */
    public function getVerificationHistory(int $profileId, int $limit = 10): array
    {
        $records = $this->verificationModel->getHistoryByProfile($profileId, $limit);

        return array_map(function ($record) {
            return [
                'id' => $record['id'],
                'status' => $record['status'],
                'created_at' => $record['created_at'],
                'submitted_at' => $record['submitted_at'],
                'approved_at' => $record['approved_at'],
                'rejection_reason' => $record['rejection_reason'],
            ];
        }, $records);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cron: Cleanup expired verifications
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark expired pending verifications as expired
     * Run hourly via cron
     */
    public function cleanupExpiredVerifications(): int
    {
        $count = $this->verificationModel->cleanupExpiredPending();
        if ($count > 0) {
            $this->logger->info('verification.cleanup', ['expired_count' => $count]);
        }

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Validation Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate proof URL (screenshot)
     */
    private function isValidProofUrl(string $url): bool
    {
        // ✅ Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if (empty($host)) {
            return false;
        }

        $appHost = strtolower((string)parse_url($this->urlGenerator->origin(), PHP_URL_HOST));
        $allowedDomains = array_filter([
            'localhost',
            $appHost,
            'instagram.com',
            'www.instagram.com',
            't.me',
            'telegram.me',
            'www.telegram.me',
            'tiktok.com',
            'www.tiktok.com',
            'twitter.com',
            'www.twitter.com',
            'x.com',
            'www.x.com',
            'facebook.com',
            'www.facebook.com',
        ]);

        return in_array($host, $allowedDomains, true);
    }
}

