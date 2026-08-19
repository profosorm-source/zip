<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use App\Services\Settings\AppSettings;
use Closure;

/**
 * MaintenanceMiddleware — مدیریت حالت تعمیرات سایت
 */
class MaintenanceMiddleware
{
    private AppSettings $setting;

    public function __construct(AppSettings $setting) {
        $this->setting = $setting;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $maintenanceMode = false;
        $allowedIPs = [];
        $message = 'سایت در حال بروزرسانی است...';

        try {
            $maintenanceMode = (bool)$this->setting->get('maintenance_mode', config('maintenance.enabled', false));
            $allowedIPs = (array)$this->setting->get('maintenance_allowed_ips', config('maintenance.allowed_ips', []));
            $message = str_value($this->setting->get('maintenance_message', config('maintenance.message', 'سایت در حال بروزرسانی است...')));
        } catch (\Throwable $e) {
            $maintenanceMode = (bool)config('maintenance.enabled', false);
            $allowedIPs = (array)config('maintenance.allowed_ips', []);
            $message = str_value(config('maintenance.message', 'سایت در حال بروزرسانی است...'));
        }
        
        if (!$maintenanceMode) {
            return $this->toResponse($next($request));
        }

        // بررسی مسیرهای استثنا شده (Except paths)
        $uri = (string)$request->uri();
        $excepts = (array)config('maintenance.except', []);
        foreach ($excepts as $except) {
            if ($except === '/' && $uri === '/') {
                return $this->toResponse($next($request));
            }
            if ($except !== '/' && str_starts_with($uri, str_value($except))) {
                return $this->toResponse($next($request));
            }
        }
        
        // استثناء برای ادمین‌ها
        if (function_exists('is_admin') && is_admin()) {
            return $this->toResponse($next($request));
        }
        
        // استثناء برای IPهای مجاز (Strict check)
        // L-08 Fix: دور زدن حالت تعمیر یک امتیاز حساس است و نباید به هدر قابل‌جعل
        // X-Forwarded-For متکی باشد. منبع معتبر همیشه REMOTE_ADDR (IP واقعی اتصال) است.
        // IP فوروارد‌شده فقط زمانی پذیرفته می‌شود که فهرست پراکسی مورد اعتماد
        // به‌صراحت پیکربندی شده باشد (نه fallback ضمنی localhost).
        $remoteAddr = str_value($_SERVER['REMOTE_ADDR'] ?? '');
        $trustedProxiesConfigured = !empty((array)config('app.trusted_proxies', []));

        $ipAllowed = ($remoteAddr !== '' && in_array($remoteAddr, $allowedIPs, true))
            || ($trustedProxiesConfigured && in_array($request->ip(), $allowedIPs, true));

        if ($ipAllowed) {
            return $this->toResponse($next($request));
        }
        
        $response = new Response();
        $response->setStatusCode(503);

        // اصلاح کلیدی معماری کلاینت موبایل (Mobile API Maintenance Shield):
        // بازگشت ساختار استاندارد JSON به جای صفحات HTML در زمان درخواست‌های API جهت جلوگیری از کرش پارسرهای کلاینت موبایل
        $wantsJson = $request->isAjax() 
            || str_starts_with($request->uri(), '/api/') 
            || str_contains(str_value($request->header('Accept') ?? ''), 'application/json');

        if ($wantsJson) {
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->setContent(json_encode([
                'success' => false,
                'message' => $message,
                'maintenance' => true,
                'error_code' => 'MAINTENANCE_MODE'
            ], JSON_UNESCAPED_UNICODE));
            return $response;
        }
        
        // رندر ویو در قالب استرینگ برای قرارگیری در آبجکت Response
        ob_start();
        view('errors/maintenance', ['message' => $message]);
        $content = ob_get_clean();
        
        $response->setContent($content ?: 'Site is under maintenance.');
        return $response;
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        $response = new Response();
        $response->setContent(str_value($result));
        return $response;
    }
}
