<?php

declare(strict_types=1);

namespace App\Services\Sentry;

use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;
use App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor;
use App\Contracts\CacheInterface;
use Core\Logger;
use Core\Session;

/**
 * 🛡️ SentryExceptionHandler - Global Handler برای خطاها
 */
class SentryExceptionHandler
{
    private static ?self $instance = null;
    private bool $registered = false;

    private SentryErrorMonitor $errorMonitor;
    private SentryPerformanceMonitor $performanceMonitor;
    private Logger $logger;
    private Session $session;

    /**
     * Cache-backed circuit breaker — پشتیبانی از محیط multi-process/worker
     * اگر cache در دسترس نبود، fallback به فایل‌محور قدیمی می‌کند.
     */
    private ?CacheInterface $cache = null;

    private const CB_CACHE_KEY     = 'sentry:circuit_breaker:failures';
    private const CB_TS_KEY        = 'sentry:circuit_breaker:last_failure';
    private const CB_THRESHOLD     = 5;
    private const CB_COOLDOWN_SECS = 60;

    public function __construct(
        SentryErrorMonitor $errorMonitor,
        SentryPerformanceMonitor $performanceMonitor,
        Logger $logger,
        Session $session,
        ?CacheInterface $cache = null
    ) {
        $this->errorMonitor = $errorMonitor;
        $this->performanceMonitor = $performanceMonitor;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->session = $session;
}

    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('SentryExceptionHandler instance has not been initialized.');
        }
        return self::$instance;
    }

    /**
     * 📝 Register - ثبت handlerها
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        $this->registered = true;
    }

    /**
     * 🚨 Handle Error
     */
    public function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // اگر این سطح خطا توسط error_reporting فعلی پوشش داده نمیشه، به PHP بده
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        
        $level = match($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            default => 'info'
        };

        if (in_array($errno, [E_ERROR, E_WARNING, E_USER_ERROR, E_USER_WARNING])) {
            if (!$this->isCircuitOpen()) {
                try {
                    $userId = $this->getCurrentUserId();
                    $this->errorMonitor->captureException($exception, [], $level, $userId);
                } catch (\Throwable $e) {
                    $this->recordFailure();
                }
            }
        }

        // false = PHP standard handler هم اجرا بشه (Notice, Deprecated, ... لاگ بشن)
        return false;
    }

    /**
     * 💥 Handle Exception
     */
    public function handleException(\Throwable $exception): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if (!$this->isCircuitOpen()) {
                $this->errorMonitor->captureException($exception, ['http_code' => http_response_code()], 'error', $userId);
            }
            $this->displayErrorPage($exception);
        } catch (\Throwable $e) {
            $this->recordFailure();
            $this->logger->critical('sentry.exception_handler.failed', ['channel' => 'sentry', 'error' => $e->getMessage()]);
            $this->fallbackDisplay($exception);
        }
    }

    /**
     * ⚠️ Handle Shutdown (برای Fatal Errors)
     */
    public function handleShutdown(): void
    {
        @ignore_user_abort(true);
        @set_time_limit(10);
        
        try {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                $userId = $this->getCurrentUserId();
                if (!$this->isCircuitOpen()) {
                    $this->errorMonitor->captureException($exception, [], 'fatal', $userId);
                }
            }
        } catch (\Throwable $e) {
            $this->recordFailure();
            // Shutdown context — logger ممکنه unavailable باشه
            try {
                $this->logger->critical('sentry.shutdown_capture.failed', [
                    'channel' => 'sentry',
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // آخرین fallback — فقط PHP error log
                @error_log('Sentry Shutdown capture failed: ' . $e->getMessage());
            }
        }

        $this->finishPerformanceTracking();
    }

    private function finishPerformanceTracking(): void
    {
        try {
            $this->performanceMonitor->finishTransaction([
                'status_code' => http_response_code(),
                'user_id' => $this->getCurrentUserId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('sentryexceptionhandler.operation_failed', ['error' => $e->getMessage()]);
        }
    }

    private function displayErrorPage(\Throwable $exception): void
    {
        http_response_code(500);
        $appEnv = config('app.env', 'production');
        $isDebug = (bool)config('app.debug', false);
        
        // 🛡️ MOBILE COMPATIBILITY FIX (API JSON Error Shield): جلوگیری از کرش پارسرهای موبایل
        // ارسال ساختار استاندارد JSON به جای HTML در زمان وقوع خطاهای کشنده سرور برای کلاینت‌های موبایل
        $isApi = str_starts_with(str_value(app()->request->uri()), '/api/') || str_contains(str_value(app()->request->header('accept') ?? ''), 'application/json');
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'خطای سرور رخ داده است. لطفاً چند لحظه دیگر تلاش کنید.',
                'error' => ($appEnv !== 'production' || $isDebug) ? $exception->getMessage() : 'INTERNAL_SERVER_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($appEnv === 'production' && !$isDebug) {
            $errorView = dirname(__DIR__, 3) . '/views/errors/500.php';
            if (file_exists($errorView)) {
                include $errorView;
            } else {
                echo '<h1>خطایی رخ داده است</h1><p>لطفاً بعداً تلاش کنید.</p>';
            }
        } else {
            $this->detailedDisplay($exception);
        }
    }

    private function fallbackDisplay(\Throwable $exception): void
    {
        $appEnv = config('app.env', 'production');
        $isApi = str_starts_with(str_value(app()->request->uri()), '/api/') || str_contains(str_value(app()->request->header('accept') ?? ''), 'application/json');
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'خطای سرور رخ داده است.',
                'error' => ($appEnv !== 'production') ? $exception->getMessage() : 'INTERNAL_SERVER_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($appEnv !== 'production') {
            echo '<h1>Error</h1><p>' . e($exception->getMessage()) . '</p>';
        } else {
            echo '<h1>خطایی رخ داده است</h1><p>لطفاً بعداً تلاش کنید.</p>';
        }
    }

    private function detailedDisplay(\Throwable $exception): void
    {
        $trace = mb_substr($exception->getTraceAsString(), 0, 12000);
        $basePath = realpath(dirname(__DIR__, 3));
        if ($basePath) {
            $trace = str_replace($basePath, '[ROOT]', $trace);
        }
        
        $file = $exception->getFile();
        if ($basePath) {
            $file = str_replace($basePath, '[ROOT]', $file);
        }

        echo '<html><head><title>Error</title><style>body{font-family:sans-serif;padding:20px;background:#f5f5f5;}.error{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}h1{color:#d32f2f;margin:0 0 10px;}pre{background:#f5f5f5;padding:15px;overflow:auto;}</style></head><body><div class="error">';
        echo '<h1>' . e(get_class($exception)) . '</h1><p>' . e($exception->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . e($file) . ':' . $exception->getLine() . '</p><h3>Stack Trace:</h3><pre>' . e($trace) . '</pre></div></body></html>';
    }

    private function getCurrentUserId(): ?int
    {
        try {
            return $this->session->get('user_id') ? int_value($this->session->get('user_id')) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getErrorMonitor(): SentryErrorMonitor
    {
        return $this->errorMonitor;
    }

    public function getPerformanceMonitor(): SentryPerformanceMonitor
    {
        return $this->performanceMonitor;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Circuit Breaker — Cache-backed (multi-process safe) با fallback فایلی
    // ──────────────────────────────────────────────────────────────────────────

    private function isCircuitOpen(): bool
    {
        // --- مسیر ۱: Cache-backed (Redis/APCu) ---
        if ($this->cache !== null) {
            try {
                $failures    = int_value($this->cache->get(self::CB_CACHE_KEY, 0));
                $lastFailure = int_value($this->cache->get(self::CB_TS_KEY, 0));

                if ($failures >= self::CB_THRESHOLD) {
                    $elapsed = time() - $lastFailure;
                    if ($elapsed < self::CB_COOLDOWN_SECS) {
                        return true;
                    }
                    // cooldown منقضی شده — reset
                    $this->cache->delete(self::CB_CACHE_KEY);
                    $this->cache->delete(self::CB_TS_KEY);
                }
                return false;
            } catch (\Throwable) {
                // cache در دسترس نیست — fallback به فایل
            }
        }

        // --- مسیر ۲: Fallback فایل‌محور ---
        return $this->isCircuitOpenFile();
    }

    private function recordFailure(): void
    {
        // --- مسیر ۱: Cache-backed ---
        if ($this->cache !== null) {
            try {
                $this->cache->increment(self::CB_CACHE_KEY, 1);
                // TTL روی کلید counter (60s cooldown)
                $this->cache->set(self::CB_TS_KEY, time(), self::CB_COOLDOWN_SECS + 5);
                return;
            } catch (\Throwable) {
                // cache در دسترس نیست — fallback به فایل با flock(LOCK_EX)
            }
        }

        // --- مسیر ۲: Fallback فایل‌محور با flock(LOCK_EX) atomic ---
        $this->recordFailureFile();
    }

    // ─── Fallback: پیاده‌سازی فایل‌محور (نگه داشته شده برای محیط‌های بدون cache) ───

    private function getCircuitBreakerPath(): string
    {
        return sys_get_temp_dir() . '/sentry_circuit_breaker.json';
    }

    private function isCircuitOpenFile(): bool
    {
        $file = $this->getCircuitBreakerPath();
        if (!file_exists($file)) {
            return false;
        }

        $data = $this->readCircuitFile($file);
        if (!$data) {
            return false;
        }

        $elapsed = time() - ($data['last_failure'] ?? 0);

        if ($data['failures'] >= self::CB_THRESHOLD && $elapsed < self::CB_COOLDOWN_SECS) {
            return true;
        }

        if ($elapsed >= self::CB_COOLDOWN_SECS) {
            @unlink($file);
        }

        return false;
    }

    private function recordFailureFile(): void
    {
        $file   = $this->getCircuitBreakerPath();
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            $content  = stream_get_contents($handle);
            $data     = $content ? (array)(json_decode($content, true) ?? []) : null;
            $failures = is_array($data) ? ($data['failures'] ?? 0) + 1 : 1;

            fseek($handle, 0);
            ftruncate($handle, 0);
            fwrite($handle, (string)json_encode([
                'failures'     => $failures,
                'last_failure' => time(),
            ]));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed>|null */
    /**
     * @return array<string, mixed>|null
     */
    private function readCircuitFile(string $file): ?array
    {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) {
                return null;
            }

            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);

            if (!$content) {
                return null;
            }
            /** @var array<string, mixed> $decoded */
            $decoded = (array)(json_decode($content, true) ?? []);
            return $decoded;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 🎯 Encapsulated Static Helper Interfaces for System Logging
     */

    /**
     * Safe accessor — هرگز exception پرتاب نمی‌کند.
     * اگر instance نساخته باشد (bootstrap fail)، null برمی‌گرداند.
     */
    private static function safeInstance(): ?self
    {
        if (self::$instance === null) {
            @error_log('[Sentry] Handler not initialized — skipping capture.');
            return null;
        }
        return self::$instance;
    }

    /** @param array<string, mixed> $context */
    public static function captureException(\Throwable $exception, ?int $userId = null, array $context = []): ?string
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return null;
        try {
            return $handler->getErrorMonitor()->captureException($exception, $context, 'error', $userId);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    /** @param array<string, mixed> $context */
    public static function captureMessage(string $message, string $level = 'info', ?int $userId = null, array $context = []): ?string
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return null;
        try {
            return $handler->getErrorMonitor()->captureMessage($message, $level, $context, $userId);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    public static function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return;
        try {
            $handler->getErrorMonitor()->addBreadcrumb($message, $category, $level, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }

    /** @param array<string, mixed> $data */
    public static function startTransaction(string $name, string $op = 'http.request', array $data = []): ?string
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return null;
        try {
            return $handler->getPerformanceMonitor()->startTransaction($name, $op, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    public static function startSpan(string $op, string $description, array $data = []): string
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return '';
        try {
            return $handler->getPerformanceMonitor()->startSpan($op, $description, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return '';
        }
    }

    /** @param array<string, mixed> $data */
    public static function finishSpan(string $spanId, array $data = []): void
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return;
        try {
            $handler->getPerformanceMonitor()->finishSpan($spanId, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }

    /** @param array<int|string, mixed>|null $params */
    public static function trackQuery(string $query, float $duration, ?array $params = null): void
    {
        $handler = self::safeInstance();
        if ($handler === null || $handler->isCircuitOpen()) return;
        try {
            $handler->getPerformanceMonitor()->trackQuery($query, $duration, $params);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }
}


