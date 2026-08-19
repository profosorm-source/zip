<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Jobs\Seo\StartSeoTaskJob;
use App\Jobs\Seo\CompleteSeoTaskJob;
use App\Jobs\Seo\ProcessSeoTaskAsyncJob;
use App\Jobs\Seo\CancelSeoTaskJob;
use App\Jobs\Seo\ReportSeoTaskJob;
use App\Jobs\Seo\RateSeoTaskJob;

class SeoService
{
    private StartSeoTaskJob $startJob;
    private CompleteSeoTaskJob $completeJob;
    private ProcessSeoTaskAsyncJob $processAsyncJob;
    private CancelSeoTaskJob $cancelJob;
    private ReportSeoTaskJob $reportJob;
    private RateSeoTaskJob $rateJob;

    public function __construct(
        StartSeoTaskJob $startJob,
        CompleteSeoTaskJob $completeJob,
        ProcessSeoTaskAsyncJob $processAsyncJob,
        CancelSeoTaskJob $cancelJob,
        ReportSeoTaskJob $reportJob,
        RateSeoTaskJob $rateJob
    ) {
        $this->startJob = $startJob;
        $this->completeJob = $completeJob;
        $this->processAsyncJob = $processAsyncJob;
        $this->cancelJob = $cancelJob;
        $this->reportJob = $reportJob;
        $this->rateJob = $rateJob;
    }

    /** @return array<string, mixed> */
    public function startTask(int $adId, int $userId): array
    {
        return $this->startJob->handle($adId, $userId);
    }

    /**
     * @param array<string, mixed> $engagementData
     * @return array<string, mixed>
     */
    public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        return $this->completeJob->handle($executionId, $userId, $this->validateEngagementData($engagementData));
    }

    /**
     * @param array<string, mixed> $engagementData
     * @return array<string, mixed>
     */
    public function processTaskAsync(int $executionId, int $userId, int $adId, array $engagementData): array
    {
        return $this->processAsyncJob->handle($executionId, $userId, $adId, $this->validateEngagementData($engagementData));
    }

    /** @return array<string, mixed> */
    public function cancelTask(int $executionId, int $userId): array
    {
        return $this->cancelJob->handle($executionId, $userId);
    }

    /** @return array<string, mixed> */
    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        return $this->reportJob->handle($reporterId, $adId, $reason, $description);
    }

    /** @return array<string, mixed> */
    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        return $this->rateJob->handle($raterId, $adId, $stars, $comment);
    }

    /**
     * Canonical boundary for untrusted SEO engagement payloads.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateEngagementData(array $data): array
    {
        foreach (['duration', 'scroll_depth', 'interactions'] as $field) {
            if (!array_key_exists($field, $data) || !is_numeric($data[$field])) {
                throw new \InvalidArgumentException("SEO engagement {$field} must be numeric.");
            }
        }
        $duration = int_value($data['duration']);
        $scrollDepth = float_value($data['scroll_depth']);
        $interactions = int_value($data['interactions']);
        if ($duration < 0 || $duration > 3600 || $scrollDepth < 0 || $scrollDepth > 100 || $interactions < 0) {
            throw new \InvalidArgumentException('SEO engagement metrics are outside allowed ranges.');
        }
        $behavior = $data['behavior'] ?? [];
        if (!is_array($behavior)) throw new \InvalidArgumentException('SEO behavior must be an array.');
        $interactionTypes = $data['interaction_types'] ?? [];
        if (!is_array($interactionTypes) || array_filter($interactionTypes, 'is_string') !== $interactionTypes) {
            throw new \InvalidArgumentException('SEO interaction_types must be a list of strings.');
        }
        $clientMode = $data['client_mode'] ?? 'web';
        if (!is_string($clientMode) || !in_array($clientMode, ['web', 'mobile_web', 'mobile_app'], true)) {
            throw new \InvalidArgumentException('SEO client_mode is invalid.');
        }
        $data['duration'] = $duration;
        $data['scroll_depth'] = $scrollDepth;
        $data['interactions'] = $interactions;
        $data['interaction_types'] = array_values($interactionTypes);
        $data['client_mode'] = $clientMode;
        $data['behavior'] = $behavior;
        return $data;
    }

}
