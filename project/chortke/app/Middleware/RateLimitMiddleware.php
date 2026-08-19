<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\RateLimiter;
use Core\Session;
use Closure;
use App\Contracts\LoggerInterface;
use App\Constants\SessionKeys;

/**
 * RateLimitMiddleware — محدودسازی نرخ درخواست‌ها
 */
class RateLimitMiddleware extends BaseMiddleware
{
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;
    private Session $session;
    private int $maxAttempts;
    private int $decayMinutes;

    /**
     * Section 8.8 — Hardcoded ROUTE_LIMITS removed. The mapping now lives in
     * config/rate_limits.php under 'route_map' (single source of truth).
     * It's resolved at runtime via resolveLimit(); allows ops to tune
     * per-route limits via .env without code changes.
     */
    /** @var array<string, mixed>|null */
    private ?array $routeMap = null;

    public function __construct(RateLimiter $rateLimiter, LoggerInterface $logger, Session $session, int $maxAttempts = 60, int $decayMinutes = 1) {
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
        $this->session = $session;
        $this->maxAttempts = int_value(config('rate_limits.default.max_attempts', $maxAttempts));
        $this->decayMinutes = int_value(config('rate_limits.default.decay_minutes', $decayMinutes));
    }

    public function handle(Request $request, Closure $next, ?string $maxAttemptsParam = null, ?string $decayMinutesParam = null): mixed
    {
        $calledNext = false;
        try {
            [$maxAttempts, $decayMinutes, $configFailClosed] = $this->resolveLimit($request);
            if ($maxAttemptsParam !== null) {
                $maxAttempts = (int)$maxAttemptsParam;
            }
            if ($decayMinutesParam !== null) {
                $decayMinutes = (int)$decayMinutesParam;
            }

            // CORE-042: Use high-precision token_bucket for any path matched by the central route_map.
            $requestUri = $request->uri();
            $isCriticalPath = false;
            foreach (array_keys($this->getRouteMap()) as $pattern) {
                if (str_starts_with($requestUri, (string)$pattern) && $requestUri !== '/api/v1/ping') {
                    $isCriticalPath = true;
                    break;
                }
            }

            $originalStrategy = $this->rateLimiter->getStrategy();
            if ($isCriticalPath) {
                $this->rateLimiter->setStrategy('token_bucket');
            }

            $key = $this->resolveRequestSignature($request);

            $retryAfter = 0;
            try {
                // CORE-044 + Section 8.8: fail-closed when either the path is critical or the
                // config entry explicitly requests it (e.g. payment.callback).
                $failClosed = $isCriticalPath || $configFailClosed;
                $allowed = $this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes, $failClosed);
                if (!$allowed) {
                    // Read retry metadata from the same strategy that rejected
                    // the request. Restoring the previous strategy first would
                    // query a different key namespace and return an invalid TTL.
                    $retryAfter = max(1, $this->rateLimiter->availableIn($key));
                }
            } finally {
                $this->rateLimiter->setStrategy($originalStrategy);
            }

            if (!$allowed) {

                $this->logger->warning('Rate limit exceeded', [
                    'ip'          => $request->ip(),
                    'uri'         => $request->uri(),
                    'retry_after' => $retryAfter,
                ]);

                $response = new Response();
                $response->setStatusCode(429);
                $response->setHeader('Retry-After', (string)$retryAfter);
                $response->setHeader('X-RateLimit-Limit', (string)$maxAttempts);
                $response->setHeader('X-RateLimit-Remaining', '0');
                $response->setHeader('Content-Type', 'application/json; charset=utf-8');
                $response->setContent(json_encode([
                    'success'     => false,
                    'message'     => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً ' . $retryAfter . ' ثانیه دیگر صبر کنید.',
                    'retry_after' => $retryAfter,
                ], JSON_UNESCAPED_UNICODE));
                return $response;
            }

            $calledNext = true;
            $response = $this->toResponse($next($request));

            $remaining = max(0, $maxAttempts - $this->rateLimiter->hits($key));
            
            return $response
                ->header('X-RateLimit-Limit', (string)$maxAttempts)
                ->header('X-RateLimit-Remaining', (string)$remaining);

        } catch (\Throwable $e) {
            $this->logger->error('middleware.rate_limit.error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if ($calledNext) {
                throw $e;
            }
            
            // Fail-Closed: در صورت بروز هرگونه خطای داخلی در سیستم محدودسازی نرخ، درخواست را به طور ایمن رد می‌کنیم
            $response = new Response();
            $response->json([
                'success' => false,
                'message' => 'سرویس موقتاً در دسترس نیست. لطفا دقایقی دیگر تلاش کنید.'
            ], 503);

            // json() همیشه HttpResponseException شلیک می‌کند؛ این return فقط برای
            // اعلام کامل بودن امضای متد است و در عمل هرگز اجرا نمی‌شود.
            return $response;
        }
    }

    /**
     * @return array{0:int,1:int,2:bool}  [maxAttempts, decayMinutes, failClosed]
     */
    private function resolveLimit(Request $request): array
    {
        $uri = $request->uri();
        $map = $this->getRouteMap();

        foreach ((array)$map as $pattern => $target) {
            if (!str_starts_with($uri, (string)$pattern)) {
                continue;
            }
            // $target is [group, endpoint]
            if (!is_array($target)) {
                continue;
            }
            $group    = str_value($target[0] ?? '');
            $endpoint = str_value($target[1] ?? 'general');
            if ($group === '') {
                continue;
            }
            $cfg = config("rate_limits.{$group}.{$endpoint}");
            if (!is_array($cfg) || empty($cfg)) {
                // group itself may be the config (no nested endpoint)
                $cfg = config("rate_limits.{$group}");
            }
            if (is_array($cfg) && isset($cfg['max_attempts'])) {
                return [
                    (int)($cfg['max_attempts'] ?? $this->maxAttempts),
                    (int)($cfg['decay_minutes'] ?? $this->decayMinutes),
                    (bool)($cfg['fail_closed'] ?? false),
                ];
            }
        }

        return [$this->maxAttempts, $this->decayMinutes, false];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRouteMap(): array
    {
        if ($this->routeMap !== null) {
            return $this->routeMap;
        }
        $cfg = config('rate_limits.route_map');
        $this->routeMap = is_array($cfg) ? $cfg : [];
        return $this->routeMap;
    }

    private function resolveRequestSignature(Request $request): string
    {
        $uri = $request->uri();
        $cleanUri = strtok($uri, '?') ?: $uri; // فقط path، بدون query parameters
        
        // CORE-043: Normalize components (trim, lowercase) to ensure hash uniqueness
        $cleanUri = strtolower(trim((string)$cleanUri));
        
        $userId = (PHP_SESSION_ACTIVE === session_status()) ? $this->session->get(SessionKeys::USER_ID) : null;
        $ip = get_client_ip();

        if ($userId) {
            // اصلاح راهبردی معماری موبایل و جلوگیری از دور زدن محدودیت با پروکسی:
            // برای کاربران احراز هویت‌شده، کلید منحصراً بر پایه UserID تنظیم می‌شود تا جابه‌جایی میان دکل‌های 4G/5G (IP Drift) یا چرخش پروکسی مهاجمان تاثیری در مکانیزم نداشته باشد.
            return 'rl_user_' . $userId . '_' . hash('sha256', $cleanUri);
        }
        
        // L-06 Fix: کلید مهمان نباید بر پایهٔ device fingerprint قابل‌جعل باشد: fingerprint از
        // session_id و هدرهای User-Agent/Accept-Language ساخته می‌شود که مهمان در هر
        // درخواست می‌تواند آن‌ها را عوض کند و با هر چرخش، bucket تازه بگیرد (دور زدن محدودیت).
        // کلید را روی IP نرمال‌شده (IPv6 به /64) لنگر می‌کنیم که توسط مهاجم قابل‌چرخش نیست.
        // توجه: مقدار get_client_ip() به اعتماد به پراکسی وابسته است (موضوع جداگانه L-08).
        $normalizedIp = $this->normalizeIp($ip);
        return 'rl_ip_' . hash('sha256', $normalizedIp) . '_' . hash('sha256', $cleanUri);
    }

    /**
     * MEDIUM-06 Fix: Normalize IPv6 address to its /64 prefix
     */
    private function normalizeIp(string $ip): string 
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) return $ip;
            $packed = substr($packed, 0, 8) . str_repeat("\x00", 8); // /64 mask
            return (string)inet_ntop($packed);
        }
        return $ip;
    }
}
