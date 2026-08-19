<?php

namespace App\Services;

use Core\Cache;
use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * Distributed Lock Service
 * 
 * سرویس قفل توزیع‌شده برای محیط‌های multi-server
 * استفاده از Redis برای اطمینان از اجرای atomic
 */
class DistributedLockService
{
    private int $defaultTTL = 30; // ثانیه
    
    private \Core\Cache $cache;
    private \Core\PathResolver $paths;
    private ?Database $db;
    public function __construct(\Core\Cache $cache, \Core\PathResolver $paths, ?Database $db = null)
    {
        $this->cache = $cache;
        $this->paths = $paths;
        $this->db = $db;
    }
    
    /**
     * اخذ قفل
     * 
     * @param string $resource نام resource که می‌خواهیم قفل کنیم
     * @param int $ttl مدت زمان قفل (ثانیه)
     * @param int $waitTimeout زمان انتظار برای اخذ قفل (ثانیه)
     * @return array<string, mixed> ['acquired' => bool, 'token' => string|null]
     */
    /**
     * @return array{acquired: bool, token: string|null, expires_at?: int, reason?: string}
     */
    public function acquire(string $resource, ?int $ttl = null, int $waitTimeout = 0, bool $failClosed = false): array
    {
        $ttl = $ttl ?? $this->defaultTTL;
        $token = $this->generateToken();
        $key = $this->getLockKey($resource);
        
        $startTime = time();
        
        while (true) {
            // تلاش برای اخذ قفل
            if ($this->tryAcquire($key, $token, $ttl, $failClosed)) {
                // M-26 FIX: alongside the (random) ownership token we now issue a monotonically
                // increasing FENCING token. The ownership token only proves "I hold the lock right
                // now" for release/extend; it cannot protect against a process that stalled past the
                // lock TTL, had the lock auto-expire, and then resumes writing while a newer holder
                // is active. A fencing token lets the protected resource reject the stale writer by
                // persisting the highest fence it has seen and refusing any lower one.
                try {
                    $fence = $this->nextFenceToken($resource);
                } catch (\Throwable $e) {
                    // Ownership without a durable fence is unsafe. Release the
                    // Redis/file lock immediately so no orphan lock remains.
                    $this->release($resource, $token);
                    throw $e;
                }
                return [
                    'acquired' => true,
                    'token' => $token,
                    'fence' => $fence,
                    'expires_at' => time() + $ttl,
                ];
            }
            
            // بررسی timeout
            if (time() - $startTime >= $waitTimeout) {
                return [
                    'acquired' => false,
                    'token' => null,
                    'fence' => null,
                    'reason' => 'timeout',
                ];
            }
            
            // صبر کوتاه قبل از تلاش مجدد (100ms)
            usleep(100000);
        }
    }
    
    /**
     * تلاش برای اخذ قفل (atomic)
     *
     * اولویت: Redis → File-based fallback
     *
     * File-based fallback در single-server ایمن است (flock بین process‌ها کار می‌کند).
     * در multi-server ایمن نیست ولی بهتر از کرش کردن کل سیستم مالی است.
     *
     * وقتی Redis در دسترس نیست، CRITICAL لاگ می‌شود تا اپراتور مطلع شود،
     * ولی عملیات ادامه می‌یابد (graceful degradation).
     */
    private function tryAcquire(string $key, string $token, int $ttl, bool $failClosed = false): bool
    {
        if ($this->cache->driver() === 'redis') {
            return $this->tryAcquireRedis($key, $token, $ttl);
        }

        // ── Redis unavailable ─────────────────────────────────────────────
        // M-26 policy: the file-based fallback is only safe on a single server.
        // On a multi-server deployment it silently grants a fake lock (fail-open),
        // which for financial resources can allow concurrent double-spend. Callers
        // that protect money MUST pass $failClosed=true (or set lock.fail_closed);
        // in that case we refuse the unsafe lock instead of degrading silently.
        if ($failClosed || (bool) config('lock.fail_closed', false)) {
            error_log(sprintf(
                '[CRITICAL] DistributedLockService: Redis unavailable and lock is fail-closed. '
                . 'Refusing unsafe file-based lock for key: %s.',
                $key
            ));
            throw new \RuntimeException(
                'Distributed lock backend (Redis) is unavailable; refusing to run a fail-closed critical section without a reliable lock.'
            );
        }

        // ── loud warning + graceful (single-server) fallback ──────────────
        error_log(sprintf(
            '[CRITICAL] DistributedLockService: Redis unavailable! '
            . 'Falling back to file-based lock for key: %s. '
            . 'File-based lock is safe on single-server but NOT on multi-server deployments.',
            $key
        ));

        return $this->tryAcquireFile($key, $token, $ttl);
    }
    
    /**
     * اخذ قفل با Redis (atomic با SET NX EX)
     */
    private function tryAcquireRedis(string $key, string $token, int $ttl): bool
    {
        $redis = $this->cache->redis();
        
        if (!$redis instanceof \Redis) return false;
        // استفاده از SET NX EX برای atomic operation
        $result = $redis->set($key, $token, ['NX', 'EX' => $ttl]);
        
        return $result === true;
    }
    
    /**
     * اخذ قفل با File (fallback هنگام Redis unavailable)
     *
     * ── TOCTOU Fix ────────────────────────────────────────────────────────────
     * مشکل قبلی: بعد از fwrite، فوراً LOCK_UN می‌دادیم.
     * این window ایجاد می‌کرد که پروسه دیگری فایل را open کرده و lock بگیرد
     * درحالی‌که ما هنوز داریم از lock استفاده می‌کنیم.
     *
     * راه‌حل: flock را فقط با fclose رها می‌کنیم — PHP/OS به‌صورت atomic
     * هر دو را با هم انجام می‌دهد. LOCK_UN جداگانه حذف شد.
     *
     * ── چرا fclose به‌تنهایی کافی است؟ ─────────────────────────────────────
     * طبق مستندات PHP: "All locks are released when the process terminates
     * OR the file handle is closed." — POSIX هر دو را atomic می‌داند.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function tryAcquireFile(string $key, string $token, int $ttl): bool
    {
        $lockDir = rtrim($this->paths->storage('locks'), '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $lockFile = $lockDir . md5($key) . '.lock';

        // 'c+' = open for r/w, create if not exists, don't truncate
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        // LOCK_NB = non-blocking: اگر دیگری lock دارد فوری false برگرداند
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        // از اینجا تا fclose هیچ پروسه دیگری نمی‌تواند lock بگیرد
        try {
            rewind($fp);
            $content = stream_get_contents($fp);
            $data = !empty($content) ? (array)(json_decode($content, true) ?? []) : null;

            // اگر lock فعال (منقضی‌نشده) وجود دارد → شکست
            if (is_array($data)
                && isset($data['expires_at'])
                && $data['expires_at'] >= time()
            ) {
                // LOCK_UN + fclose به‌صورت atomic توسط OS
                fclose($fp);
                return false;
            }

            // lock آزاد یا منقضی‌شده — اطلاعات جدید می‌نویسیم
            ftruncate($fp, 0);
            rewind($fp);

            fwrite($fp, (string)json_encode([
                'token'      => $token,
                'expires_at' => time() + $ttl,
                'created_at' => time(),
                'pid'        => getmypid(),
            ]));
            fflush($fp);

            // ── نقطه حیاتی ──────────────────────────────────────────────────
            // LOCK_UN جداگانه حذف شد. fclose به‌تنهایی lock را آزاد می‌کند.
            // این از TOCTOU جلوگیری می‌کند: هیچ window‌ای بین نوشتن و آزاد کردن نیست.
            fclose($fp);

            return true;

        } catch (\Throwable $e) {
            // در صورت هر خطایی fclose lock را آزاد می‌کند
            fclose($fp);
            return false;
        }
    }
    
    /**
     * آزاد کردن قفل
     */
    public function release(string $resource, string $token): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            return $this->releaseRedis($key, $token);
        }

        // Redis unavailable → file-based fallback + loud warning
        error_log(sprintf(
            '[CRITICAL] DistributedLockService::release: Redis unavailable! '
            . 'Falling back to file-based release for key: %s.',
            $key
        ));
        
        return $this->releaseFile($key, $token);
    }
    
    /**
     * آزاد کردن قفل با Redis (با Lua script برای atomic)
     */
    private function releaseRedis(string $key, string $token): bool
    {
        $redis = $this->cache->redis();
        if (!$redis instanceof \Redis) return false;
        // Lua script برای بررسی token و حذف atomic
        $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;
        
        $result = $redis->eval($script, [$key, $token], 1);
        
        return $result === 1;
    }
    
    /**
     * آزاد کردن قفل با File
     *
     * ── TOCTOU Fix ────────────────────────────────────────────────────────────
     * مشکل قبلی: LOCK_UN + fclose قبل از unlink → پروسه دیگری می‌توانست
     * بین fclose و unlink فایل را lock کند و lock جدید بگیرد.
     *
     * راه‌حل: فایل را truncate می‌کنیم (محتوا حذف می‌شود) و سپس مستقیم
     * fclose می‌دهیم. unlink بعد از fclose انجام می‌شود.
     * پروسه‌ای که بعد از unlink فایل را open کند، یک فایل جدید خالی
     * می‌بیند و lock جدیدی نیست — رفتار صحیح است.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function releaseFile(string $key, string $token): bool
    {
        $lockDir = rtrim($this->paths->storage('locks'), '/\\') . DIRECTORY_SEPARATOR;
        $lockFile = $lockDir . md5($key) . '.lock';

        if (!file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        // blocking lock — صبر می‌کنیم تا صاحب واقعی کنترل داشته باشیم
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        $data = !empty($content) ? (array)(json_decode($content, true) ?? []) : null;

        if (is_array($data) && isset($data['token']) && $data['token'] === $token) {
            // محتوا را پاک می‌کنیم تا پروسه دیگری که lock می‌گیرد فایل خالی ببیند
            ftruncate($fp, 0);
            fflush($fp);
            // fclose به‌صورت atomic lock را آزاد می‌کند
            fclose($fp);
            // حالا فایل را حذف می‌کنیم — هر پروسه‌ای که بعد از این fopen کند
            // یک فایل جدید می‌سازد و lock جدیدی ایجاد می‌شود (رفتار صحیح)
            @unlink($lockFile);
            return true;
        }

        // token مطابقت ندارد — lock متعلق به ما نیست
        fclose($fp);
        return false;
    }
    
    /**
     * تمدید قفل
     */
    public function extend(string $resource, string $token, int $additionalTTL): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            if (!$redis instanceof \Redis) return false;
            // Lua script برای بررسی token و تمدید
            $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("EXPIRE", KEYS[1], ARGV[2])
else
    return 0
end
LUA;
            
            $result = $redis->eval($script, [$key, $token, $additionalTTL], 1);
            
            return $result === 1;
        }

        // Redis unavailable → file-based fallback + loud warning
        error_log(sprintf(
            '[CRITICAL] DistributedLockService::extend: Redis unavailable! '
            . 'Falling back to file-based extend for key: %s.',
            $key
        ));
        
        return $this->extendFile($key, $token, $additionalTTL);
    }

    /**
     * File-based extend (فقط development)
     */
    private function extendFile(string $key, string $token, int $additionalTTL): bool
    {
        $lockDir = rtrim($this->paths->storage('locks'), '/\\') . DIRECTORY_SEPARATOR;
        $lockFile = $lockDir . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        $data = !empty($content) ? (array)(json_decode($content, true) ?? []) : null;

        if (is_array($data) && isset($data['token']) && $data['token'] === $token) {
            $data['expires_at'] = intval($data['expires_at']) + $additionalTTL;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($data));
            fflush($fp);
            // fclose به‌تنهایی lock را atomic آزاد می‌کند (TOCTOU Fix)
            fclose($fp);
            return true;
        }

        fclose($fp);
        return false;
    }

    /**
     * بررسی وضعیت قفل
     */
    public function isLocked(string $resource): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            if (!$redis instanceof \Redis) return false;
            $exists = $redis->exists($key);
            return is_int($exists) ? $exists > 0 : (bool) $exists;
        }

        // Redis unavailable → file-based fallback + loud warning
        error_log(sprintf(
            '[CRITICAL] DistributedLockService::isLocked: Redis unavailable! '
            . 'Falling back to file-based check for key: %s.',
            $key
        ));
        
        // File-based check
        $lockDir = rtrim($this->paths->storage('locks'), '/\\') . DIRECTORY_SEPARATOR;
        $lockFile = $lockDir . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        $data = !empty($content) ? (array)(json_decode($content, true) ?? []) : null;

        if (is_array($data) && isset($data['expires_at']) && $data['expires_at'] >= time()) {
            fclose($fp);
            return true;
        }

        // lock منقضی‌شده یا خالی — فایل را پاکسازی می‌کنیم
        ftruncate($fp, 0);
        fflush($fp);
        fclose($fp);
        @unlink($lockFile);
        return false;
    }
    
    /**
     * اجرای عملیات با قفل
     * 
     * @param string $resource نام resource
     * @template T
     * @param callable(mixed=): T $callback تابعی که باید با قفل اجرا شود
     * @param int $ttl مدت قفل
     * @param int $waitTimeout زمان انتظار
     * @return T نتیجه callback
     */
    public function synchronized(string $resource, callable $callback, ?int $ttl = null, int $waitTimeout = 5, bool $failClosed = false)
    {
        $lock = $this->acquire($resource, $ttl, $waitTimeout, $failClosed);
        
        if (!$lock['acquired']) {
            throw new \RuntimeException("Failed to acquire lock for resource: {$resource}");
        }
        
        $token = $lock['token'];
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException("Lock for resource {$resource} was acquired without a release token");
        }

        // M-26: pass the fencing token to the callback so the ACTUAL protected
        // write can enforce it at commit time via guardFence()/currentFence().
        // We intentionally do NOT auto-enforce the fence here: the write, not the
        // lock acquisition, is the point that must reject a stale holder, and the
        // monotonic counter can legitimately reset scale during a Redis outage,
        // which would make an acquisition-time guard throw false positives on
        // healthy financial operations.
        $fence = int_value($lock['fence'] ?? 0);
        try {
            $ref = new \ReflectionFunction(\Closure::fromCallable($callback));
            return $ref->getNumberOfParameters() > 0 ? $callback($fence) : $callback();
        } finally {
            $this->release($resource, $token);
        }
    }
    
    /**
     * تولید token یکتا
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * M-26: produce a strictly increasing fencing token for a lock resource.
     *
     * Uses an atomic counter (Redis INCR via Cache::increment, which is also atomic on the file
     * driver) so every successful acquisition observes a value strictly greater than any prior
     * holder's. Callers that mutate shared state should persist the highest fence they have
     * applied and reject writes carrying a lower/equal fence — that is what actually neutralises a
     * stalled, TTL-expired holder. Falls back to a high-resolution timestamp only if the counter
     * backend is unavailable, preserving monotonicity best-effort rather than crashing the lock.
     */
    private function nextFenceToken(string $resource): int
    {
        if ($this->db !== null) {
            $hash = hash('sha256', $resource);
            try {
                $this->db->query(
                    "INSERT INTO distributed_lock_fences (resource_hash,resource_name,next_fence,applied_fence,updated_at)
                     VALUES (?, ?, LAST_INSERT_ID(1), 0, NOW())
                     ON DUPLICATE KEY UPDATE next_fence=LAST_INSERT_ID(next_fence+1), resource_name=VALUES(resource_name), updated_at=NOW()",
                    [$hash, mb_substr($resource, 0, 255)]
                );
                $value = (int)$this->db->lastInsertId();
                if ($value <= 0) {
                    $value = (int)$this->db->fetchColumn('SELECT next_fence FROM distributed_lock_fences WHERE resource_hash=?', [$hash]);
                }
                if ($value > 0) return $value;
            } catch (\Throwable $e) {
                error_log('[CRITICAL] DistributedLockService durable fence allocation failed: ' . $e->getMessage());
                throw new \RuntimeException('Durable fencing backend is unavailable; refusing unsafe distributed lock.', 0, $e);
            }
        }

        // Compatibility for explicitly constructed single-process instances.
        $counterKey = config('redis.prefix', 'chortke') . ':lockfence';
        try {
            $value = $this->cache->increment($counterKey, 1, 0);
            if (is_int($value) && $value > 0) return $value;
        } catch (\Throwable $e) {
            error_log('[WARN] DistributedLockService::nextFenceToken counter unavailable: ' . $e->getMessage());
        }
        return (int)round(microtime(true) * 1000000);
    }

    /**
     * M-26: enforce the per-resource fencing policy.
     *
     * Atomically records the highest fence token ever presented for $resource and
     * accepts the write ONLY when $fence is greater than or equal to the stored
     * high-water mark. A stalled, TTL-expired holder that resumes with an older
     * fence is rejected because a newer holder has already advanced the mark. This
     * is the actual protection against a stale owner writing over a newer writer.
     *
     * On Redis this is a single atomic compare-and-set-max (Lua). On the file
     * fallback it uses an flock-guarded read/write. If the backend is entirely
     * unavailable it fails OPEN (returns true) so single-server deployments keep
     * working; multi-server safety there is already covered by the fail-closed
     * acquire policy.
     *
     * @return bool true if $fence is the newest (write allowed), false if stale.
     */
    public function guardFence(string $resource, int $fence): bool
    {
        if ($fence <= 0) return false;
        if ($this->db !== null) {
            $hash = hash('sha256', $resource);
            try {
                $this->db->execute(
                    'UPDATE distributed_lock_fences SET applied_fence=?, updated_at=NOW() WHERE resource_hash=? AND next_fence>=? AND applied_fence<=?',
                    [$fence, $hash, $fence, $fence]
                );
                $applied = (int)$this->db->fetchColumn('SELECT applied_fence FROM distributed_lock_fences WHERE resource_hash=?', [$hash]);
                return $applied === $fence;
            } catch (\Throwable $e) {
                error_log('[CRITICAL] DistributedLockService durable fence guard failed: ' . $e->getMessage());
                return false;
            }
        }

        $key = $this->getFenceKey($resource);

        if ($this->cache->driver() === 'redis') {
            try {
                $redis = $this->cache->redis();
                if ($redis !== null) {
                    $script = <<<LUA
local cur = tonumber(redis.call("GET", KEYS[1]) or "0")
local fence = tonumber(ARGV[1])
if fence >= cur then
    redis.call("SET", KEYS[1], fence)
    return 1
else
    return 0
end
LUA;
                    $result = $redis->eval($script, [$key, $fence], 1);
                    return is_int($result) && $result === 1;
                }
            } catch (\Throwable $e) {
                error_log('[WARN] DistributedLockService::guardFence redis unavailable: ' . $e->getMessage());
            }
        }

        return $this->guardFenceFile($key, $fence);
    }

    /**
     * M-26: read the current fence high-water mark for a resource (0 if none).
     */
    public function currentFence(string $resource): int
    {
        if ($this->db !== null) {
            try {
                return (int)$this->db->fetchColumn(
                    'SELECT applied_fence FROM distributed_lock_fences WHERE resource_hash=?',
                    [hash('sha256', $resource)]
                );
            } catch (\Throwable) {
                return 0;
            }
        }

        $key = $this->getFenceKey($resource);

        if ($this->cache->driver() === 'redis') {
            try {
                $redis = $this->cache->redis();
                if ($redis !== null) {
                    $val = $redis->get($key);
                    return is_numeric($val) ? (int) $val : 0;
                }
            } catch (\Throwable $e) {
                error_log('[WARN] DistributedLockService::currentFence redis unavailable: ' . $e->getMessage());
            }
        }

        $file = $this->getFenceFilePath($key);
        if (!file_exists($file)) {
            return 0;
        }
        $raw = @file_get_contents($file);
        return is_string($raw) && is_numeric(trim($raw)) ? (int) trim($raw) : 0;
    }

    /**
     * File-based compare-and-set-max for the fence high-water mark.
     */
    private function guardFenceFile(string $key, int $fence): bool
    {
        $file = $this->getFenceFilePath($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            // Cannot persist the fence — fail OPEN on single-server.
            return true;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        try {
            rewind($fp);
            $content = stream_get_contents($fp);
            $cur = is_string($content) && is_numeric(trim($content)) ? (int) trim($content) : 0;

            if ($fence < $cur) {
                fclose($fp);
                return false;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $fence);
            fflush($fp);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            fclose($fp);
            return true;
        }
    }

    /**
     * Cache/Redis key holding the fence high-water mark for a resource.
     */
    private function getFenceKey(string $resource): string
    {
        $prefix = config('redis.prefix', 'chortke');
        return $prefix . ':fence:' . $resource;
    }

    /**
     * File path backing the fence high-water mark when Redis is unavailable.
     */
    private function getFenceFilePath(string $key): string
    {
        $dir = rtrim($this->paths->storage('locks/fence'), '/\\') . DIRECTORY_SEPARATOR;
        return $dir . md5($key) . '.fence';
    }
    
    /**
     * دریافت کلید کامل قفل
     */
    private function getLockKey(string $resource): string
    {
        $prefix = config('redis.prefix', 'chortke');
        return $prefix . ':lock:' . $resource;
    }
    
    /**
     * پاکسازی قفل‌های منقضی‌شده (برای file-based locks)
     */
    public function cleanup(): int
    {
        if ($this->cache->driver() === 'redis') {
            return 0; // Redis خودش TTL می‌زنه
        }
        
        $lockDir = rtrim($this->paths->storage('locks'), '/\\') . DIRECTORY_SEPARATOR;
        
        if (!is_dir($lockDir)) {
            return 0;
        }
        
        $cleaned = 0;
        $files = glob($lockDir . '*.lock') ?: [];
        $now = time();
        
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            
            $fp = @fopen($file, 'c+');
            if (!$fp) {
                continue;
            }
            
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                continue;
            }
            
            $content = stream_get_contents($fp);
            $data = !empty($content) ? (array)(json_decode($content, true) ?? []) : null;
            
            if (is_array($data) && isset($data['expires_at']) && $data['expires_at'] < $now) {
                ftruncate($fp, 0);
                fflush($fp);
                fclose($fp); // atomic: lock آزاد + فایل بسته
                @unlink($file);
                $cleaned++;
            } else {
                fclose($fp); // atomic: lock آزاد + فایل بسته
            }
        }
        
        return $cleaned;
    }
    
    /**
     * مثال استفاده:
     * 
     * // روش 1: Manual
     * $lock = $lockService->acquire('payment:user:123', 10);
     * if ($lock['acquired']) {
     *     try {
     *         // انجام عملیات
     *         processPayment($userId);
     *     } finally {
     *         $lockService->release('payment:user:123', $lock['token']);
     *     }
     * }
     * 
     * // روش 2: Synchronized (توصیه می‌شود)
     * $result = $lockService->synchronized('payment:user:123', function() use ($userId) {
     *     return processPayment($userId);
     * });
     */
}

