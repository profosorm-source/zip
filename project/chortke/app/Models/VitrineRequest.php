<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * VitrineRequest — مدل درخواست‌های خرید/فروش
 *
 * وقتی خریدار روی یک آگهی «درخواست» می‌دهد یا قیمت پیشنهادی می‌گذارد
 */
class VitrineRequest extends Model
{
    protected static string $table = 'vitrine_requests';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public function findById(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare(
            "SELECT vr.*,
                    r.full_name AS requester_name, r.kyc_status AS requester_kyc, r.tier AS requester_tier,
                    vl.title AS listing_title, vl.seller_id, vl.price_usdt AS listing_price,
                    vl.category, vl.platform
             FROM vitrine_requests vr
             LEFT JOIN users r      ON r.id  = vr.requester_id
             LEFT JOIN vitrine_listings vl ON vl.id = vr.listing_id
             WHERE vr.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $this->fetchObject($stmt);
    }

    /** @param array<string, mixed> $d */
    public function createRequest(array $d): ?\stdClass
    {
        // L-گاپ Fix (TOCTOU): درج اتمیک با شرط عدم وجود درخواست pending تکراری؛
        // MySQL از unique جزئی روی status='pending' پشتیبانی نمی‌کند، پس شرط داخل خودِ INSERT اعمال می‌شود.
        $stmt = $this->db->prepare(
            "INSERT INTO vitrine_requests
                (listing_id, requester_id, user_id, offer_price, message, status, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, 'pending', NOW(), NOW()
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM vitrine_requests
                 WHERE listing_id = ? AND requester_id = ? AND status = 'pending'
             )"
        );
        $ok = $stmt->execute([
            $d['listing_id'],
            $d['requester_id'],
            $d['requester_id'],
            $d['offer_price'] ?? null,
            $d['message']     ?? null,
            $d['listing_id'],
            $d['requester_id'],
        ]);
        if (!$ok || $stmt->rowCount() < 1) {
            // درج نشد یا درخواست pending تکراری (شرط رقابتی) وجود دارد.
            return null;
        }
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE vitrine_requests SET status = ?, responded_at = NOW(), updated_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$status, $id]);
    }

    /** درخواست‌های pending یک آگهی */
    /** @return list<\stdClass> */
    public function getPendingByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT vr.*, u.full_name AS requester_name, u.kyc_status, u.tier
             FROM vitrine_requests vr
             LEFT JOIN users u ON u.id = vr.requester_id
             WHERE vr.listing_id = ? AND vr.status = 'pending'
             ORDER BY vr.created_at DESC"
        );
        $stmt->execute([$listingId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** همه درخواست‌های یک آگهی */
    /** @return list<\stdClass> */
    public function getAllByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT vr.*, u.full_name AS requester_name, u.kyc_status, u.tier
             FROM vitrine_requests vr
             LEFT JOIN users u ON u.id = vr.requester_id
             WHERE vr.listing_id = ?
             ORDER BY vr.created_at DESC"
        );
        $stmt->execute([$listingId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** درخواست‌های یک کاربر */
    /** @return list<\stdClass> */
    public function getByRequester(int $userId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT vr.*, vl.title AS listing_title, vl.category, vl.platform, vl.status AS listing_status
             FROM vitrine_requests vr
             LEFT JOIN vitrine_listings vl ON vl.id = vr.listing_id
             WHERE vr.requester_id = ?
             ORDER BY vr.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** آیا این کاربر قبلاً برای این آگهی درخواست داده؟ */
    public function existsPending(int $listingId, int $requesterId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM vitrine_requests
             WHERE listing_id = ? AND requester_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$listingId, $requesterId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * رد تمام درخواست‌های pending دیگر برای یک آگهی
     * وقتی فروشنده یک درخواست را می‌پذیرد، بقیه رد می‌شوند.
     */
    public function rejectOtherPending(int $acceptedRequestId, int $listingId): void
    {
        $this->db->execute(
            "UPDATE vitrine_requests SET status = ?, updated_at = NOW()
             WHERE listing_id = ? AND id != ? AND status = 'pending'",
            [self::STATUS_REJECTED, $listingId, $acceptedRequestId]
        );
    }
}
