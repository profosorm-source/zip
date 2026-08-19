<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

use App\Models\SeoExecution;
use App\Services\Seo\AdsSeoService;
use Core\EventDispatcher;

class CompleteSeoTaskJob
{
    public function __construct(
        private SeoExecution $executionModel,
        private ProcessSeoTaskAsyncJob $processAsyncJob,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {}

/**
 * @param array<string, mixed> $engagementData
 * @return array<string, mixed>
 */
public function handle(int $executionId, int $userId, array $engagementData): array
    {
        try {
            $execution = $this->executionModel->findByUser($executionId, $userId);
            if (!$execution) {
                return ['success' => false, 'message' => 'اجرای تسک یافت نشد.'];
            }

            if ((string)$execution->status !== 'started') {
                return ['success' => false, 'message' => 'این تسک در حال پردازش یا قبلاً تکمیل شده است.'];
            }

            $result = $this->processAsyncJob->handle($executionId, $userId, (int)$execution->ad_id, $engagementData);

            if ($result['success'] ?? false) {
                $this->outbox?->record('seo', $executionId, 'seo.task.completed', [
                    'execution_id' => $executionId,
                    'user_id'      => $userId,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در تکمیل تسک SEO: ' . $e->getMessage()];
        }
    }
}
