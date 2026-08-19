<?php

declare(strict_types=1);

namespace App\Services\Interaction;

use App\Enums\InteractionType;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use App\Models\InteractionModel;
use Core\Database;
use Core\EventDispatcher;

/**
 * سرویس مدیریت گزارش تخلفات (Reports)
 * مسئولیت: ثبت گزارش تخلف برای محتوا یا کاربران، و اخطار/مسدودسازی در صورت نیاز
 */
class ReportService
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private InteractionModel $interactionModel;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        ?InteractionModel $interactionModel = null,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->interactionModel = $interactionModel ?? new InteractionModel($db);
        $this->outbox = $outbox;
    }

    /**
     * ثبت ریپورت برای یک موجودیت
     */
    public function submit(int $reporterId, string $interactableType, int $interactableId, ModuleContext $context, string $reason, ?string $description = null): bool
    {
        try {
            $this->db->beginTransaction();

            $meta = json_encode([
                'reason' => $reason,
                'description' => $description,
                'status' => 'pending'
            ], JSON_UNESCAPED_UNICODE);
            $meta = is_string($meta) ? $meta : '{}';

            $reportId = $this->interactionModel->createReport(
                $reporterId,
                $interactableType,
                $interactableId,
                InteractionType::REPORT->value,
                $context->value,
                $meta
            );

            $this->db->commit();

            // در صورتی که تعداد ریپورت‌های یک محتوا از حدی گذشت، ایونت شلیک شود
            if ($this->getReportCount($interactableType, $interactableId) >= 5) {
                $this->outbox?->record('report', $interactableId, 'report.threshold_reached', [
                    'entity_type' => $interactableType,
                    'entity_id' => $interactableId,
                    'context' => $context->value,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('report_service.submit_failed', [
                'reporter_id' => $reporterId,
                'entity' => $interactableType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تعداد ریپورت‌های یک موجودیت
     */
    public function getReportCount(string $interactableType, int $interactableId): int
    {
        return $this->interactionModel->countReports($interactableType, $interactableId, InteractionType::REPORT->value);
    }
}
