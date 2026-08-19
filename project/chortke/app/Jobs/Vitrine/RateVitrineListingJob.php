<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Models\VitrineListing;
use App\Models\VitrineRating;
use Core\Database;
use Core\EventDispatcher;

/**
 * RateVitrineListingJob
 *
 * ثبت امتیاز به لیستینگ ویترین توسط خریدار پس از تحویل.
 * این Job امتیاز را در جدول vitrine_ratings ثبت می‌کند
 * و میانگین امتیاز seller را به‌روزرسانی می‌کند.
 */
class RateVitrineListingJob
{
    private Database $db;
    private LoggerInterface $logger;
    private VitrineListing $listing;
    private VitrineRating $rating;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        VitrineListing $listing,
        VitrineRating $rating,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->listing = $listing;
        $this->rating = $rating;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(int $raterId, int $listingId, int $stars, string $comment = ''): array
    {
        // اعتبارسنجی امتیاز
        if ($stars < 1 || $stars > 5) {
            return ['success' => false, 'message' => 'امتیاز باید بین ۱ تا ۵ باشد'];
        }

        // بررسی وجود لیستینگ
        $listing = $this->listing->find($listingId);
        if (!$listing || !isset($listing->id)) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        // بررسی اینکه کاربر ردهنده، خریدار باشد
        if ((int) $listing->seller_id === $raterId) {
            return ['success' => false, 'message' => 'فروشنده نمی‌تواند به آگهی خودش امتیاز دهد'];
        }

        // بررسی اینکه قبلاً امتیاز نداده باشد
        $existingRating = $this->rating->findByUserAndListing($raterId, $listingId);
        if ($existingRating && isset($existingRating->id)) {
            return ['success' => false, 'message' => 'شما قبلاً به این آگهی امتیاز داده‌اید'];
        }

        $this->db->beginTransaction();
        try {
            // ثبت امتیاز.
            // ROOT FIX: the earlier findByUserAndListing() check was a TOCTOU race — two concurrent
            // ratings could both pass it and insert twice. A UNIQUE(user_id, listing_id) constraint
            // (see migration 2026_08_08_0002) is now the real fence; a duplicate surfaces as an
            // integrity violation which we translate into the same "already rated" response.
            try {
                $stmt = $this->db->query(
                    "INSERT INTO vitrine_ratings (user_id, listing_id, seller_id, stars, comment, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$raterId, $listingId, $listing->seller_id, $stars, $comment ?: null]
                );
            } catch (\PDOException $dupCandidate) {
                $isDuplicate = ((string)$dupCandidate->getCode() === '23000')
                    || (isset($dupCandidate->errorInfo[1]) && (int)$dupCandidate->errorInfo[1] === 1062);
                if ($isDuplicate) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollback();
                    }
                    return ['success' => false, 'message' => 'شما قبلاً به این آگهی امتیاز داده‌اید'];
                }
                throw $dupCandidate;
            }

            $ratingId = (int) $this->db->lastInsertId();

            // ROOT FIX: the previous line wrote a *seller* average into a per-listing
            // `vitrine_listings.avg_rating` column that (a) no migration ever creates — so the
            // UPDATE crashed with "Unknown column 'avg_rating'" — and (b) nothing reads, because the
            // rating average is computed live from vitrine_ratings via
            // VitrineRating::getSellerAverageRating(). The broken denormalised write is removed and
            // the live aggregate remains the single source of truth.
            $this->db->commit();

            // ثبت در outbox/event
            if ($this->outbox) {
                $this->outbox->record('vitrine', $listingId, 'vitrine.listing_rated', [
                    'rating_id' => $ratingId,
                    'rater_id' => $raterId,
                    'listing_id' => $listingId,
                    'stars' => $stars,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.listing_rated', [
                    'rating_id' => $ratingId,
                    'rater_id' => $raterId,
                    'listing_id' => $listingId,
                    'stars' => $stars,
                ]);
            }

            $this->logger->info('vitrine.listing_rated', [
                'rating_id' => $ratingId,
                'rater_id' => $raterId,
                'listing_id' => $listingId,
                'stars' => $stars,
            ]);

            return [
                'success' => true,
                'rating_id' => $ratingId,
                'message' => 'امتیاز با موفقیت ثبت شد',
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('vitrine.rating_failed', [
                'listing_id' => $listingId,
                'rater_id' => $raterId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}
