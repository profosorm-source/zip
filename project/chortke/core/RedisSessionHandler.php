<?php

declare(strict_types=1);
namespace Core;

/**
 * Redis Session Handler
 * 
 * مدیریت Session با Redis + Fallback به File
 * خودکار بین Redis و فایل سوئیچ می‌کند
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    private ?\Redis $redis = null;
    private static bool $hasFailed = false; // H13 Fix: جلوگیری از تلاش مجدد در طول کل حیات این پروسس (مخصوصاً در CLI)
    private string $prefix = 'chortke:session:';
    private int $ttl = 7200; // 2 hours default — overridden from config in __construct
    private bool $slidingTtl = true; // 🛡️ FIX P2: refresh TTL on every successful read (active users stay logged in)
    private string $savePath = '';

    public function __construct(
        ?Cache $cache = null,
        ?string $sessionDriver = null,
        ?PathResolver $paths = null
    ) {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $this->savePath = ($paths ?? new PathResolver($basePath))->storage('sessions');
        $sessionDriver ??= config('session.driver', 'redis');
        if (!is_string($sessionDriver) || !in_array($sessionDriver, ['redis', 'file'], true)) {
            throw new \InvalidArgumentException("Session driver must be either 'redis' or 'file'.");
        }

        $this->tryConnectRedis($cache ?? Cache::getInstance(), $sessionDriver);

        $rawTtl = config('session.lifetime', 7200);
        if (is_int($rawTtl)) {
            $ttl = $rawTtl;
        } elseif (is_string($rawTtl) && ctype_digit($rawTtl)) {
            $ttl = (int)$rawTtl;
        } else {
            throw new \InvalidArgumentException('Session lifetime must be a positive integer number of seconds.');
        }
        if ($ttl < 1 || $ttl > 31_536_000) {
            throw new \InvalidArgumentException('Session lifetime must be between 1 and 31536000 seconds.');
        }
        $this->ttl = $ttl;

        // 🛡️ FIX P2: sliding TTL keeps active sessions alive; idle sessions expire after TTL.
        // Disable via SESSION_SLIDING_TTL=false for stricter security policies (e.g. admin sessions).
        $this->slidingTtl = filter_var(
            env('SESSION_SLIDING_TTL', config('session.sliding_ttl', true)),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private function tryConnectRedis(Cache $cache, string $sessionDriver): void
    {
        // H13 Fix: اگر قبلاً فیل شده، مستقیماً برو روی فایل
        if (self::$hasFailed) {
            return;
        }

        if ($sessionDriver !== 'redis') {
            // File sessions are an intentional configured mode, not an operational event.
            return;
        }

        $redis = $cache->redis();
        if ($redis !== null) {
            try {
                $redis->ping();
                $this->redis = $redis;

                // A successful normal connection is deliberately not logged per request/process.
            } catch (\Throwable $e) {
                self::$hasFailed = true;
                    if (function_exists('logger')) {
                    try {
                        logger()->critical('Session handler: Redis connection failed on production. Falling back to file-based sessions to prevent complete system outage.', ['error' => $e->getMessage()]);
                    } catch (\Throwable $ignore) {}
                }
                }
        } else {
            $this->redis = null;

            // Cache driver fallback is normal in non-Redis environments; only failures log critically.
        }
    }

    public function open(string $path, string $name): bool
    {
        if ($this->redis === null) {
            if (!is_dir($this->savePath)) {
                // L-18 Fix: مجوز محدود 0700 به‌جای 0755 (فقط مالک) برای پوشهٔ session
                mkdir($this->savePath, 0700, true);
            }
        }
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->isValidSessionId($id)) {
            return '';
        }
        if ($this->redis !== null) {
            try {
                $key = $this->prefix . $id;
                $data = $this->redis->get($key);

                // 🛡️ FIX P2 (Sliding TTL): اگر session فعال است، TTL را refresh کن
                // این کار باعث می‌شود کاربران فعال logout نشوند ولی sessionهای idle منقضی شوند
                // فقط در صورت فعال بودن SESSION_SLIDING_TTL اعمال می‌شود
                // L-18 Fix: سقف مطلق عمر session؛ TTL لغزان فقط تا وقتی نشانگر created موجود
                // است refresh می‌شود تا session فعال هم بی‌نهایت زنده نماند.
                if ($this->slidingTtl && $data !== false) {
                    $withinAbsolute = true;
                    try {
                        $withinAbsolute = (bool) $this->redis->exists($key . ':created');
                    } catch (\Throwable $absEx) {
                        $withinAbsolute = true; // در تردید، رفتار قبلی حفظ می‌شود
                    }
                    if ($withinAbsolute) {
                        try {
                            $this->redis->expire($key, $this->ttl);
                        } catch (\Throwable $ttlEx) {
                            // TTL refresh failure should not break session read
                        }
                    }
                }

                return ($data === false) ? '' : str_value($data);
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileRead($id);
            }
        }

        return $this->fileRead($id);
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->isValidSessionId($id)) {
            return false;
        }
        if ($this->redis !== null) {
            try {
                // L-18 Fix: نشانگر created را یک‌بار (NX) با TTL برابر سقف مطلق عمر session ثبت می‌کنیم.
                // وقتی این نشانگر منقضی شود، TTL لغزان دیگر refresh نمی‌شود و session نهایتاً منقضی می‌گردد.
                try {
                    $absoluteMax = int_value(config('session.absolute_lifetime', 86400));
                    if ($absoluteMax > 0) {
                        $this->redis->set($this->prefix . $id . ':created', (string)time(), ['NX', 'EX' => $absoluteMax]);
                    }
                } catch (\Throwable $createdEx) {
                    // ثبت نشانگر created حیاتی نیست
                }
                return (bool) $this->redis->setEx(
                    $this->prefix . $id,
                    $this->ttl,
                    $data
                );
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileWrite($id, $data);
            }
        }

        return $this->fileWrite($id, $data);
    }

    public function destroy(string $id): bool
    {
        if (!$this->isValidSessionId($id)) {
            return false;
        }
        if ($this->redis !== null) {
            try {
                $this->redis->del($this->prefix . $id);
                $this->redis->del($this->prefix . $id . ':created');
                return true;
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileDestroy($id);
            }
        }

        return $this->fileDestroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->redis !== null) {
            // Redis automatically handles TTL
            return 0;
        }

        return $this->fileGc($max_lifetime);
    }

    // ─────────────────────────────────────────────────
    //  File Fallback Methods
    // ─────────────────────────────────────────────────

    private function fileRead(string $id): string
    {
        $file = $this->getFilePath($id);
        if (!file_exists($file)) {
            return '';
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return '';
        }

        return $data;
    }

    private function fileWrite(string $id, string $data): bool
    {
        $file = $this->getFilePath($id);
        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    private function fileDestroy(string $id): bool
    {
        $file = $this->getFilePath($id);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    private function fileGc(int $max_lifetime): int
    {
        $files = glob($this->savePath . '/sess_*') ?: [];
        $now = time();
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file) > $max_lifetime)) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function getFilePath(string $id): string
    {
        // L-18 Fix: اعتبارسنجی سخت‌گیرانهٔ شناسهٔ session برای جلوگیری از path traversal
        if (!$this->isValidSessionId($id)) {
            throw new \InvalidArgumentException('Invalid session id');
        }
        return $this->savePath . '/sess_' . $id;
    }

    /**
     * L-18 Fix: فقط شناسه‌های session با الگوی مجاز پذیرفته می‌شوند (دفاع در برابر
     * path traversal در مسیر فایل و آلودگی کلید در Redis).
     */
    private function isValidSessionId(string $id): bool
    {
        return $id !== '' && preg_match('/^[A-Za-z0-9,\-]{1,128}$/', $id) === 1;
    }

    private function fallbackToFile(\Throwable $e): void
    {
        self::$hasFailed = true; // ثبت وضعیت خرابی سیستمی برای بقیه درخواست یا لوپ
        
        if ($this->redis !== null) {
            $this->redis = null;

            if (function_exists('logger')) {
                try {
                    logger()->critical('Redis session store connection was lost. Downgrading to file session handler in production to maintain availability.', [
                        'channel' => 'session',
                        'error' => $e->getMessage()
                    ]);
                } catch (\Throwable $ignore) {}
            }

            // Initialize file path securely with strict permissions in production
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0700, true);
            }
        }
    }

    /**
     * Get current driver
     */
    public function driver(): string
    {
        return $this->redis !== null ? 'redis' : 'file';
    }
}
