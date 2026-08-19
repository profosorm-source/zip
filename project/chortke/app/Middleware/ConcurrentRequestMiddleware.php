<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Redis;
use Closure;

/**
 * ConcurrentRequestMiddleware — جلوگیری از ثبت همزمان و مضاعف درخواست‌ها
 */
class ConcurrentRequestMiddleware extends BaseMiddleware
{
    private Redis $redis;

    public function __construct(Redis $redis) {
        $this->redis = $redis;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $userIdValue = session()->get('user_id');
        $userId = is_numeric($userIdValue) ? int_value($userIdValue) : 0;

        // قفل گذاری فقط برای متدهایی که تغییر دهنده هستند (POST, PUT, DELETE, PATCH)
        if ($userId && in_array(strtoupper($request->method()), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $isFinancial = $this->isFinancialRoute($request->uri());
            if ($this->redis->isAvailable()) {
                $uriHash = md5($request->uri());
                $lockKey = "lock:user:{$userId}:{$uriHash}";

                // تلاش برای ایجاد قفل غیرهمزمان با انقضای ۳ ثانیه
                // در کتابخانه Redis افزونه php، متد set با پارامترهای ['nx', 'ex' => 3] کار می‌کند
                try {
                    $client = $this->redis->getClient();
                    if ($client === null) throw new \RuntimeException('Redis client unavailable.');
                    $acquired = $client->set($lockKey, '1', ['NX', 'EX' => 3]) === true;
                    if (!$acquired) {
                        $response = new Response();
                        if ($request->isAjax()) {
                            $response->json([
                                'success' => false,
                                'message' => 'درخواست قبلی شما در حال پردازش است. لطفا چند لحظه صبر کنید.'
                            ], 429);
                        }
                        
                        $response->setContent('درخواست همزمان مجاز نیست. لطفا صبر کنید.');
                        $response->status(429);
                        return $response;
                    }
                } catch (\Throwable $e) {
                    // L-07 Fix: روی مسیرهای مالی/حساس به‌جای fail-open باید fail-closed شویم
                    // تا در صورت خطای Redis امکان ثبت مضاعف (double-submit) مالی وجود نداشته باشد.
                    if ($isFinancial) {
                        return $this->serviceUnavailable($request);
                    }
                    // مسیرهای غیرمالی: برای حفظ دسترس‌پذیری، fail-open می‌مانند.
                }
            } elseif ($isFinancial) {
                // L-07 Fix: Redis در دسترس نیست و مسیر مالی است → fail-closed
                return $this->serviceUnavailable($request);
            }
        }

        try {
            $response = $next($request);
        } finally {
            // در پایان درخواست، قفل آزاد می‌شود
            if (isset($lockKey) && $this->redis->isAvailable()) {
                try {
                    $client = $this->redis->getClient();
                    if ($client !== null) $client->del($lockKey);
                } catch (\Throwable $e) {
                    // نادیده گرفتن خطا
                }
            }
        }

        return $this->toResponse($response);
    }

    /**
     * L-07: تشخیص مسیرهای مالی/حساس که در آن‌ها باید fail-closed باشیم.
     */
    private function isFinancialRoute(string $uri): bool
    {
        $path = strtok($uri, '?');
        $path = strtolower(trim((string)($path === false ? $uri : $path)));
        $prefixes = ['/wallet', '/payment', '/withdrawal', '/deposit', '/transfer', '/vitrine', '/api/v1/wallet', '/api/v1/payment'];
        foreach ($prefixes as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * L-07: پاسخ fail-closed (503) برای مسیرهای مالی وقتی Redis در دسترس نیست.
     */
    private function serviceUnavailable(Request $request): Response
    {
        $response = new Response();
        if ($request->isAjax()) {
            $response->json([
                'success' => false,
                'message' => 'سرویس موقتاً در دسترس نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.'
            ], 503);
        }
        $response->setContent('سرویس موقتاً در دسترس نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.');
        $response->status(503);
        return $response;
    }
}
