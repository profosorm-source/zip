<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ScoreService;
use Core\Cache;

/**
 * UpdateFraudScoreJob — ثبت ناهمگام تغییرات امتیاز فراد در دیتابیس برای مقابله با DoS
 */
class UpdateFraudScoreJob
{
    private ScoreService $scoreService;

    public function __construct(ScoreService $scoreService) {
        $this->scoreService = $scoreService;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data): void
    {
        $userId = int_value($data['user_id'] ?? 0);
        $delta  = float_value($data['delta'] ?? 0);
        $domainValue = $data['domain'] ?? 'fraud';
        $sourceValue = $data['source'] ?? 'unknown';
        if (!is_string($domainValue) || $domainValue === '' || !is_string($sourceValue) || $sourceValue === '') {
            throw new \InvalidArgumentException('Fraud score domain and source must be non-empty strings.');
        }
        $domain = $domainValue;
        $source = $sourceValue;
        $meta   = $data['meta'] ?? [];

        if ($userId <= 0 || $delta == 0.0) {
            return;
        }

        // Physical writes executed inside background context using Unified ScoreService
        $metaData = is_array($meta) ? $meta : [];
        $this->scoreService->applyDelta('user', $userId, $domain, $delta, $source, $metaData);
    }
}
