<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InfluencerService;
use App\Contracts\LoggerInterface;

class InfluencerOrderTimeoutJob
{
    public function __construct(
        private InfluencerService $influencerService,
        private LoggerInterface $logger
    ) {}

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        try {
            $cancelled = $this->influencerService->processExpiredPendingAcceptance();
            $autoApproved = $this->influencerService->processExpiredBuyerChecks();
            $this->logger->info('influencer.timeout_job.completed', [
                'cancelled' => $cancelled,
                'auto_approved' => $autoApproved,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('influencer.timeout_job.failed', ['error' => $e->getMessage()]);
        }
    }
}
