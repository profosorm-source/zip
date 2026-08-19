<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\SmsNotificationService;
use App\Contracts\LoggerInterface;
use App\Contracts\JobInterface;

/**
 * ProcessNotificationJob
 * 
 * این کلاس وظیفه ارسال پیام‌ها به APIهای خارجی (FCM, SMS, Email)
 * را در پس‌زمینه بر عهده دارد تا از کندی و Deadlock در سیستم جلوگیری شود.
 */
class ProcessNotificationJob implements JobInterface
{
    private NotificationDispatcher $dispatcher;
    private LoggerInterface $logger;
    private ?SmsNotificationService $smsService;
    public function __construct(
        NotificationDispatcher $dispatcher,
        LoggerInterface $logger,
        ?SmsNotificationService $smsService = null
    ) {        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->smsService = $smsService;
}

    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $payload */
public function handle(array $payload): void
    {
        $channelValue = $payload['channel'] ?? null;
        $userIdsValue = $payload['user_ids'] ?? null;
        if (!is_string($channelValue) || !in_array($channelValue, ['fcm', 'sms'], true)) {
            throw new \InvalidArgumentException('Notification channel must be fcm or sms.');
        }
        if (!is_array($userIdsValue) || $userIdsValue === []) {
            throw new \InvalidArgumentException('Notification user_ids must be a non-empty array.');
        }
        $channel = $channelValue;
        $userIds = array_map(static fn(mixed $value): int => int_value($value), $userIdsValue);

        $cache = cache();
        $cbKey = "circuit_breaker:notif_{$channel}";
        
        if ($cache->get("{$cbKey}:open")) {
            $this->logger->warning('notif.circuit_breaker_open', ['channel' => $channel]);
            // Throw exception to let the queue worker release/delay the job
            throw new \RuntimeException("Circuit breaker is OPEN for {$channel}. Deferring execution.");
        }

        try {
            switch ($channel) {
                case 'fcm':
                    $this->dispatcher->dispatchBulk(
                        'fcm', 
                        $userIds,
                        str_value($payload['title'] ?? ''), 
                        str_value($payload['message'] ?? ''), 
                        is_array($payload['data'] ?? null) ? $payload['data'] : null, 
                        null, 
                        $payload['action_url'] === null ? null : str_value($payload['action_url'])
                    );
                    break;
    
                case 'sms':
                    $userIds = $userIds;
                    if (count($userIds) === 1) {
                        $userId = $userIds[0];
                        $smsType = str_value($payload['sms_type'] ?? 'generic');
                        
                        if ($smsType === 'security' && $this->smsService) {
                            $this->smsService->sendSecurityAlertToUser(int_value($userId), str_value($payload['message']));
                        } elseif ($smsType === 'withdrawal' && $this->smsService) {
                            $this->smsService->sendWithdrawalAlertToUser(int_value($userId), str_value($payload['amount']), str_value($payload['currency']));
                        } else {
                            $this->dispatcher->dispatch('sms', int_value($userId), str_value($payload['title'] ?? ''), str_value($payload['message'] ?? ''));
                        }
                    }
                    break;
            }
            
            // Success -> Reset errors
            $cache->forget("{$cbKey}:errors");

        } catch (\Throwable $e) {
            // Failure -> Increment errors
            $errors = $cache->increment("{$cbKey}:errors", 1, 60);
            if ($errors >= 30) {
                // Trip the breaker for 5 minutes
                $cache->put("{$cbKey}:open", true, 300);
            }

            $this->logger->error('job.process_notification_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
                'consecutive_errors' => $errors
            ]);
            
            throw $e;
        }
    }
}
