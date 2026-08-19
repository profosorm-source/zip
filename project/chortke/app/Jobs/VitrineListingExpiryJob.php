<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Database;
use Core\EventDispatcher;

class VitrineListingExpiryJob
{
    private Database $db;
    private WalletServiceInterface $walletService;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private ?\App\Services\OutboxService $outbox;
    private Cache $cache;

    public function __construct(
        Database $db,
        WalletServiceInterface $walletService,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        Cache $cache,
        ?\App\Services\OutboxService $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->outbox = $outbox;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        $this->expireListings();
        $this->releaseHoldsForExpired();
    }

    private function expireListings(): void
    {
        try {
            $expiredListings = $this->db->fetchAll(
                "SELECT id, user_id FROM vitrine_listings WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW() LIMIT 500"
            );
            if (empty($expiredListings)) return;

            $ids = array_map(fn($l) => (int)$l->id, $expiredListings);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $affected = (int) $this->db->execute("UPDATE vitrine_listings SET status = 'expired', updated_at = NOW() WHERE id IN ($ph)", $ids);
            if ($affected > 0) {
                $this->logger->info('vitrine.listings_expired', ['count' => $affected]);
                foreach ($expiredListings as $listing) {
                    try {
                        if ($this->outbox) {
                            $this->outbox->record('vitrine', (int)$listing->id, 'vitrine.listing_expired', ['id' => (int)$listing->id, 'user_id' => (int)$listing->user_id]);
                        }
                    } catch (\Throwable $e) {
            $this->logger->warning('vitrinelistingexpiryjob.operation_failed', ['error' => $e->getMessage()]);
        }
                }
                try {
                    $this->cache->forget('vitrine:active_listings');
                    $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'vitrine']);
                } catch (\Throwable $e) {
            $this->logger->warning('vitrinelistingexpiryjob.operation_failed', ['error' => $e->getMessage()]);
        }
            }
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.expire_listings_failed', ['error' => $e->getMessage()]);
        }
    }

    private function releaseHoldsForExpired(): void
    {
        try {
            // اصلاح کلیدی معماری همزمانی در آزادسازی اعتبارهای منقضی‌شده ویترین (Vitrine Expired Hold Concurrency Guard):
            // استفاده از قفل‌گذاری اتمیک FOR UPDATE جهت جلوگیری از اجرای موازی آزادسازی توسط ورکرهای کرون و شارژ مضاعف کیف پول آگهی‌دهنده
            $listings = $this->db->fetchAll("SELECT vl.id, vl.user_id, vl.hold_amount, vl.currency FROM vitrine_listings vl WHERE vl.status = 'expired' AND vl.hold_released = 0 AND vl.hold_amount > 0 LIMIT 200 FOR UPDATE");
            $released = 0;
            foreach ($listings as $listing) {
                try {
                    $this->db->beginTransaction();
                    $this->walletService->deposit((int)$listing->user_id, (string)$listing->hold_amount, (string)($listing->currency ?: 'irt'), ['type' => 'vitrine_hold_release', 'listing_id' => $listing->id, 'description' => 'آزادسازی Hold آگهی ویترین منقضی‌شده']);
                    $this->db->prepare("UPDATE vitrine_listings SET hold_released = 1, hold_released_at = NOW() WHERE id = ?")->execute([$listing->id]);
                    $this->db->commit();
                    $released++;
                    try { $this->outbox?->record('vitrine', (int)$listing->id, 'vitrine.hold_released', ['listing_id' => (int)$listing->id, 'user_id' => (int)$listing->user_id, 'amount' => (is_numeric($listing->hold_amount ?? null) ? (string)$listing->hold_amount : '0')]); } catch (\Throwable $e) {
            $this->logger->warning('vitrinelistingexpiryjob.operation_failed', ['error' => $e->getMessage()]);
        }
                } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollback(); $this->logger->error('vitrine.hold_release_failed', ['listing_id' => $listing->id, 'error' => $e->getMessage()]); }
            }
            if ($released > 0) $this->logger->info('vitrine.holds_released', ['count' => $released]);
        } catch (\Throwable $e) { $this->logger->error('vitrine.release_holds_job_failed', ['error' => $e->getMessage()]); }
    }
}
