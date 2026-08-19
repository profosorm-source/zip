<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Enums\ScoreDomain;

/**
 * Score Model - مدل اشتراکی امتیازات
 * 
 * Consolidated from: UserScoreEvent.php, UserScoreAdjustment.php, Score.php
 * 
 * این مدل تمام عملیات امتیازدهی را مدیریت می‌کند:
 * - ثبت رویدادهای امتیازدهی (events)
 * - اعمال تنظیمات امتیاز (adjustments)
 * - محاسبه امتیازات موثر (effective scores)
 * 
 * 🛡️ H-3 Fix: addEvent() همیشه به DB می‌نویسد (مسیر اصلی و قابل اطمینان)
 *              Single write path → فقط DB (Redis buffer حذف شد)
 * 
 * جداول: score_events, user_score_adjustments (legacy), user_score_events (legacy)
 */
class Score extends Model
{
    protected static string $table = 'score_events';

    protected array $fillable = ['entity_type', 'entity_id', 'domain', 'delta', 'source', 'meta', 'created_at'];

    public const DOMAIN_FRAUD = 'fraud';
    public const DOMAIN_TASK = 'task';
    public const DOMAIN_SOCIAL_TRUST = 'social_trust';
    public const DOMAIN_REFERRAL = 'referral';
    public const DOMAIN_ACTIVITY = 'activity';
    public const DOMAIN_LOYALTY = 'loyalty';

    public static function normalizeDomain(string $domain): string
    {
        $normalized = ScoreDomain::normalize($domain);
        if (!ScoreDomain::isValid($normalized)) {
            throw new \InvalidArgumentException("Unsupported score domain: {$domain}");
        }
        return $normalized;
    }

    /** @return list<string> */

    public static function allowedDomains(): array
    {
        return ScoreDomain::values();
    }

    // ==========================================
    // Event Management (from UserScoreEvent)
    // ==========================================

    /** @param array<string, mixed> $meta */

    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        $domain = self::normalizeDomain($domain);

        // 🛡️ H-3 Fix: همیشه مستقیم به DB بنویس (مسیر اصلی)
        $stmt = $this->db->prepare("
            INSERT INTO score_events (entity_type, entity_id, domain, delta, reason, metadata, created_at)
            VALUES ('user', ?, ?, ?, ?, ?, NOW())
        ");

        $ok = $stmt->execute([
            $userId,
            $domain,
            $delta,
            $source,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return $ok;
    }

    /** @return list<\stdClass> */
    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        $limit = \max(1, (int)$limit);
        $domain = $domain !== null ? self::normalizeDomain($domain) : null;
        if ($domain === null) {
            $stmt = $this->db->prepare("
                SELECT id, domain, reason, delta, metadata, created_at FROM score_events
                WHERE entity_type = 'user' AND entity_id = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, domain, reason, delta, metadata, created_at FROM score_events
                WHERE entity_type = 'user' AND entity_id = ? AND domain = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$userId, $domain, $limit]);
        }

        return $this->fetchObjectList($stmt);
    }

    // ==========================================
    // Adjustment Management (from UserScoreAdjustment)
    // ==========================================

    /**
     * دریافت تنظیمات فعال امتیازدهی
     */
    /** @return list<\stdClass> */
    public function getActiveAdjustments(int $userId, string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        $stmt = $this->db->prepare("
            SELECT * FROM user_score_adjustments
            WHERE user_id = ? AND domain = ? AND is_active = 1
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $domain]);

        return $this->fetchObjectList($stmt);
    }

    /**
     * ایجاد تنظیم امتیاز جدید
     */
    /** @param array<string, mixed> $data */
    public function createAdjustment(array $data): bool
    {
        $data['domain'] = self::normalizeDomain(str_value($data['domain'] ?? ''));
        $stmt = $this->db->prepare("
            INSERT INTO user_score_adjustments 
            (user_id, domain, operation, value, reason, expires_at, created_by, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");

        return $stmt->execute([
            $data['user_id'],
            $data['domain'],
            $data['operation'],
            $data['value'],
            $data['reason'],
            $data['expires_at'] ?? null,
            $data['created_by'],
        ]);
    }

    /**
     * دریافت تنظیمات امتیاز کاربر
     */
    /** @return list<\stdClass> */
    public function getAdjustmentsByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_score_adjustments 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 200
        ");
        $stmt->execute([$userId]);

        return $this->fetchObjectList($stmt);
    }

    // ==========================================
    // Unified Score Events (New)
    // ==========================================

    /**
     * ثبت رویداد امتیازدهی unified
     * 
     * 🛡️ H-3 Fix: همیشه مستقیم به DB می‌نویسد (منبع حقیقت = DB)
     *              Redis buffer فقط یک کپی ثانویه غیرضروری است.
     */
    /** @param array<string, mixed> $data */
    public function addEvent(array $data): bool
    {
        $data['domain'] = self::normalizeDomain(str_value($data['domain'] ?? ''));

        // 🛡️ H-3 Fix: DB مسیر اصلی و همیشگی (single source of truth)
        $stmt = $this->db->prepare("
            INSERT INTO score_events (entity_type, entity_id, domain, delta, reason, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $ok = $stmt->execute([
            $data['entity_type'],
            $data['entity_id'],
            $data['domain'],
            $data['delta'],
            $data['source'],
            json_encode($data['meta'] ?? [])
        ]);

        return $ok;
    }

    /**
     * دریافت امتیاز کل unified
     */
    public function getTotal(int $entityId, string $entityType, string $domain): float
    {
        $domain = self::normalizeDomain($domain);
        $stmt = $this->db->prepare("
            SELECT SUM(delta) FROM score_events
            WHERE entity_id = ? AND entity_type = ? AND domain = ?
        ");
        $stmt->execute([$entityId, $entityType, $domain]);
        return (float)$stmt->fetchColumn();
    }

    public function getDomainScore(int $userId, string $domain): float
    {
        $domain = self::normalizeDomain($domain);

        // جدول یکپارچه score_events
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(delta), 0.0) FROM score_events
            WHERE entity_id = ? AND entity_type = 'user' AND domain = ?
        ");
        $stmt->execute([$userId, $domain]);
        return (float)$stmt->fetchColumn();
    }



    /**
     * دریافت آمار هفتگی اجرا برای trust score
     */
    public function getWeeklyExecutionStats(int $userId): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' AND final_score >= 80 THEN 1 ELSE 0 END) as good_tasks,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'soft_approved' THEN 1 ELSE 0 END) as soft_approved,
                AVG(final_score) as avg_score
            FROM task_executions
            WHERE executor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$userId]);

        return $this->fetchObject($stmt);
    }

    /**
     * ذخیره snapshot trust score
     */
    /** @param array<string, mixed> $data */
    public function saveTrustSnapshot(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_trust_snapshots 
            (user_id, trust_score, week_good_tasks, week_rejected, week_soft, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $data['user_id'],
            $data['trust_score'],
            $data['week_good_tasks'],
            $data['week_rejected'],
            $data['week_soft']
        ]);
    }

    // ==========================================
    // Legacy Methods (for backward compatibility)
    // ==========================================

    public function getTaskRawRisk(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(AVG(fraud_score), 0) AS avg_score
            FROM task_executions
            WHERE executor_id = ?
        ");
        $stmt->execute([$userId]);

        return (float)$stmt->fetchColumn();
    }

    /** @return list<\stdClass> */
    public function getRecentEvents(int $userId, int $limit = 50): array
    {
        $limit = \max(1, (int)$limit);
        $stmt = $this->db->prepare("
            SELECT id, domain, reason, delta, metadata, created_at FROM score_events
            WHERE entity_type = 'user' AND entity_id = ?
            UNION ALL
            SELECT id, domain, source, delta, meta_json, created_at FROM user_score_events
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $limit]);

        return $this->fetchObjectList($stmt);
    }

    public function revokeAdjustment(int $adjustmentId, int $adminId, string $reason): bool
    {
        // First get the adjustment details
        $find = $this->db->prepare("
            SELECT id, user_id, domain, operation, value
            FROM user_score_adjustments
            WHERE id = ?
            LIMIT 1
        ");
        $find->execute([$adjustmentId]);
        $adj = $this->fetchAssoc($find);

        if ($adj === []) {
            return false;
        }

        // Deactivate the adjustment
        $stmt = $this->db->prepare("
            UPDATE user_score_adjustments
            SET is_active = 0
            WHERE id = ?
            LIMIT 1
        ");
        $ok = $stmt->execute([$adjustmentId]);

        if ($ok) {
            // 🛡️ H-3 Fix: همیشه مستقیم به DB بنویس (single source of truth)
            $ev = $this->db->prepare("
                INSERT INTO score_events (entity_type, entity_id, domain, delta, reason, metadata, created_at)
                VALUES ('user', ?, ?, ?, ?, ?, NOW())
            ");
            $ev->execute([
                int_value($adj['user_id'] ?? 0),
                str_value($adj['domain'] ?? ''),
                0,
                'admin_adjustment_revoke',
                json_encode([
                    'adjustment_id' => $adjustmentId,
                    'reason' => $reason,
                    'admin_id' => $adminId,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            
            // Invalidate Redis cache so next read fetches from DB
            try {
                if (class_exists('\Core\Cache')) {
                    $redis = cache()->redis();
                    if ($redis) {
                        $cacheKey = 'score:user:' . int_value($adj['user_id'] ?? 0);
                        $redis->del($cacheKey);
                    }
                }
            } catch (\Throwable $e) {
                // Non-critical
            }
        }

        return $ok;
    }

    /**
     * Flush buffered events from Redis into DB.
     * 
     * 🛡️ H-3 Fix: No-op. All writes go directly to DB (single source of truth).
     * The Redis buffer path has been removed to prevent dual-write data loss.
     * Kept as no-op for backward compatibility with existing cron calls.
     */
    public function flushBuffer(int $batchSize = 1000): int
    {
        return 0;
    }

}
