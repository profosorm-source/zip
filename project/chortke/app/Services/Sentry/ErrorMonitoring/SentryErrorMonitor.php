<?php

declare(strict_types=1);

namespace App\Services\Sentry\ErrorMonitoring;

use App\Models\SentryModel;
use Core\Logger;
use App\Utils\Sentry\StackTraceAnalyzer;
use App\Utils\Sentry\BreadcrumbCollector;
use App\Utils\Sentry\ContextEnricher;
use App\Services\Sentry\Alerting\AlertDispatcher;
use App\Contracts\CacheInterface;

/**
 * 🔥 SentryErrorMonitor - سیستم مانیتورینگ خطا مشابه Sentry
 */
class SentryErrorMonitor implements \App\Contracts\DatabaseErrorReporter
{


    private StackTraceAnalyzer $stackAnalyzer;
    private BreadcrumbCollector $breadcrumbs;
    private ContextEnricher $contextEnricher;
    
    /** @var array<string, mixed> */
    private array $config = [
        'enabled' => true,
        'environment' => 'production',
        'release' => null,
        'sample_rate' => 1.0,
        'ignore_exceptions' => [],
        'before_send' => null,
    ];

    #[\Core\Attributes\Inject]
    private \Core\Container $container;

    private SentryModel $model;
    private AlertDispatcher $alertDispatcher;
    private CacheInterface $cache;

    /** @param array<string, mixed> $config */
    public function __construct(
        SentryModel $model,
        AlertDispatcher $alertDispatcher,
        CacheInterface $cache,
        array $config = []
    ) {
        $this->model = $model;
        $this->alertDispatcher = $alertDispatcher;
        $this->cache = $cache;
        $this->container = \Core\Container::getInstance();

        $this->config = array_merge($this->config, $config);

        $this->stackAnalyzer = new StackTraceAnalyzer();
        $this->breadcrumbs = new BreadcrumbCollector();
        $this->contextEnricher = new ContextEnricher();

        if (!$this->config['release']) {
            $this->config['release'] = $this->detectRelease();
        }
    }

    /**
     * Helper to resolve logger lazily to break circular dependency:
     * SentryErrorMonitor -> Logger -> LogService -> AuditTrail -> SentryErrorMonitor
     */
    private function getLogger(): ?\Core\Logger
    {
        try {
            $result = $this->container->make(\Core\Logger::class);
            assert($result instanceof \Core\Logger);
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 🎯 Capture Exception - ورودی اصلی برای ثبت خطا
     */
    public function captureException(
        \Throwable $exception,
        array $extraContext = [],
        string $level = 'error',
        ?int $userId = null
    ): ?string {
        try {
            if (!$this->config['enabled'] || !$this->shouldCapture() || $this->shouldIgnore($exception)) {
                return null;
            }

            $event = $this->buildEvent($exception, $userId, $extraContext, $level);

            if (is_callable($this->config['before_send'])) {
                $processedEvent = call_user_func($this->config['before_send'], $event);
                if ($processedEvent === null) return null;
                if (!is_array($processedEvent)) {
                    throw new \UnexpectedValueException('Sentry before_send must return an event array or null.');
                }
                $event = $processedEvent;
            }

            $eventId = $this->storeEvent($event);

            $this->handleAlerting($event, $eventId);
            $this->breadcrumbs->clear();

            return $eventId;

        } catch (\Throwable $e) {
            $logger = $this->getLogger();
            if ($logger) {
                $logger->critical('sentry.error_monitor.failed', [
                    'channel' => 'sentry',
                    'error' => $e->getMessage(),
                ]);
            } else {
                @error_log('Sentry Error Monitor Critical Failure: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 🚨 Capture Anomaly - شناسایی خطاهای منطقی (Silent Failures)
     *
     * این متد برای زمانی است که کد کرش نمی‌کند اما دیتای اشتباه تولید کرده است.
     * مثال: تراز کیف پول با تراکنش‌ها یکی نیست.
     */
    /** @param array<string, mixed> $context */
    public function captureAnomaly(
        string $anomalyType,
        string $message,
        array $context = [],
        string $level = 'critical',
        ?int $userId = null
    ): ?string {
        try {
            if (!$this->config['enabled']) {
                return null;
            }

            // ناهنجاری‌ها همیشه ثبت می‌شوند چون خطرناک‌تر از Exceptionهای معمولی هستند
            $event = [
                'event_id' => $this->generateEventId(),
                'timestamp' => microtime(true),
                'level' => $level,
                'message' => "[ANOMALY] {$anomalyType}: {$message}",
                'logger' => 'anomaly_detector',
                'platform' => 'php',
                'environment' => $this->config['environment'],
                'release' => $this->config['release'],
                'user' => $this->getUserContext($userId),
                'request' => $this->getRequestContext(),
                'tags' => array_merge($this->getTags(), ['type' => 'anomaly']),
                'extra' => $context,
                'breadcrumbs' => $this->breadcrumbs->getAll(),
            ];

            $eventId = $this->storeEvent($event);

            // ناهنجاری‌ها به طور پیش‌فرض در سطح Critical هستند و باید Alert شوند
            $this->handleAlerting($event, $eventId);
            
            return $eventId;

        } catch (\Throwable $e) {
            $logger = $this->getLogger();
            if ($logger) {
                $logger->error('captureAnomaly failed', ['error' => $e->getMessage()]);
            } else {
                @error_log('Sentry captureAnomaly failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 📝 Capture Message - برای لاگ پیام‌های manual
     */
    public function captureMessage(
        string $message,
        string $level = 'info',
        array $context = [],
        ?int $userId = null
    ): ?string {
        try {
            if (!$this->config['enabled'] || !$this->shouldCapture()) {
                return null;
            }

            $event = [
                'event_id' => $this->generateEventId(),
                'timestamp' => microtime(true),
                'level' => $level,
                'message' => $message,
                'logger' => 'php',
                'platform' => 'php',
                'environment' => $this->config['environment'],
                'release' => $this->config['release'],
                'user' => $this->getUserContext($userId),
                'request' => $this->getRequestContext(),
                'tags' => $this->getTags(),
                'extra' => $context,
                'breadcrumbs' => $this->breadcrumbs->getAll(),
            ];

            return $this->storeEvent($event);

        } catch (\Throwable $e) {
            $logger = $this->getLogger();
            if ($logger) {
                $logger->error('captureMessage failed', ['error' => $e->getMessage()]);
            } else {
                @error_log('Sentry captureMessage failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 🍞 Add Breadcrumb
     */
    /** @param array<string, mixed> $data */
    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $this->breadcrumbs->add($message, $category, $level, $data);
    }

    /**
     * @param array<string, mixed> $extraContext
     * @return array<string, mixed>
     */
    private function buildEvent(\Throwable $exception, ?int $userId, array $extraContext, string $level): array
    {
        $stackTrace = $this->stackAnalyzer->analyze($exception);
        $fingerprint = $this->generateFingerprint($exception, $stackTrace);
        $eventId = $this->generateEventId();

        return [
            'event_id' => $eventId,
            'timestamp' => microtime(true),
            'level' => $level,
            'logger' => 'php',
            'platform' => 'php',
            'sdk' => [
                'name' => 'chortke-sentry',
                'version' => '1.0.0',
            ],
            'exception' => [
                'type' => get_class($exception),
                'value' => $exception->getMessage(),
                'stacktrace' => $stackTrace,
                'module' => $this->getModuleFromException($exception),
            ],
            'fingerprint' => $fingerprint,
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            'user' => $this->getUserContext($userId),
            'request' => $this->getRequestContext(),
            'contexts' => $this->contextEnricher->enrich(),
            'tags' => $this->getTags(),
            'breadcrumbs' => $this->breadcrumbs->getAll(),
            'extra' => array_merge($extraContext, [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ]),
        ];
    }

    /** @param array<string, mixed> $event */
    private function storeEvent(array $event): string
    {
        $fallbackEventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : $this->generateEventId();
        try {
            // EM2: Filter PII and mask secrets recursively prior to storage to secure persistence logs
            $event = $this->sanitizeData($event);
            $eventId = $event['event_id'] ?? null;
            if (!is_string($eventId) || $eventId === '') {
                throw new \UnexpectedValueException('Sentry event_id must be a non-empty string.');
            }
            $exception = is_array($event['exception'] ?? null) ? $event['exception'] : [];
            $user = is_array($event['user'] ?? null) ? $event['user'] : [];
            $request = is_array($event['request'] ?? null) ? $event['request'] : [];
            $stackTrace = is_array($exception['stacktrace'] ?? null) ? $exception['stacktrace'] : [];

            $fingerprint = str_value($event['fingerprint'] ?? $this->generateSimpleFingerprint($event));

            // EM1: Throttle and consolidate database writes under high-volume bursts
            if ($this->isRateLimited($fingerprint)) {
                return $eventId;
            }

            $existingIssue = $this->model->findExistingIssue($fingerprint, str_value($this->config['environment']));

            if ($existingIssue) {
                $this->model->updateIssueStats((int)$existingIssue->id, str_value($event['level']));
                $issueId = (int)$existingIssue->id;
            } else {
                $issueId = $this->model->createIssue([
                    'fingerprint' => $fingerprint,
                    'level' => $event['level'],
                    'title' => $this->getIssueTitle($event),
                    'culprit' => $this->getCulprit($event),
                    'environment' => $this->config['environment'],
                    'release' => $this->config['release'],
                    'metadata' => [
                        'exception_type' => $exception['type'] ?? null,
                        'platform' => $event['platform'] ?? 'php',
                    ]
                ]);
            }

            $this->model->storeEventRecord([
                'event_id' => $event['event_id'],
                'issue_id' => $issueId,
                'level' => $event['level'],
                'message' => $event['message'] ?? $exception['value'] ?? '',
                'exception_type' => $exception['type'] ?? null,
                'stack_trace' => json_encode($stackTrace),
                'breadcrumbs' => json_encode($event['breadcrumbs'] ?? []),
                'user_context' => json_encode($event['user'] ?? []),
                'request_context' => json_encode($event['request'] ?? []),
                'device_context' => json_encode($event['contexts'] ?? []),
                'tags' => json_encode($event['tags'] ?? []),
                'extra' => json_encode($event['extra'] ?? []),
                'environment' => $event['environment'],
                'release_version' => $event['release'],
                'user_id' => $user['id'] ?? null,
                // Masked IP from request context mapped securely (EM2 enforced)
                'ip_address' => $request['ip'] ?? null,
                'user_agent' => $request['user_agent'] ?? null,
            ]);

            return $eventId;
        } catch (\Throwable $e) {
            // Log only to internal logger, avoid echoing to stdout during tests
            $logger = $this->getLogger();
            if ($logger) {
                $logger->error('storeEvent failed', ['error' => $e->getMessage()]);
            } else {
                @error_log('Sentry storeEvent failed: ' . $e->getMessage());
            }
            return $fallbackEventId;
        }
    }

    /** @param array<string, mixed> $event */
    private function handleAlerting(array $event, string $eventId): void
    {
        $exception = is_array($event['exception'] ?? null) ? $event['exception'] : [];
        $stackTrace = is_array($exception['stacktrace'] ?? null) ? $exception['stacktrace'] : [];
        $frames = is_array($stackTrace['frames'] ?? null) ? $stackTrace['frames'] : [];
        $firstFrame = is_array($frames[0] ?? null) ? $frames[0] : [];
        if (!in_array($event['level'], ['error', 'critical', 'fatal'])) {
            return;
        }

        $this->alertDispatcher->dispatch([
            'type' => 'error',
            'severity' => $this->mapLevelToSeverity(str_value($event['level'])),
            'title' => $this->getIssueTitle($event),
            'message' => $exception['value'] ?? $event['message'] ?? '',
            'event_id' => $eventId,
            'environment' => $event['environment'],
            'metadata' => [
                'exception_type' => $exception['type'] ?? null,
                'file' => $firstFrame['file'] ?? null,
                'line' => $firstFrame['line'] ?? null,
            ]
        ]);
    }

    /** @param array<string, mixed> $stackTrace */
    private function generateFingerprint(\Throwable $exception, array $stackTrace): string
    {
        $frames = is_array($stackTrace['frames'] ?? null) ? $stackTrace['frames'] : [];
        $frame = is_array($frames[0] ?? null) ? $frames[0] : [];
        $normalizedMessage = $this->normalizeMessage($exception->getMessage());
        
        $components = [
            get_class($exception),
            $frame['file'] ?? '',
            $frame['line'] ?? '',
            $normalizedMessage,
        ];

        $hash = hash('sha256', implode('|', $components));
        // Debug: print components to see why fingerprints differ
        // error_log("Sentry Fingerprint Components: " . json_encode($components) . " -> Hash: $hash");
        return $hash;
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = (string) preg_replace('/\d+/', 'N', $message);
        $normalized = (string) preg_replace('/0x[0-9a-f]+/i', '0xHEX', $normalized);
        $normalized = (string) preg_replace('/\/[\w\/]+\//', '/PATH/', $normalized);
        return substr($normalized, 0, 200);
    }

    /** @param array<string, mixed> $event */
    private function generateSimpleFingerprint(array $event): string
    {
        $exception = is_array($event['exception'] ?? null) ? $event['exception'] : [];
        $message = str_value($event['message'] ?? $exception['value'] ?? '');
        $type = str_value($exception['type'] ?? 'message');
        return hash('sha256', $type . '|' . $this->normalizeMessage($message));
    }

    /** @param array<string, mixed> $event */
    private function getIssueTitle(array $event): string
    {
        $exception = is_array($event['exception'] ?? null) ? $event['exception'] : [];
        if (isset($exception['type'])) {
            $type = str_value($exception['type']);
            $shortType = substr(strrchr($type, '\\') ?: $type, 1);
            return $shortType . ': ' . substr(str_value($exception['value'] ?? ''), 0, 100);
        }
        return substr(str_value($event['message'] ?? 'Unknown Error'), 0, 150);
    }

    /** @param array<string, mixed> $event */
    private function getCulprit(array $event): ?string
    {
        $exception = is_array($event['exception'] ?? null) ? $event['exception'] : [];
        $stackTrace = is_array($exception['stacktrace'] ?? null) ? $exception['stacktrace'] : [];
        $frames = is_array($stackTrace['frames'] ?? null) ? $stackTrace['frames'] : [];
        $frame = is_array($frames[0] ?? null) ? $frames[0] : null;
        if ($frame === null) return null;
        $file = basename(str_value($frame['file'] ?? ''));
        $function = str_value($frame['function'] ?? '');
        return $file ? "{$file} in {$function}" : null;
    }

    /** @return array<string, mixed> */
    private function getUserContext(?int $userId): array
    {
        $context = ['id' => $userId];
        if ($userId) {
            try {
                $user = $this->model->getUserData($userId);
                if ($user && !empty($user->email)) {
                    $parts = explode('@', (string)$user->email, 2);
                    $maskedEmail = mb_substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '');
                    $context['email'] = $maskedEmail;
                    $context['username'] = 'User #' . $userId;
                }
            } catch (\Throwable $e) {
                @error_log('[SentryErrorMonitor] getUserContext failed: ' . $e->getMessage());
            }
        }
        return $context;
    }

    /** @return array<string, mixed> */
    private function getRequestContext(): array
    {
        $query = app()->request->query();
        $cleanQuery = [];
        $sensitiveParams = ['token', 'sig', 'api_key', 'authorization', 'password', 'secret', 'cvv', 'card', 'key'];
        if (is_array($query)) {
            foreach ($query as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), $sensitiveParams, true)) {
                    $cleanQuery[$k] = '[REDACTED]';
                } else {
                    $cleanQuery[$k] = $v;
                }
            }
        }

        // Clean URI query string as well (Finding #7 & #9)
        $rawUri = app()->request->uri();
        $cleanUri = preg_replace('/([?&](?:token|sig|api_key|password|secret|key)=)[^&]*/i', '$1[REDACTED]', $rawUri);

        return [
            'url' => $cleanUri,
            'method' => app()->request->method(),
            'query_string' => $cleanQuery !== [] ? http_build_query($cleanQuery) : null,
            'headers' => $this->getHeaders(),
            'ip' => get_client_ip(),
            'user_agent' => get_user_agent(),
        ];
    }

    /** @return array<string, mixed> */
    private function getHeaders(): array
    {
        $headers = [];
        foreach ((array)$_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('HTTP_', '', $key);
                $header = str_replace('_', '-', $header);
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    /** @return array<string, mixed> */
    private function getTags(): array
    {
        return [
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            'php_version' => PHP_VERSION,
        ];
    }

    private function getModuleFromException(\Throwable $exception): ?string
    {
        $class = get_class($exception);
        $parts = explode('\\', $class);
        return $parts[0] ?? null;
    }

    private function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function detectRelease(): ?string
    {
        $gitHead = dirname(__DIR__, 4) . '/.git/HEAD';
        if (file_exists($gitHead)) {
            $head = trim((string)file_get_contents($gitHead));
            if (preg_match('/ref: (.+)/', $head, $matches)) {
                return basename($matches[1]);
            }
        }
        $release = config('app.release', 'unknown');
        return is_string($release) ? $release : null;
    }

    private function shouldCapture(): bool
    {
        $sampleRate = float_value($this->config['sample_rate'] ?? 1.0);
        if ($sampleRate >= 1.0) return true;
        if ($sampleRate <= 0.0) return false;

        // EM3: Use only a validated, non-empty string as the deterministic
        // sampling identity. Request headers are untrusted/mixed input and
        // must never be blindly cast before hashing.
        $traceCandidate = app()->request->header('x-request-id');
        $traceId = is_string($traceCandidate) ? trim($traceCandidate) : '';
        if ($traceId === '') {
            $traceId = get_client_ip() . '|' . get_user_agent();
        }

        // Derive deterministic 32-bit value from the validated trace signature
        $hashValue = hexdec(substr(md5($traceId), 0, 8));
        $maxLimit = 0xFFFFFFFF;

        return ($hashValue / $maxLimit) <= $sampleRate;
    }

    private function shouldIgnore(\Throwable $exception): bool
    {
        $class = get_class($exception);
        return in_array($class, is_array($this->config['ignore_exceptions'] ?? null) ? $this->config['ignore_exceptions'] : [], true);
    }

    private function mapLevelToSeverity(string $level): string
    {
        return match($level) {
            'critical', 'fatal' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            default => 'low',
        };
    }

    public function setConfig(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function getStatistics(string $period = 'today'): array
    {
        $stats = $this->model->getErrorStats($period, str_value($this->config['environment']));

        return [
            'total_issues' => $stats->total_issues ?? 0,
            'total_events' => $stats->total_events ?? 0,
            'critical_count' => $stats->critical_count ?? 0,
            'error_count' => $stats->error_count ?? 0,
            'warning_count' => $stats->warning_count ?? 0,
        ];
    }

    /**
     * EM1: Throttles event captures under rapid exception bursts to maintain DB responsiveness
     */
    private function isRateLimited(string $fingerprint): bool
    {
        $cacheKey = 'sentry:burst_limit:' . md5($fingerprint);
        
        try {
            // Standard increment on PSR-16 (or simulation)
            $current = int_value($this->cache->get($cacheKey, 0));
            if ($current >= 10) {
                // Block if limit exceeded (10 errors per 60s)
                return true;
            }
            
            $this->cache->set($cacheKey, $current + 1, 60);
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * EM2: Recursive deep filtering of PII identifiers and sensitive secrets to enforce GDPR compliance
     */
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'passwd', 'secret', 'token', 'api_key', 'auth', 'authorization', 'cookie', 'session', 'card', 'credit'];
        $result = [];

        foreach ((array)$data as $key => $value) {
            $lowKey = strtolower((string)$key);
            
            $isSensitive = false;
            foreach ($sensitiveKeys as $sKey) {
                if (str_contains($lowKey, $sKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[FILTERED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeData($value);
            } elseif (in_array($lowKey, ['ip', 'ip_address', 'remote_addr'], true)) {
                // Mask the last octet/segments of IP address to defend PII disclosure vectors
                $ip = str_value($value);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $result[$key] = preg_replace('/\d+$/', 'XXX', $ip);
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $result[$key] = preg_replace('/:[0-9a-fA-F]+$/', ':XXXX', $ip);
                } else {
                    $result[$key] = '[MASKED]';
                }
            } elseif ($lowKey === 'email' && is_string($value) && str_contains($value, '@')) {
                // EM2: ایمیل mask بشه (نه حذف) — برای debug مفیده ولی PII محافظت بشه
                $result[$key] = $this->maskEmail($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * ایمیل mask: u***r@domain.com
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '[MASKED_EMAIL]';
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (mb_strlen((string)$local) <= 2) {
            $masked = $local[0] . '***';
        } else {
            $masked = $local[0] . str_repeat('*', mb_strlen((string)$local) - 2) . mb_substr($local, -1);
        }

        return $masked . '@' . $domain;
    }
}
