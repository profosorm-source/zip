<?php

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use App\Services\FeatureFlagService;
use Core\Request;
use Core\Response;

/**
 * Middleware برای محافظت از Route ها با Feature Flags
 * 
 * مثال استفاده:
 * Route::middleware(['auth', 'feature:crypto_wallet'])->get('/wallet', ...);
 */
class RequireFeature
{
    private FeatureFlagService $featureService;
    private LoggerInterface $logger;
    private Response $response;
    
    public function __construct(
        FeatureFlagService $featureService,
        LoggerInterface $logger,
        Response $response
    ) {
        $this->featureService = $featureService;
        $this->logger = $logger;
        $this->response = $response;
    }
    
    /**
     * Handle the middleware
     * 
     * @param Request $request
     * @param callable $next
     * @param string $feature نام فیچر (مثلا: crypto_wallet)
     * @param string|null $mode حالت: 'redirect' یا 'json' یا '404'
     */
    public function handle(Request $request, callable $next, string $feature, ?string $mode = null): mixed
    {
        $userId = user_id();
        
        // بررسی فیچر
        if (!$this->featureService->isEnabled($feature, $userId)) {
            return $this->handleDisabledFeature($request, $feature, $userId, $mode);
        }
        
        // فیچر فعال است، ادامه بده
        return $next($request);
    }
    
    /**
     * مدیریت فیچر غیرفعال
     */
    private function handleDisabledFeature(Request $request, string $feature, ?int $userId, ?string $mode): mixed
    {
        // تشخیص نوع درخواست
        $isAjax = $request->isAjax();
        $mode = $mode ?? ($isAjax ? 'json' : 'redirect');
        
        // لاگ کردن تلاش دسترسی (🚀 M-02 Fix: Better Attribution)
        $this->logger->warning('feature_flag.access_denied', [
            'channel' => 'feature_flag',
            'feature' => $feature,
            'user_id' => $userId ?: 'guest',
            'ip' => $request->ip(),
            'path' => $request->uri(),
            'method' => $request->method(),
        ]);
        
        // حالت‌های مختلف پاسخ
        switch ($mode) {
            case 'json':
                $this->response->json([
                    'success' => false,
                    'error' => 'feature_disabled',
                    'message' => 'این ویژگی در حال حاضر غیرفعال است.',
                ], 503);
                // L-09 Fix: باید صریحاً return شود تا به case '404' fall-through نشود.
                return $this->response;

            case '404':
                // در محیط‌های API ممکن است بخواهیم خطای 404 برگردانیم تا وجود مسیر لو نرود
                abort(404);
                return $this->response;
                
            case 'redirect':
            default:
                $this->response->setStatusCode(503);
                $this->response->setHeader('Content-Type', 'text/html; charset=utf-8');
                $this->response->setContent('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>Service Unavailable</title><body><h1>503</h1><p>این ویژگی در حال حاضر در دسترس نیست.</p></body></html>');
                return $this->response;
        }
    }
}
