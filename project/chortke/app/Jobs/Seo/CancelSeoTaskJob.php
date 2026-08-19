<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

use App\Models\SeoExecution;
use App\Services\Seo\AdsSeoService;

class CancelSeoTaskJob
{
    public function __construct(
        private AdsSeoService $adsService,
        private SeoExecution $executionModel
    ) {}

    /** @return array<string, mixed> */
public function handle(int $executionId, int $userId): array
    {
        $execution = $this->executionModel->findByUser($executionId, $userId);
        if (!$execution) {
            return ['success' => false, 'message' => 'اجرای تسک یافت نشد.'];
        }
        if ((string)$execution->status !== 'started') {
            return ['success' => false, 'message' => 'این تسک قابل لغو نیست.'];
        }

        $ok = $this->adsService->cancelExecution($executionId, 'لغو شده توسط کاربر');

        return ['success' => $ok, 'message' => $ok ? 'تسک لغو شد' : 'لغو تسک انجام نشد'];
    }
}
