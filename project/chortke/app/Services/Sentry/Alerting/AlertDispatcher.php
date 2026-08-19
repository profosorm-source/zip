<?php

declare(strict_types=1);

namespace App\Services\Sentry\Alerting;

use App\Events\AlertRequestedEvent;
use App\Models\SentryModel;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

/**
 * 🚨 AlertDispatcher - سیستم ارسال هوشمند Alert
 */
class AlertDispatcher
{


    /** @var array<string, int> */
    private array $throttleConfig = [
        'critical' => 60,
        'high' => 300,
        'medium' => 900,
        'low' => 3600,
    ];

    private SentryModel $model;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private \App\Services\Settings\AppSettings $appSettings;

    public function __construct(
        SentryModel $model,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        \App\Services\Settings\AppSettings $appSettings
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->model           = $model;
        $this->logger          = $logger;
        $this->appSettings     = $appSettings;
    }

    /**
     * BUGFIX-LISTENER-DI-2026-06: Resolve the *current* EventDispatcher.
     *
     * The constructor-injected $this->eventDispatcher can be stale when this
     * service is constructed during early bootstrap — before the singleton
     * binding for Core\EventDispatcher is registered, the container creates
     * a transient instance via reflection auto-wiring. That transient
     * dispatcher never receives the alert.requested listener (which is
     * registered later, on the *real* singleton instance), causing every
     * alert to be silently dropped with `alert.no_listeners`.
     *
     * To guarantee we always dispatch through the canonical, listener-aware
     * EventDispatcher, we re-resolve it from the singleton accessor at use
     * time. EventDispatcher::getInstance() is idempotent and cached, so
     * the cost is a single array lookup after the first call.
     */
    private function activeEventDispatcher(): EventDispatcher
    {
        $canonical = EventDispatcher::getInstance();
        if ($canonical !== $this->eventDispatcher) {
            // Heal the constructor-injected reference so subsequent calls
            // (and any debugging via reflection) see the canonical instance.
            $this->eventDispatcher = $canonical;
            $this->logger->info('alert.dispatcher.eventbus_rebound', [
                'note' => 'AlertDispatcher was constructed before EventDispatcher singleton; rebinding.'
            ]);
        }
        return $this->eventDispatcher;
    }

    /**
     * 🤖 Process Rules - بررسی خودکار تمام قوانین هشدار
     */
    public function processRules(): int
    {
        $rules = $this->model->getActiveRules();
        $triggeredCount = 0;

        foreach ($rules as $rule) {
            try {
                $value = $this->model->getMetricValue($rule->rule_type, (int)($rule->time_window ?: 60));
                
                if ($value >= (float)$rule->threshold) {
                    $alert = [
                        'type' => 'automated_rule',
                        'severity' => $rule->severity,
                        'title' => "Rule: {$rule->rule_name}",
                        'message' => "Threshold reached: {$value} >= {$rule->threshold} (Window: {$rule->time_window} min)",
                        'metadata' => [
                            'rule_id' => $rule->id,
                            'metric' => $rule->rule_type,
                            'value' => $value,
                            'threshold' => $rule->threshold
                        ]
                    ];

                    if ($this->dispatch($alert)) {
                        $this->model->updateRuleLastTriggered((int)$rule->id);
                        $triggeredCount++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('alert.rule_processing.failed', [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $triggeredCount;
    }

    /**
     * 📤 Dispatch Alert - ارسال alert
     */
    /** @param array<string, mixed> $alert */
    public function dispatch(array $alert): bool
    {
        // 🛡️ High-Level Push-Alerting Logic
        // We check a global system setting to see if Push Alerts are enabled.
        //
        // BUGFIX-RECURSION-2026-06 — Layer 3: AppSettings hits the database.
        // If the database itself is the source of the error being reported
        // (e.g. missing `system_settings` table), this call would recurse
        // back into Database::reportQueryError and trigger the loop we are
        // protecting against in core/Database.php. We harden this read so
        // that ANY failure here degrades to the documented default (true)
        // and is logged via the in-memory logger only (which itself must
        // never push back into AlertDispatcher).
        $pushEnabled = true;
        try {
            $settings    = $this->appSettings;
            $pushEnabled = (bool) $settings->get('sentry.push_alerts_enabled', true);
        } catch (\Throwable $settingsFailure) {
            // Intentionally swallowed: error path must not depend on settings.
            @error_log('AlertDispatcher: settings unavailable during dispatch, '
                     . 'defaulting push_alerts_enabled=true ('
                     . $settingsFailure->getMessage() . ')');
        }

        if (!$pushEnabled) {
            $this->logger->info('alert.push_disabled', ['alert' => $alert['title'] ?? 'unknown']);
            // We still dispatch to internal event listeners (for DB recording, etc.),
            // but we can skip the external push channels if we want.
            // For now, we let the dispatch proceed but the channel handler will also check.
        }

        $bus       = $this->activeEventDispatcher();
        $listeners = $bus->getListeners('alert.requested');
        if (empty($listeners)) {
            $this->logger->warning('alert.no_listeners', ['alert' => $alert['title'] ?? 'unknown']);
            return false;
        }

        $event = new AlertRequestedEvent($alert);
        $bus->dispatch('alert.requested', $event);
        return true;
    }

    public function handleAlertRequest(AlertRequestedEvent $event): bool
    {
        try {
            $alert = $this->normalizeAlert($event->alert);

            if ($this->isThrottled($alert)) {
                $this->logger->info('alert.throttled', ['alert' => $alert['title']]);
                return false;
            }

            $alertId = $this->storeAlert($alert);
            $channels = $this->model->getActiveChannels(str_value($alert['severity']));

            $sentCount = 0;
            foreach ($channels as $channel) {
                if ($this->sendToChannel($channel, $alert)) {
                    $sentCount++;
                    $this->model->recordNotificationHistory((int)$channel->id, $alertId, 'sent');
                } else {
                    $this->model->recordNotificationHistory((int)$channel->id, $alertId, 'failed');
                }
            }

            if ($sentCount > 0) {
                $this->model->markAlertAsSent($alertId);
            }

            return $sentCount > 0;
        } catch (\Throwable $e) {
            $this->logger->error('alert.dispatch.failed', [
                'channel' => 'alerting',
                'error' => $e->getMessage(),
                'alert' => $event->alert['title'] ?? 'unknown',
            ]);
            // Note: از error_log استفاده می‌کنیم نه captureException
            // تا از recursive loop جلوگیری کنیم (alerting → Sentry → alerting)
            @error_log('[Sentry][AlertDispatcher] dispatch failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<string, mixed> $alert
     * @return array<string, mixed>
     */
    private function normalizeAlert(array $alert): array
    {
        $normalized = array_merge([
            'type' => 'custom',
            'severity' => 'medium',
            'title' => 'Alert',
            'message' => '',
            'metadata' => [],
            'event_id' => null,
            'environment' => 'production',
        ], $alert);
        foreach (['type', 'severity', 'title', 'message', 'environment'] as $field) {
            if (!is_string($normalized[$field]) || ($field !== 'message' && $normalized[$field] === '')) {
                throw new \InvalidArgumentException("Alert {$field} must be a valid string.");
            }
        }
        if (!is_array($normalized['metadata'])) {
            throw new \InvalidArgumentException('Alert metadata must be an array.');
        }
        if ($normalized['event_id'] !== null && !is_string($normalized['event_id'])) {
            throw new \InvalidArgumentException('Alert event_id must be a string or null.');
        }
        return $normalized;
    }

    /** @param array<string, mixed> $alert */
    private function isThrottled(array $alert): bool
    {
        $severity = str_value($alert['severity']);
        $throttleSeconds = $this->throttleConfig[$severity] ?? 600;
        $fingerprint = $this->createAlertFingerprint($alert);

        $lastAlert = $this->model->getLastAlert($fingerprint, $severity);

        if (!$lastAlert) {
            return false;
        }

        $lastTime = strtotime((string)$lastAlert->created_at);
        $elapsed = time() - $lastTime;

        return $elapsed < $throttleSeconds;
    }

    /** @param array<string, mixed> $alert */
    private function createAlertFingerprint(array $alert): string
    {
        $components = [
            $alert['type'],
            $alert['title'],
            $alert['environment'] ?? 'production',
        ];

        return hash('sha256', implode('|', $components));
    }

    /** @param array<string, mixed> $alert */
    private function storeAlert(array $alert): int
    {
        return $this->model->storeAlert(array_merge($alert, [
            'fingerprint' => $this->createAlertFingerprint($alert)
        ]));
    }

    /** @param array<string, mixed> $alert */
    private function sendToChannel(\stdClass $channel, array $alert): bool
    {
        $decodedConfig = json_decode((string)$channel->config, true);
        $config = is_array($decodedConfig) ? $decodedConfig : [];

        return match($channel->channel_type) {
            'telegram' => $this->sendTelegram($config, $alert),
            'email' => $this->sendEmail($config, $alert),
            'slack' => $this->sendSlack($config, $alert),
            'webhook' => $this->sendWebhook($config, $alert),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $alert
     */
    private function sendTelegram(array $config, array $alert): bool
    {
        // Double-check global toggle before sending
        $settings = $this->appSettings;
        if (!$settings->get('sentry.push_alerts_enabled', true)) {
            return false;
        }

        $botToken = $config['bot_token'] ?? null;
        $chatId = $config['chat_id'] ?? null;
        if (!is_string($botToken) || $botToken === '' || (!is_string($chatId) && !is_int($chatId))) {
            throw new \InvalidArgumentException('Telegram channel requires string bot_token and scalar chat_id.');
        }

        $text = $this->formatTelegramMessage($alert);
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = null;
        try {
            $ch = curl_init($url);
            if ($ch === false) { return false; }
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // ارتقای تقارن معماری با آداپتورهای بلاکچین جهت جلوگیری از مسدود شدن ورکرهای وب در زمان کندی وب‌هوک‌های الرتینگ
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode === 200;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null && $ch !== false) {
                curl_close($ch);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $alert
     */
    private function sendEmail(array $config, array $alert): bool
    {
        if (empty($config['email'])) {
            return false;
        }

        $email = filter_var($config['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $this->logger->warning('Invalid alert destination email', ['email' => $config['email']]);
            return false;
        }

        // AL2: Strip any potential CRLF injections from the subject line
        $severity = str_value($alert['severity']);
        $title = str_value($alert['title']);
        $subject = str_replace(["\r", "\n"], ' ', "[{$severity}] {$title}");
        $body = $this->formatEmailMessage($alert);

        // AL2: Using array representation for headers is native protection against injection
        $headers = [
            'From' => 'noreply@chortke.com',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'PHP/' . phpversion()
        ];

        try {
            return mail($email, $subject, $body, $headers);
        } catch (\Throwable $e) {
            $this->logger->error('Email send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $alert
     */
    private function sendSlack(array $config, array $alert): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        $payload = [
            'text' => $alert['title'],
            'attachments' => [
                [
                    'color' => $this->getSeverityColor(str_value($alert['severity'])),
                    'text' => $alert['message'],
                    'fields' => [
                        ['title' => 'Severity', 'value' => strtoupper(str_value($alert['severity'])), 'short' => true],
                        ['title' => 'Environment', 'value' => $alert['environment'], 'short' => true],
                    ],
                    'footer' => 'Chortke Sentry',
                    'ts' => time(),
                ],
            ],
        ];

        if (!$this->isSafeUrl(str_value($config['webhook_url']))) {
            $this->logger->warning('Blocked SSRF vector or invalid URL in Slack dispatch', ['url' => $config['webhook_url']]);
            return false;
        }

        $ch = null;
        try {
            $ch = curl_init(str_value($config['webhook_url']));
            if ($ch === false) { return false; }
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // ارتقای تقارن معماری با آداپتورهای بلاکچین جهت جلوگیری از مسدود شدن ورکرهای وب در زمان کندی وب‌هوک‌های الرتینگ
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode === 200;
        } catch (\Throwable $e) {
            $this->logger->error('Slack send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null && $ch !== false) {
                curl_close($ch);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $alert
     */
    private function sendWebhook(array $config, array $alert): bool
    {
        if (!isset($config['url'])) {
            return false;
        }

        if (!$this->isSafeUrl(str_value($config['url']))) {
            $this->logger->warning('Blocked SSRF vector or invalid URL in webhook dispatch', ['url' => $config['url']]);
            return false;
        }

        $ch = null;
        try {
            $ch = curl_init(str_value($config['url']));
            if ($ch === false) { return false; }
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($alert));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // ارتقای تقارن معماری با آداپتورهای بلاکچین جهت جلوگیری از مسدود شدن ورکرهای وب در زمان کندی وب‌هوک‌های الرتینگ
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Throwable $e) {
            $this->logger->error('Webhook send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null && $ch !== false) {
                curl_close($ch);
            }
        }
    }

    /** @param array<string, mixed> $alert */
    private function formatTelegramMessage(array $alert): string
    {
        $emoji = match($alert['severity']) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };

        $title = htmlspecialchars(str_value($alert['title']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars(str_value($alert['message']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $severity = htmlspecialchars(str_value($alert['severity']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $environment = htmlspecialchars(str_value($alert['environment']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eventId = $alert['event_id'] ?? null;
        $text = "{$emoji} <b>{$title}</b>\n\n";
        $text .= "{$message}\n\n";
        $text .= "📊 Severity: <code>{$severity}</code>\n";
        $text .= "🌍 Environment: <code>{$environment}</code>\n";
        if (is_string($eventId) && $eventId !== '') {
            $safeEventId = htmlspecialchars($eventId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text .= "🔗 Event ID: <code>{$safeEventId}</code>\n";
        }
        return $text;
    }

    /** @param array<string, mixed> $alert */
    private function formatEmailMessage(array $alert): string
    {
        $now = date('Y-m-d H:i:s');
        $severity = htmlspecialchars(str_value($alert['severity']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars(str_value($alert['title']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = nl2br(htmlspecialchars(str_value($alert['message']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $environment = htmlspecialchars(str_value($alert['environment']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $color = $this->getSeverityColor(str_value($alert['severity']));
        return <<<HTML
        <html>
        <body style="font-family: Arial, sans-serif;">
            <h2 style="color: {$color};">{$title}</h2>
            <p>{$message}</p>
            <table>
                <tr><td><strong>Severity:</strong></td><td>{$severity}</td></tr>
                <tr><td><strong>Environment:</strong></td><td>{$environment}</td></tr>
                <tr><td><strong>Time:</strong></td><td>{$now}</td></tr>
            </table>
        </body>
        </html>
        HTML;
    }

    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#dc3545',
            'high' => '#fd7e14',
            'medium' => '#ffc107',
            'low' => '#28a745',
            default => '#6c757d',
        };
    }

    /**
     * 🛡️ isSafeUrl - SSRF Mitigation by resolving host IP and filtering out local/private ranges.
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];
        
        // Defensive local check before DNS resolution
        $forbiddenHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array(strtolower((string)$host), $forbiddenHosts, true)) {
            return false;
        }

        $ip = gethostbyname($host);
        if (!$ip || $ip === $host) {
            // Could not resolve or matches original hostname (e.g. invalid IP or internal host)
            return false;
        }

        // Enforce validation that IP is not in private or reserved ranges
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
