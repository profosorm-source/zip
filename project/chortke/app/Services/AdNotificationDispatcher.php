<?php

namespace App\Services;

use Core\Database;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;
use App\Contracts\CacheInterface;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * AdNotificationDispatcher - Responsible for transmitting ad notification campaigns in the background.
 */
class AdNotificationDispatcher
{


    private \App\Contracts\LoggerInterface $logger;
    private \Core\Cache $cache;
    private \Core\Database $db;
    private NotificationService $notificationService;
    private AdsBudgetSettlementService $adsBudgetSettlementService;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \Core\Cache $cache,
        \Core\Database $db,
        NotificationService $notificationService,
        AdsBudgetSettlementService $adsBudgetSettlementService
    ) {        $this->logger = $logger;
        $this->cache = $cache;
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->adsBudgetSettlementService = $adsBudgetSettlementService;

        
    }

    /**
     * Scans pending active notification campaigns and delivers them in parallel batches.
     *
     * @return array<string, mixed>
     */
    public function processAdNotifications(): array
    {
        $stats = ['ads_processed' => 0, 'total_sent' => 0];
        
        // 🚀 Owner-verified Distributed Lock (Findings #1 & #6)
        $lockKey = 'ad_notification_process';
        if (!$this->cache->lock($lockKey, 300, 0)) {
            $this->logger->info('ad_process_locked', ['message' => 'Another ad process is already running.']);
            return $stats;
        }

        try {
            // 1. Find active notification ads that are approved and not yet completed
            $activeAds = $this->db->fetchAll(
                "SELECT id, title, type, status, remaining_budget, impressions, restrictions, link FROM ads WHERE type = 'notification' AND status = 'active' AND remaining_budget > 0 LIMIT 5"
            ) ?: [];

            if (empty($activeAds)) {
                return $stats;
            }

            $notificationQueue = []; // OUTBOX PATTERN: Store notifications to send after transaction

            foreach ($activeAds as $ad) {
                $restrictions = (array)(json_decode($ad->restrictions ?? '', true) ?? []) ?: [];
                
                $where = ["ud.fcm_token IS NOT NULL", "LENGTH(ud.fcm_token) > 10", "u.status = 'active'"];
                $params = [];
                
                if (!empty($restrictions['age_min'])) {
                    $where[] = "TIMESTAMPDIFF(YEAR, u.birth_date, CURDATE()) >= ?";
                    $params[] = $restrictions['age_min'];
                }
                if (!empty($restrictions['age_max'])) {
                    $where[] = "TIMESTAMPDIFF(YEAR, u.birth_date, CURDATE()) <= ?";
                    $params[] = $restrictions['age_max'];
                }
                if (!empty($restrictions['regions']) && is_array($restrictions['regions'])) {
                    $validRegions = array_values(array_filter($restrictions['regions'], fn($r) => is_string($r) || is_numeric($r)));
                    if (!empty($validRegions)) {
                        $regionPlaceholders = array_fill(0, count($validRegions), '?');
                        $where[] = "u.region IN (" . implode(',', $regionPlaceholders) . ")";
                        $params = array_merge($params, array_map('strval', $validRegions));
                    }
                }
                $allowedGenders = ['male', 'female', 'other'];
                if (!empty($restrictions['gender']) && in_array($restrictions['gender'], $allowedGenders, true)) {
                    $where[] = "u.gender = ?";
                    $params[] = $restrictions['gender'];
                }
                
                // Finding #8 Fix: Keyset pagination based on last_processed_user_id rather than shifting impression offset
                $lastUserId = (int)($restrictions['last_processed_user_id'] ?? 0);
                $where[] = "u.id > ?";
                $params[] = $lastUserId;

                $whereClause = implode(' AND ', $where);
                $limit = 100;
                
                $userQuery = "SELECT DISTINCT u.id FROM users u
                             JOIN user_devices ud ON u.id = ud.user_id
                             WHERE {$whereClause}
                             ORDER BY u.id ASC
                             LIMIT ?";
                
                $params[] = $limit;
                
                $userRows = $this->db->fetchAll($userQuery, $params) ?: [];

                if (empty($userRows)) {
                    $this->adsBudgetSettlementService->refundRemainingBudget((int)$ad->id, 'completed', 'اتمام مخاطبان نوتیفیکیشن تبلیغاتی', 'system');
                    continue;
                }

                $userIds = array_map('intval', array_column($userRows, 'id'));
                $maxUserIdInBatch = max($userIds);

                // Update last_processed_user_id in ad restrictions
                $restrictions['last_processed_user_id'] = $maxUserIdInBatch;
                $this->db->query(
                    "UPDATE ads SET restrictions = ? WHERE id = ?",
                    [json_encode($restrictions, JSON_UNESCAPED_UNICODE), (int)$ad->id]
                );

                $batchIdemKey = 'notif_batch_ad_' . (int)$ad->id . '_' . $maxUserIdInBatch . '_' . md5(implode(',', $userIds));

                $notificationQueue[] = [
                    'ad_id' => (int)$ad->id,
                    'user_ids' => $userIds,
                    'title' => $ad->title,
                    'body' => $restrictions['push_body'] ?? 'برای مشاهده کلیک کنید',
                    'link' => $ad->link ?? '#',
                    'idem_key' => $batchIdemKey,
                ];
                
                $stats['ads_processed']++;
                $this->logger->info('ad_push_queued', ['ad_id' => $ad->id, 'count' => count($userIds)]);
            }

            // Finding #7 Fix: Send notifications with batch idempotency tracking
            foreach ($notificationQueue as $notification) {
                try {
                    $sentCount = $this->notificationService->sendBulk(
                        $notification['user_ids'],
                        'marketing',
                        $notification['title'],
                        $notification['body'],
                        ['ad_id' => $notification['ad_id']],
                        $notification['link']
                    );
                    if ($sentCount > 0) {
                        $this->adsBudgetSettlementService->consumeDeliveryBudget(
                            intval($notification['ad_id']),
                            'notification',
                            'delivery',
                            (int)$sentCount,
                            null,
                            ['source' => 'ad_notification_dispatcher', 'notification_batch' => $notification['idem_key']],
                            $notification['idem_key']
                        );
                        $stats['total_sent'] += $sentCount;
                    }
                    $this->logger->info('ad_push_delivered', ['ad_id' => $notification['ad_id'], 'count' => $sentCount]);
                } catch (\Throwable $notifError) {
                    $this->logger->error('ad_notification_failed', ['ad_id' => $notification['ad_id'], 'error' => $notifError->getMessage()]);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('ad_push_cron_fail', ['error' => $e->getMessage()]);
        } finally {
            $this->cache->unlock($lockKey);
        }

        return $stats;
    }
}
