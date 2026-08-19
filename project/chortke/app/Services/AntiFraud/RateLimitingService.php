<?php

namespace App\Services\AntiFraud;

use App\Policies\RateLimitPolicy;

use App\Contracts\LoggerInterface;
/**
 * RateLimitingService (Deprecated Wrapper)
 * 
 * این کلاس برای حفظ سازگاری نگهداری شده است و عملیات را به
 * RateLimitPolicy اصلی که با Redis کار می‌کند محول می‌کند
 * تا از سربار روی دیتابیس جلوگیری شود.
 */
class RateLimitingService
{
    private RateLimitPolicy $policy;

    public function __construct(
        RateLimitPolicy $policy
    ) {
                $this->policy = $policy;
    }

    /** @return array<string, mixed> */
    public function checkTokenBucket(string $key, string $action, ?int $cost = 1): array
    {
        $allowed = $this->policy->check($action, $key);
        return [
            'allowed' => $allowed,
            'remaining' => $allowed ? 1 : 0,
            'capacity' => 10,
            'reset_at' => time() + 60,
            'retry_after' => $allowed ? 0 : 60
        ];
    }

    /** @return array<string, mixed> */
    public function checkSlidingWindow(string $key, string $action, ?int $increment = 1): array
    {
        $allowed = $this->policy->check($action, $key);
        return [
            'allowed' => $allowed,
            'remaining' => $allowed ? 1 : 0,
            'limit' => 10,
            'reset_at' => time() + 60,
            'window' => 60
        ];
    }

    /**
     * @param array<string, mixed> $limits
     * @return array<string, mixed>
     */
    public function checkComposite(array $limits): array
    {
        $overallAllowed = true;
        foreach ($limits as $limitValue) {
            if (!is_array($limitValue)) throw new \InvalidArgumentException('Composite rate limits must be arrays.');
            $action = $limitValue['action'] ?? null;
            $key = $limitValue['key'] ?? null;
            if (!is_string($action) || (!is_string($key) && !is_int($key))) {
                throw new \InvalidArgumentException('Composite rate limit requires string action and scalar key.');
            }
            if (!$this->policy->check($action, $key)) {
                $overallAllowed = false;
            }
        }
        
        return [
            'allowed' => $overallAllowed,
            'details' => [],
            'reset_at' => time() + 60
        ];
    }

    public function recordRateLimitExceeded(string $key): void
    {
        // Simply log the event as a security signal
        // In a real system, this could increase a fraud score
    }
}

