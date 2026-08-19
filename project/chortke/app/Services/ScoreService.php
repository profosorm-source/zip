<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Score\ScoreCommandService;
use App\Services\Score\ScoreQueryService;

/**
 * Unified Score Service (Facade)
 * 
 * This service acts as a backward-compatible Facade for the new CQRS-based Score System.
 * It delegates write operations to ScoreCommandService and read operations to ScoreQueryService.
 */
class ScoreService
{


    private ScoreCommandService $commandService;
    private ScoreQueryService $queryService;
    private \Core\Database $db;

    public function __construct(
        ScoreCommandService $commandService,
        ScoreQueryService $queryService,
        \Core\Database $db
    ) {
        $this->commandService = $commandService;
        $this->queryService = $queryService;
        $this->db = $db;
    }

    /**
     * Delegates delta application to the Command service (Write Model)
     * 
     * 🛡️ M-2 Fix: اضافه شدن idempotencyKey برای پشتیبانی از Idempotency داخل CommandService
     */
    /** @param array<string, mixed> $meta */
    public function applyDelta(string $entityType, int $entityId, string $domain, float $delta, string $source, array $meta = [], ?string $idempotencyKey = null): bool
    {
        return $this->commandService->applyDelta($entityType, $entityId, $domain, $delta, $source, $meta, $idempotencyKey);
    }

    /**
     * Delegates score reading to the Query service (Read Model)
     */
    public function getScore(string $entityType, int $entityId, string $domain): float
    {
        return $this->queryService->getScore($entityType, $entityId, $domain);
    }

    // =====================================================================
    // Score Management API — مورد نیاز توسط ScoreManagementController
    // مدل: effective = raw + Σ(active adjustments)
    // =====================================================================

    /**
     * بارگذاری کاربر به‌همراه امتیاز fraud خام برای صفحه مدیریت امتیاز.
     */
    public function getUserForScoreManagement(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT id, username, email, full_name, fraud_score, role
             FROM users WHERE id = ?",
            [$userId]
        );
    }

    /**
     * امتیاز risk خام دامنه‌ی task از projection.
     */
    public function getTaskRawRisk(int $userId): float
    {
        return (float) $this->db->fetchColumn(
            "SELECT score FROM user_scores WHERE user_id = ? AND domain = 'task'",
            [$userId]
        );
    }

    /**
     * امتیاز مؤثر = raw + مجموع اصلاح‌های فعال (active و منقضی‌نشده).
     */
    public function getEffectiveScore(int $userId, string $domain, float $raw): float
    {
        $sum = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(score_adjustment), 0)
             FROM user_score_adjustments
             WHERE user_id = ? AND domain = ? AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())",
            [$userId, $domain]
        );
        return $raw + $sum;
    }

    /**
     * لیست اصلاح‌های فعال یک کاربر برای یک دامنه.
     */
    /** @return list<\stdClass> */
    public function getActiveAdjustments(int $userId, string $domain): array
    {
        return $this->db->fetchAll(
            "SELECT id, domain, score_adjustment, operation, reason, created_by,
                    created_at, expires_at
             FROM user_score_adjustments
             WHERE user_id = ? AND domain = ? AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC",
            [$userId, $domain]
        );
    }

    /**
     * رویدادهای اخیر امتیاز کاربر.
     */
    /** @return list<\stdClass> */
    public function getRecentScoreEvents(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(500, (int)$limit));
        return $this->db->fetchAll(
            "SELECT id, domain, source, delta, meta_json, created_at
             FROM user_score_events
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$userId]
        );
    }

    /**
     * ثبت اصلاح امتیاز دستی توسط ادمین + ثبت رویداد ledger.
     * operation: add|subtract|set
     * @return array<string, mixed>
     */
    public function createAdjustment(
        int $userId,
        string $domain,
        string $operation,
        float $value,
        string $reason,
        ?string $expiresAt = null,
        ?int $adminId = null
    ): array {
        if (!in_array($domain, ['fraud', 'task'], true)) {
            return ['success' => false, 'message' => 'دامنه امتیاز نامعتبر است.'];
        }

        // محاسبه‌ی دلتای امضا‌دار
        switch ($operation) {
            case 'add':
                $delta = abs($value);
                break;
            case 'subtract':
                $delta = -abs($value);
                break;
            case 'set':
                // مؤثر باید برابر value شود → دلتا = value - raw فعلی
                $raw = ($domain === 'fraud')
                    ? (float)($this->getUserForScoreManagement($userId)->fraud_score ?? 0)
                    : $this->getTaskRawRisk($userId);
                $delta = $value - $raw;
                break;
            default:
                return ['success' => false, 'message' => 'عملیات نامعتبر است.'];
        }

        $ok = $this->db->execute(
            "INSERT INTO user_score_adjustments
                (user_id, score_adjustment, operation, reason, domain, created_by, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)",
            [$userId, $delta, $operation, $reason, $domain, $adminId, ($expiresAt !== '' ? $expiresAt : null)]
        );

        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت اصلاح امتیاز.'];
        }

        // ثبت رویداد برای ledger
        $this->db->execute(
            "INSERT INTO user_score_events (user_id, domain, source, delta, meta_json)
             VALUES (?, ?, 'admin_adjustment', ?, ?)",
            [
                $userId,
                $domain,
                $delta,
                json_encode(
                    ['operation' => $operation, 'reason' => $reason, 'admin_id' => $adminId],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['success' => true, 'message' => 'اصلاح امتیاز ثبت شد.'];
    }

    /**
     * ابطال (غیرفعال‌کردن) یک اصلاح امتیاز.
     */
    public function revokeScoreAdjustment(int $adjustmentId, ?int $adminId = null, string $reason = ''): bool
    {
        $count = (int) $this->db->execute(
            "UPDATE user_score_adjustments
             SET status = 'revoked', revoked_by = ?, revoked_at = NOW(), revoked_reason = ?
             WHERE id = ? AND status = 'active'",
            [$adminId, ($reason !== '' ? $reason : null), $adjustmentId]
        );
        return $count > 0;
    }
}
