<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * VitrineRating — مدل امتیازهای لیستینگ ویترین
 *
 * امتیازهای ۱ تا ۵ ستاره که خریداران پس از تحویل
 * به لیستینگ‌ها می‌دهند.
 */
class VitrineRating extends Model
{
    protected static string $table = 'vitrine_ratings';

    public int $id;
    public int $user_id;
    public int $listing_id;
    public int $seller_id;
    public int $stars;
    public ?string $comment;
    public string $created_at;

    /**
     * یافتن امتیاز کاربر به یک لیستینگ
     */
    public function findByUserAndListing(int $userId, int $listingId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM `" . static::$table . "` WHERE user_id = ? AND listing_id = ? LIMIT 1",
            [$userId, $listingId]
        );
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ? (object) $row : null;
    }

    /**
     * دریافت میانگین امتیاز یک فروشنده
     */
    public function getSellerAverageRating(int $sellerId): float
    {
        $stmt = $this->db->query(
            "SELECT AVG(stars) as avg_rating FROM `" . static::$table . "` WHERE seller_id = ?",
            [$sellerId]
        );
        $row = $this->fetchAssoc($stmt);
        return round(float_value($row['avg_rating'] ?? 0), 2);
    }

    /**
     * دریافت تعداد کل امتیازهای یک فروشنده
     */
    public function getSellerRatingCount(int $sellerId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as cnt FROM `" . static::$table . "` WHERE seller_id = ?",
            [$sellerId]
        );
        $row = $this->fetchAssoc($stmt);
        return int_value($row['cnt'] ?? 0);
    }

    /**
     * دریافت امتیازهای یک لیستینگ
     */
    /** @return list<array<string, mixed>> */
    public function getForListing(int $listingId, int $limit = 20): array
    {
        $stmt = $this->db->query(
            "SELECT r.*, u.full_name as user_name 
             FROM `" . static::$table . "` r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.listing_id = ?
             ORDER BY r.created_at DESC
             LIMIT ?",
            [$listingId, $limit]
        );
        return $this->fetchAssocList($stmt);
    }

    /**
     * ثبت امتیاز جدید
     */
    public function createRating(int $userId, int $listingId, int $sellerId, int $stars, ?string $comment = null): ?int
    {
        $stmt = $this->db->query(
            "INSERT INTO `" . static::$table . "` (user_id, listing_id, seller_id, stars, comment, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$userId, $listingId, $sellerId, $stars, $comment]
        );
        return (int) $this->db->lastInsertId();
    }
}
