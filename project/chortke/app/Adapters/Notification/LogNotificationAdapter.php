<?php

declare(strict_types=1);

namespace App\Adapters\Notification;

use App\Models\Notification;
use App\Models\SystemTelemetryModel;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;
use Core\CircuitBreaker;
use Core\Logger;

/**
 * LogNotificationService
 *
 * سرویس ارسال نوتیفیکیشن‌ها و مدیریت هشدارها
 *
 * Section 8.3/8.4 — فراخوانی‌های HTTP خارجی (Telegram, Webhook) از طریق
 * ExternalCallTrait درون Core\CircuitBreaker اجرا می‌شوند تا در صورت
 * cascading failure مسیر hot به‌سرعت fail-fast شود.
 */
class LogNotificationAdapter
{
    use ExternalCallTrait;
    use ValidatesExternalUrl;

    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuit;

    private \App\Services\Notification\NotificationOrchestrator $orchestrator;
    private Notification $notification;
    private SystemTelemetryModel $telemetry;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        Notification $notification,
        SystemTelemetryModel $telemetry,
        Logger $logger,
        CircuitBreaker $circuit,
        \App\Services\Notification\NotificationOrchestrator $orchestrator
    ) {
        $this->orchestrator = $orchestrator;
        unset($logger, $circuit);
        $this->logger       = $this->orchestrator->logger();
        $this->circuit      = $this->orchestrator->circuitBreaker();
        
        $this->notification = $notification;
        $this->telemetry = $telemetry;
    }

    /**
     * ارسال هشدار به تمام کانال‌های فعال
     */
    public function sendAlert(string $title, string $message, string $severity = 'medium'): void
    {
        $channels = $this->notification->getActiveChannelsBySeverity($severity);

        foreach ($channels as $channel) {
            try {
                $config = $this->decodeConfig($channel->config ?? null);

                $sent = match($channel->channel_type) {
                    'telegram' => $this->sendTelegram($config, $title, $message, $severity),
                    'email' => $this->sendEmail($config, $title, $message),
                    'sms' => $this->sendSMS($config, $title, $message),
                    'webhook' => $this->sendWebhook($config, $title, $message, $severity),
                    default => false
                };

                $this->notification->logHistory(
                    (int)$channel->id,
                    'alert',
                    $title,
                    $message,
                    $sent ? 'sent' : 'failed'
                );

            } catch (\Throwable $e) {
                $this->logger->error('log_notification.channel.send.failed', [
                    'channel' => 'notification',
                    'channel_id' => $channel->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ارسال پیام تلگرام (با CircuitBreaker + retry روی خطاهای transient)
     */
    /** @param array<string, mixed> $config */
    private function sendTelegram(array $config, string $title, string $message, string $severity): bool
    {
        if (empty($config['bot_token']) || empty($config['chat_id'])) {
            return false;
        }

        $emoji = match($severity) {
            'low' => '🔵',
            'medium' => '🟡',
            'high' => '🟠',
            'critical' => '🔴',
            default => '⚪'
        };

        $text = "{$emoji} *{$title}*\n\n{$message}\n\n⏰ " . date('Y-m-d H:i:s');
        $botToken = is_string($config['bot_token'] ?? null) ? trim($config['bot_token']) : '';
        if (preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $botToken) !== 1) {
            $this->logger->warning('log_notification.telegram.invalid_bot_token');
            return false;
        }
        $apiBase = config('services.telegram.api_base_url', 'https://api.telegram.org');
        $apiBase = is_string($apiBase) && $apiBase !== '' ? rtrim($apiBase, '/') : 'https://api.telegram.org';
        $url = $apiBase . '/bot' . $botToken . '/sendMessage';
        if (!$this->isExternalUrlSafe($url)) {
            $this->logger->warning('log_notification.telegram.ssrf_blocked', ['host'=>parse_url($url, PHP_URL_HOST)]);
            return false;
        }

        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        try {
            return (bool) $this->callWithBreaker('log_notif_telegram', function () use ($url, $data): bool {
                return $this->retryTransient(function () use ($url, $data): bool {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $data,
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        return true;
                    }
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'log_notif_telegram']);
                });
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('log_notification.telegram.permanent_failure', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.telegram.send.failed', [
                'channel' => 'notification',
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * ارسال ایمیل
     */
    /** @param array<string, mixed> $config */
    private function sendEmail(array $config, string $title, string $message): bool
    {
        $email = is_string($config['email'] ?? null) ? trim($config['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // AL2/Finding #15: Strip CRLF from subject to prevent Email Header Injection
        $cleanTitle = str_replace(["\r", "\n"], ' ', $title);
        $subject = "🔔 {$cleanTitle}";

        // Finding #16: Escape HTML title and message
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $body = "
        <html>
        <body style='font-family: Tahoma, Arial; direction: rtl;'>
            <h2 style='color: #d32f2f;'>{$safeTitle}</h2>
            <p>{$safeMessage}</p>
            <hr>
            <small>زمان: " . date('Y-m-d H:i:s') . "</small>
        </body>
        </html>
        ";

        $headers = [
            'From: System Alert <noreply@chortke.com>',
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0'
        ];

        return mail($email, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * ارسال SMS
     */
    /** @param array<string, mixed> $config */
    private function sendSMS(array $config, string $title, string $message): bool
    {
        return false;
    }

    /**
     * ارسال به Webhook (با CircuitBreaker + retry روی خطاهای transient)
     */
    /** @param array<string, mixed> $config */
    private function sendWebhook(array $config, string $title, string $message, string $severity): bool
    {
        if (empty((is_string($config['url'] ?? null) ? $config['url'] : ''))) {
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'timestamp' => time()
        ]);

        if (!$this->isExternalUrlSafe((string)(is_string($config['url'] ?? null) ? $config['url'] : ''))) {
            $this->logger->warning('log_notification.webhook.ssrf_blocked', ['host' => parse_url((string)(is_string($config['url'] ?? null) ? $config['url'] : ''), PHP_URL_HOST)]);
            return false;
        }

        // مشتق نام برای CB از host وب‌هوک تا breakerهای کانال‌های جدا مستقل باشند
        $host = parse_url((string)(is_string($config['url'] ?? null) ? $config['url'] : ''), PHP_URL_HOST) ?: 'unknown';
        $providerName = 'log_notif_webhook_' . $host;

        try {
            return (bool) $this->callWithBreaker($providerName, function () use ($config, $payload, $providerName): bool {
                return $this->retryTransient(function () use ($config, $payload, $providerName): bool {
                    $ch = curl_init((string)(is_string($config['url'] ?? null) ? $config['url'] : ''));
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    curl_close($ch);

                    if ($httpCode >= 200 && $httpCode < 300) {
                        return true;
                    }
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => $providerName ?? 'log_notif_webhook']);
                });
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('log_notification.webhook.permanent_failure', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.webhook.send.failed', [
                'channel' => 'notification',
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * تست کانال نوتیفیکیشن
     */
    /** @return array<string, mixed> */
    public function testChannel(int $channelId): array
    {
        $channel = $this->notification->getChannel($channelId);

        if (!$channel) {
            return ['success' => false, 'message' => 'کانال یافت نشد'];
        }

        $channelVars = get_object_vars($channel);
        $config = $this->decodeConfig($channelVars['config'] ?? null);
        $channelType = is_string($channelVars['channel_type'] ?? null) ? $channelVars['channel_type'] : '';

        $success = match($channelType) {
            'telegram' => $this->sendTelegram(
                $config,
                'تست سیستم',
                'این یک پیام تست است',
                'low'
            ),
            'email' => $this->sendEmail($config, 'تست سیستم', 'این یک ایمیل تست است'),
            default => false
        };

        return [
            'success' => $success,
            'message' => $success ? 'پیام با موفقیت ارسال شد' : 'ارسال پیام ناموفق بود'
        ];
    }

    /**
     * بررسی و اجرای قوانین هشدار
     */
    public function checkAlertRules(): void
    {
        $rules = $this->telemetry->getActiveAlertRules();

        foreach ($rules as $rule) {
            try {
                $condition = $this->decodeConfig($rule->condition ?? null);
                $triggered = $this->evaluateRule($rule, $condition);

                if ($triggered) {
                    $lastTrigger = $rule->last_triggered_at ? strtotime((string)$rule->last_triggered_at) : 0;

                    if (time() - $lastTrigger < 3600) {
                        continue;
                    }

                    $this->sendAlert(
                        (string)$rule->rule_name,
                        "قانون '{$rule->rule_name}' فعال شد",
                        (string)$rule->severity
                    );

                    $this->telemetry->updateRuleLastTriggered((int)$rule->id);
                }
            } catch (\Throwable $e) {
                $this->logger->error('log_notification.alert_rule.check.failed', [
                    'channel' => 'notification',
                    'rule_id' => $rule->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ارزیابی قانون هشدار
     */
    /** @return array<string, mixed> */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $condition */
    private function evaluateRule(object $rule, array $condition): bool
    {
        $metric = is_string($condition['metric'] ?? null) ? $condition['metric'] : '';
        $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : '>';
        $ruleVars = get_object_vars($rule);
        $timeWindow = is_numeric($ruleVars['time_window'] ?? null) ? (int)$ruleVars['time_window'] : 0;
        $threshold = is_numeric($ruleVars['threshold'] ?? null) ? (int)$ruleVars['threshold'] : 0;

        $value = match($metric) {
            'error_count' => $this->telemetry->getErrorCount($timeWindow),
            'critical_errors' => $this->telemetry->getCriticalErrorCount($timeWindow),
            'slow_requests' => $this->telemetry->getSlowRequestCount($timeWindow),
            'failed_login' => $this->telemetry->getFailedLoginCount($timeWindow),
            default => 0
        };

        return match($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => $value == $threshold,
            default => false
        };
    }
}
