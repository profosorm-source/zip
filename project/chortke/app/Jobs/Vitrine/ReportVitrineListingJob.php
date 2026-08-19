<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Models\VitrineListing;
use Core\Database;
use Core\EventDispatcher;

/**
 * ReportVitrineListingJob
 *
 * ثبت گزارش تخلف برای لیستینگ ویترین.
 * کاربران می‌توانند لیستینگ‌های متخلف را گزارش دهند
 * و این گزارش به پنل مدیران ارسال می‌شود.
 */
class ReportVitrineListingJob
{
    private Database $db;
    private LoggerInterface $logger;
    private VitrineListing $listing;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        VitrineListing $listing,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->listing = $listing;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(int $reporterId, int $listingId, string $reason, string $description = ''): array
    {
        // اعتبارسنجی دلیل
        $validReasons = [
            'spam',
            'fraud',
            'inappropriate_content',
            'copyright_violation',
            'fake_listing',
            'price_manipulation',
            'other'
        ];

        if (!in_array($reason, $validReasons, true)) {
            return ['success' => false, 'message' => 'دلیل گزارش نامعتبر است'];
        }

        // بررسی وجود لیستینگ
        $listing = $this->listing->find($listingId);
        if (!$listing || !isset($listing->id)) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        // بررسی اینکه گزارش‌دهنده فروشنده نباشد
        if ((int) $listing->seller_id === $reporterId) {
            return ['success' => false, 'message' => 'شما نمی‌توانید آگهی خودتان را گزارش کنید'];
        }

        // بررسی اینکه قبلاً گزارش نداده باشد
        $stmt = $this->db->query(
            "SELECT id FROM vitrine_reports 
             WHERE reporter_id = ? AND listing_id = ? AND status = 'pending'
             LIMIT 1",
            [$reporterId, $listingId]
        );

        $existingReportRow = $stmt->fetch(\PDO::FETCH_OBJ);
        $existingReport = $existingReportRow instanceof \stdClass ? $existingReportRow : null;
        if ($existingReport !== null && isset($existingReport->id)) {
            return ['success' => false, 'message' => 'شما قبلاً این آگهی را گزارش کرده‌اید و در انتظار بررسی است'];
        }

        $this->db->beginTransaction();
        try {
            // L-گاپ Fix (TOCTOU): درج اتمیک با شرط عدم وجود گزارش pending تکراری.
            // چون MySQL از unique جزئی روی status='pending' پشتیبانی نمی‌کند، شرط داخل خودِ INSERT اعمال می‌شود.
            $stmt = $this->db->query(
                "INSERT INTO vitrine_reports (reporter_id, listing_id, seller_id, reason, description, status, created_at)
                 SELECT ?, ?, ?, ?, ?, 'pending', NOW()
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM vitrine_reports
                     WHERE reporter_id = ? AND listing_id = ? AND status = 'pending'
                 )",
                [$reporterId, $listingId, $listing->seller_id, $reason, $description ?: null, $reporterId, $listingId]
            );

            if ($stmt->rowCount() < 1) {
                // شرط رقابتی: گزارش pending تکراری هم‌زمان ثبت شده است.
                $this->db->rollback();
                return ['success' => false, 'message' => 'شما قبلاً این آگهی را گزارش کرده‌اید و در انتظار بررسی است'];
            }

            $reportId = (int) $this->db->lastInsertId();

            $this->db->commit();

            // ثبت در outbox/event
            if ($this->outbox) {
                $this->outbox->record('vitrine', $listingId, 'vitrine.listing_reported', [
                    'report_id' => $reportId,
                    'reporter_id' => $reporterId,
                    'listing_id' => $listingId,
                    'reason' => $reason,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.listing_reported', [
                    'report_id' => $reportId,
                    'reporter_id' => $reporterId,
                    'listing_id' => $listingId,
                    'reason' => $reason,
                ]);
            }

            $this->logger->info('vitrine.listing_reported', [
                'report_id' => $reportId,
                'reporter_id' => $reporterId,
                'listing_id' => $listingId,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'report_id' => $reportId,
                'message' => 'گزارش شما با موفقیت ثبت شد و در اسرع وقت بررسی خواهد شد',
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('vitrine.report_failed', [
                'listing_id' => $listingId,
                'reporter_id' => $reporterId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}
