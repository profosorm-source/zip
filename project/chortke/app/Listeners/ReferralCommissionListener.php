<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use App\Services\Shared\ReferralService;
use Core\Container;

/**
 * ReferralCommissionListener
 * 
 * Handles referral commission processing for various reward events
 * (tasks, sales, vip purchases, etc.)
 */
class ReferralCommissionListener
{
    private ReferralService $referralService;
    private LoggerInterface $logger;

    public function __construct(
        ReferralService $referralService,
        LoggerInterface $logger
    ) {
        $this->referralService = $referralService;
        $this->logger = $logger;
    }

    /**
     * Handle referral commission event
     * 
     * Expected payload format:
     * - referrer_id: int (ID of the referrer to credit)
     * - amount: float|string (Commission amount)
     * - currency: string (Currency code: 'usdt', 'content_approval', etc.)
     * - context: array (Additional data like action, executor_id, etc.)
     * - source_user_id: int (The user whose action triggered the commission)
     */
    public function handle(mixed $event): void
    {
        try {
            // FIX: Listener may run from HTTP request OR CLI/Job context.
            $correlationId = null;
            if (PHP_SAPI !== 'cli' && function_exists('app')) {
                try { $correlationId = app()->request->header('x-request-id'); } catch (\Throwable $e) {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ReferralCommissionListener.handle']);
            $this->logger->warning('referralcommissionlistener.operation_failed', ['error' => $e->getMessage()]);
        }
            }
            $payload = $this->extractPayload($event);
            $correlationId = $correlationId ?? ($payload['correlation_id'] ?? 'cli-' . bin2hex(random_bytes(4)));
            
            if (empty($payload['referrer_id'])) {
                $this->logger->warning('referral.commission.missing_referrer', [
                    'payload' => $payload,
                    'correlation_id' => $correlationId
                ]);
                return;
            }

            // Add correlation to context for tracing
            $rawContext = $payload['context'] ?? [];
            /** @var array<string, mixed> $context */
            $context = is_array($rawContext) ? $rawContext : [];
            $context['correlation_id'] = $correlationId;

            $result = $this->referralService->processCommission(
                int_value($payload['referrer_id'] ?? 0),
                str_value($payload['amount'] ?? ''),
                str_value($payload['currency'] ?? ''),
                $context
            );

            if (!($result['success'] ?? false)) {
                $this->logger->warning('referral.commission.process_failed', [
                    'referrer_id' => $payload['referrer_id'],
                    'amount' => $payload['amount'],
                    'result' => $result,
                    'correlation_id' => $correlationId
                ]);
            } else {
                $this->logger->info('referral.commission.processed', [
                    'referrer_id' => $payload['referrer_id'],
                    'amount' => $payload['amount'],
                    'currency' => $payload['currency'],
                    'source_user_id' => $payload['source_user_id'] ?? null,
                    'correlation_id' => $correlationId
                ]);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ReferralCommissionListener.handle']);
            $this->logger->error('referral.commission.exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event' => $event,
                'correlation_id' => $correlationId ?? null
            ]);
        }
    }

    /**
     * Extract payload from various event formats
     *
     * @return array<string, mixed>
     */
    private function extractPayload(mixed $event): array
    {
        if (is_array($event)) {
            return $event;
        }

        if ($event instanceof \Core\Event) {
            return $event->getData();
        }

        if (is_object($event) && method_exists($event, 'getData')) {
            return $event->getData();
        }

        return [];
    }
}
