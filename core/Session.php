<?php

declare(strict_types=1);
namespace Core;

class Session
{
    private static ?Session $instance = null;
    private bool $started = false;
    private bool $isStarting = false;
    private bool $startFailed = false;
    private ?array $oldInputCache = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure session is started (call this before accessing $_SESSION)
     */
    public function ensureStarted(): void
    {
        $this->start();
    }

    /**
     * شروع امن Session (تنها نقطه session_start)
     */
    public function start(): void
    {
        if ($this->started || $this->isStarting || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($this->startFailed) {
            throw new \RuntimeException('Session start previously failed; refusing retry to prevent corrupt state.');
        }

        $this->isStarting = true;
        try {
            $config = config('session');
            $headersSent = headers_sent();
            $isCli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);

            if ($headersSent && !$isCli) {
                throw new \RuntimeException('Session cannot be started after headers have already been sent.');
            }

            // CORE-033: Enforce strict mode and safe cookie settings
            if (!$headersSent && !$isCli) {
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_httponly', '1');
                
                $sessionsDir = __DIR__ . '/../storage/sessions';
                if (!is_dir($sessionsDir)) {
                    @mkdir($sessionsDir, 0755, true);
                }
                session_save_path($sessionsDir);
            }

            // Set Redis session handler if headers are still writable.
            $handler = new \Core\RedisSessionHandler();
            if (!$headersSent && !$isCli) {
                session_set_save_handler($handler, true);
                
                // 🛡️ SECURITY FIX: جداسازی فیزیکی کوکی‌های ادمین و کاربر عادی
                // جلوگیری از سرقت اکانت و تداخل نشست‌ها در برابر حملات جعل نشست
                $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                $isAdminPath = str_starts_with($requestUri, '/admin');
                $baseSessionName = $this->resolveSessionName($config['name'] ?? null);
                $sessionName = $isAdminPath ? 'CHORTKE_ADM_SESS' : $baseSessionName;

                session_name($sessionName);

                // H12 Fix: جلوگیری از ست شدن نامعتبر دامین در localhost و محافظت در برابر پارس نادرست
                $host = parse_url(config('app.url', ''), PHP_URL_HOST);
                $cookieDomain = $host && $host !== 'localhost' ? $host : '';

                $lifetime = $this->resolveLifetime($config['lifetime'] ?? null);
                $secure = $this->resolveBooleanConfig($config['secure'] ?? false, 'session.secure');
                $httpOnly = $this->resolveBooleanConfig($config['httponly'] ?? true, 'session.httponly');
                $sameSite = $this->resolveSameSite($config['samesite'] ?? null);

                session_set_cookie_params([
                    'lifetime' => $lifetime,
                    'path'     => '/',
                    'domain'   => $cookieDomain,
                    'secure'   => $secure,
                    'httponly' => $httpOnly,
                    'samesite' => $sameSite,
                ]);
            }

            if ($isCli || $headersSent) {
                if (!isset($_SESSION)) {
                    $_SESSION = [];
                }
                if (!isset($_SESSION['_initiated'])) {
                    $_SESSION['_initiated'] = true;
                }
                if (!isset($_SESSION['_session_id'])) {
                    $_SESSION['_session_id'] = bin2hex(random_bytes(16));
                }

                $this->started = true;
                $this->validateFingerprint();
                return;
            }

            session_start();
            $this->started = true;

            if (!isset($_SESSION['_initiated'])) {
                session_regenerate_id(true);
                $_SESSION['_initiated'] = true;
                $_SESSION['_last_regenerate'] = time();
            } elseif (time() - ($_SESSION['_last_regenerate'] ?? 0) > 900) {
                session_regenerate_id(true);
                $_SESSION['_last_regenerate'] = time();
            }

            $this->validateFingerprint();
        } catch (\Throwable $e) {
            $this->startFailed = true;
            throw $e;
        } finally {
            $this->isStarting = false;
        }
    }

    /* -------------------------
     | Basic Session Methods
     * -------------------------*/

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }
public function delete(string $key): void
{
    $this->ensureStarted();
    unset($_SESSION[$key]);
}
    /* -------------------------
     | Flash Messages
     * -------------------------*/

    public function setFlash(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION['__flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        if (!isset($_SESSION['__flash'][$key])) {
            return null;
        }

        $value = $_SESSION['__flash'][$key];
        unset($_SESSION['__flash'][$key]);

        return $value;
    }

    public function hasFlash(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION['__flash'][$key]);
    }

    public function flashInput(array $data): void
    {
        $this->ensureStarted();
        foreach ((array)$data as $key => $value) {
            $this->setFlash('old_' . $key, $value);
        }
    }

    /**
     * فلاش کردن خودکار ورودی‌های فعلی POST به درخواست بعدی (بدون مقادیر حساس)
     */
    public function flashOld(): void
    {
        $this->ensureStarted();
        $data = $_POST;
        
        // ایمن‌سازی: حذف پسوردها و توکن CSRF جهت افزایش امنیت
        unset($data['_token'], $data['csrf_token'], $data['password'], $data['password_confirmation'], $data['old_password']);
        
        $this->setFlash('old', $data);
    }

    /**
     * دریافت هوشمند و کش‌شده‌ی ورودی‌های قدیمی
     */
    public function getOld(string $key, mixed $default = null): mixed
    {
        if ($this->oldInputCache === null) {
            $this->ensureStarted();
            // استخراج یک‌بار برای همیشه در طول عمر پردازش و کش موضعی (Atomic cache)
            if (isset($_SESSION['__flash']['old'])) {
                $this->oldInputCache = $_SESSION['__flash']['old'];
                unset($_SESSION['__flash']['old']);
            } else {
                $this->oldInputCache = [];
            }
        }

        return $this->oldInputCache[$key] ?? $default;
    }


    public function logoutAll(int $userId): void
    {
        $this->ensureStarted();
        $this->destroy();

        // Scan Redis — FIX P0: use SCAN instead of KEYS to avoid blocking the entire Redis server
        try {
            $redis = Cache::getInstance()->redis();
            if ($redis && Cache::getInstance()->driver() === 'redis') {
                $prefix = 'chortke:session:';
                $iterator = null;
                $pattern = $prefix . '*';
                $batch = [];

                // SCAN is non-blocking and cursor-based, safe for large keyspaces
                while (false !== ($keys = $redis->scan($iterator, $pattern, 200))) {
                    foreach ($keys as $key) {
                        $data = $redis->get($key);
                        if (is_string($data) && str_contains($data, "user_id|i:{$userId};")) {
                            $batch[] = $key;
                            // Batch deletion every 100 keys to minimize round-trips
                            if (count($batch) >= 100) {
                                $redis->del($batch);
                                $batch = [];
                            }
                        }
                    }
                    // SCAN returns 0 when iteration is complete
                    if ($iterator === 0 || $iterator === '0' || $iterator === null) {
                        break;
                    }
                }
                if (!empty($batch)) {
                    $redis->del($batch);
                }
            }
        } catch (\Throwable $e) {}

        // Scan Files
        try {
            $sessionsDir = __DIR__ . '/../storage/sessions';
            if (is_dir($sessionsDir)) {
                $files = glob($sessionsDir . '/sess_*') ?: [];
                foreach ($files as $file) {
                    $data = @file_get_contents($file);
                    if (is_string($data) && str_contains($data, "user_id|i:{$userId};")) {
                        @unlink($file);
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    private function invalidateSession(): void
{
    // کاربر عملاً logout می‌شود
    $_SESSION = [];

    if (\session_status() === PHP_SESSION_ACTIVE) {
        \session_regenerate_id(true);
    }
}
    /* -------------------------
     | Security
     * -------------------------*/

    private function validateFingerprint(): void
{
    $current = $this->generateFingerprint();

    if (!isset($_SESSION['_fingerprint'])) {
        $_SESSION['_fingerprint'] = $current;
        return;
    }

    if (!\hash_equals(strval($_SESSION['_fingerprint']), (string)$current)) {
        // لاگ امنیتی با جزئیات بیشتر
        if (function_exists('logger')) {
    try {
        logger()->warning('Session fingerprint mismatch - Session will be invalidated', [
                'old_fingerprint' => substr(strval($_SESSION['_fingerprint'] ?? ''), 0, 8) . '...',
                'new_fingerprint' => substr((string)$current, 0, 8) . '...',
                'user_id' => $_SESSION['user_id'] ?? 'guest',
                'user_role' => $_SESSION['user_role'] ?? 'none',
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            ]);
			 } catch (\Throwable $ignore) {}
        }

        // ✅ به جای throw کردن: سشن را باطل کن و ادامه بده
        $this->invalidateSession();
        $_SESSION['_fingerprint'] = $this->generateFingerprint();
        return;
    }
}

    private function generateFingerprint(): string
    {
        // ✅ Fix: فقط از عوامل پایدار استفاده میشه
        // حذف شده: HTTP_ACCEPT (بین درخواست HTML/AJAX/asset تغییر میکنه)
        // حذف شده: session_id (بعد از regenerate تغییر میکنه)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $language  = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown';

        // Extract stable browser family to prevent invalidation on minor WebView / browser updates
        $browserFamily = 'unknown';
        if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera|MSIE|Trident)/i', $userAgent, $m)) {
            $browserFamily = $m[1];
        }

        // برای IPv4: فقط سه اکتت اول (subnet /24) تا تغییر IP موبایل tolerate شود
        // برای IPv6: ساب‌نت پایدار /48 (سه بخش اول هگز)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ipMasked = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $ipMasked = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts    = explode(':', $ip);
            $ipMasked = implode(':', array_slice($parts, 0, 3)) . ':0:0:0:0:0';
        }

        return hash('sha256', implode('|', [
            $ipMasked,
            $browserFamily,
            substr($language, 0, 5),
        ]));
    }

    /**
     * Resolve the configured PHP session name at the configuration boundary.
     *
     * Session names are part of the cookie contract and must not be silently
     * coerced from arbitrary mixed values.
     */
    private function resolveSessionName(mixed $value): string
    {
        if ($value === null) {
            return 'CHORTKE_SESSION';
        }
        if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException('session.name must contain only letters, digits, and underscores.');
        }
        return $value;
    }

    /**
     * Resolve session lifetime without accepting fractional, negative, or
     * arbitrary values from configuration.
     */
    private function resolveLifetime(mixed $value): int
    {
        if ($value === null) {
            return 1800;
        }
        if (is_int($value)) {
            if ($value < 0) {
                throw new \InvalidArgumentException('session.lifetime cannot be negative.');
            }
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }
        throw new \InvalidArgumentException('session.lifetime must be a non-negative integer.');
    }

    private function resolveBooleanConfig(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true') return true;
            if ($normalized === 'false') return false;
        }
        throw new \InvalidArgumentException("{$key} must be a boolean.");
    }

    private function resolveSameSite(mixed $value): string
    {
        if ($value === null) {
            return 'Lax';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('session.samesite must be a string.');
        }
        $normalized = ucfirst(strtolower(trim($value)));
        if (!in_array($normalized, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('session.samesite must be Lax, Strict, or None.');
        }
        return $normalized;
    }

    /* -------------------------
     | Lifecycle
     * -------------------------*/

    public function regenerate(bool $deleteOldSession = true): void
    {
        // M15 Fix: تضمین فعالیت سشن و بازنویسی اثرانگشت امنیتی به صورت همزمان با تغییر شناسه کاربری
        $this->ensureStarted();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        } else {
            $_SESSION['_session_id'] = bin2hex(random_bytes(16));
        }

        // Regenerate CSRF token
        $this->remove('_csrf_token');

        $_SESSION['_fingerprint'] = $this->generateFingerprint();
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
        $this->oldInputCache = null;
    }

    public function getId(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $id = session_id();
            return is_string($id) ? $id : '';
        }

        return $_SESSION['_session_id'] ?? '';
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}