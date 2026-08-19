<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

use App\Models\Investment;

class ApplyWeeklyProfitLossJob
{
    private \App\Models\TradingRecord $tradingModel;
    private \App\Models\Investment $investmentModel;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\Queue $queue;
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    public function __construct(
        \App\Models\TradingRecord $tradingModel,
        \App\Models\Investment $investmentModel,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Contracts\LoggerInterface $logger,
        \Core\Queue $queue,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->tradingModel = $tradingModel;
        $this->investmentModel = $investmentModel;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->outbox = $outbox;
}

    /** @return array<string, mixed> */
    public function handle(int $adminId, int $tradingRecordId, string $profitLossPercent, string $period): array
    {
        $trade = $this->tradingModel->find($tradingRecordId);
        if (!$trade) {
            return ['success' => false, 'message' => 'رکورد ترید یافت نشد.'];
        }

        $activeInvestments = $this->investmentModel->getAll(['status' => Investment::STATUS_ACTIVE], 10000, 0);

        if (empty($activeInvestments)) {
            return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی یافت نشد.'];
        }

        // استخراج شناسه‌های سرمایه‌گذاری فعال
        $investmentIds = [];
        foreach ($activeInvestments as $inv) {
            $investmentIds[] = (int)$inv->id;
        }

        // شکستن شناسه‌ها به بچ‌های ۱۰۰ تایی
        $configuredBatchSize = $this->appSettings->get('investment_batch_size', 100);
        $batchSize = max(10, min(500, is_scalar($configuredBatchSize) && is_numeric((string)$configuredBatchSize) ? (int)$configuredBatchSize : 100));
        $chunks = array_chunk($investmentIds, $batchSize);

        $queuedJobs = 0;

        foreach ($chunks as $chunk) {
            $jobPayload = [
                'investment_ids'      => $chunk,
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
                'admin_id'            => $adminId,
            ];

            if ($this->outbox) {
                $this->outbox->record('investment', 0, 'investment.weekly_profit_batch', [
                    'job' => \App\Jobs\ApplyWeeklyProfitLossJob::class,
                    'notification' => $jobPayload,
                ]);
            } else {
                $this->queue->push(\App\Jobs\ApplyWeeklyProfitLossJob::class, $jobPayload);
            }
            $queuedJobs++;
        }

        $this->logger->info('investment_weekly_apply_queued', [
            'message' => "Admin {$adminId} queued {$profitLossPercent}% profit/loss for {$period} in {$queuedJobs} batch jobs, affecting " . count($investmentIds) . " investments."
        ]);

        return [
            'success' => true,
            'message' => "عملیات اعمال سود/ضرر هفتگی به صورت پس‌زمینه برای " . count($investmentIds) . " سرمایه‌گذاری در قالب {$queuedJobs} تسک صف‌بندی شد."
        ];
    }
}
