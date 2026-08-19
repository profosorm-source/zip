<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class StartSeoTaskJob
{
    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Services\Seo\AdsSeoService $adsService;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Services\Seo\AdsSeoService $adsService,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->adsService = $adsService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
}

    /** @return array<string, mixed> */
public function handle(int $adId, int $userId): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($adId, $userId) {
                // قفل کردن آگهی برای جلوگیری از Race Condition
                $ad = $this->adsService->getAdForUpdate($adId);
                
                if (!$ad) {
                    return ['success' => false, 'message' => 'آگهی یافت نشد'];
                }
        
                if ($ad->status !== 'active') {
                    return ['success' => false, 'message' => 'آگهی فعال نیست'];
                }

                if ((int)($ad->user_id ?? 0) === $userId) {
                    return ['success' => false, 'message' => 'امکان اجرای تسک SEO خودتان وجود ندارد.'];
                }

                $minPayout = max(1.0, (float)($ad->min_payout ?? $ad->price_per_click ?? 0));
        
                if ((float)$ad->remaining_budget < $minPayout) {
                    return ['success' => false, 'message' => 'بودجه آگهی تمام شده است'];
                }
        
                // بررسی تکراری
                if ($this->adsService->executionExistsToday($adId, $userId)) {
                    return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
                }
        
                // بررسی محدودیت روزانه کاربر
                $todayCount = $this->adsService->countUserExecutionsToday($userId);
                if ($todayCount >= (int)($ad->max_per_day ?? 10)) {
                    $maxPerDay = (int)($ad->max_per_day ?? 10);
                    return ['success' => false, 'message' => "حداکثر {$maxPerDay} تسک در روز مجاز است"];
                }
        
                // بررسی محدودیت ساعتی
                $hourlyLimit = int_value($this->appSettings->get('seo_max_tasks_per_hour', 5));
                $hourlyCount = $this->adsService->countUserExecutionsLastHour($userId);
                if ($hourlyCount >= $hourlyLimit) {
                    return ['success' => false, 'message' => "حداکثر {$hourlyLimit} تسک در ساعت مجاز است. لطفاً کمی صبر کنید"];
                }
        
                // بررسی IP
                $ip = get_client_ip();
                $ipLimit = int_value($this->appSettings->get('seo_max_ip_tasks_per_hour', 10));
                $ipHourly = $this->adsService->countIpExecutionsLastHour($ip);
                if ($ipHourly >= $ipLimit) {
                    return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید'];
                }
        
                $fingerprint = function_exists('generate_device_fingerprint') 
                    ? generate_device_fingerprint() 
                    : md5(get_user_agent() . $ip);
    
                $sessionId = bin2hex(random_bytes(16));
    
                $executionId = $this->adsService->createExecution([
                    'ad_id' => $adId,
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'status' => 'started',
                    'ip_address' => $ip,
                    'device_fingerprint' => $fingerprint,
                    'started_at' => date('Y-m-d H:i:s'),
                    'target_keyword' => $ad->keyword,
                ]);

                if (!$executionId) {
                    throw new \RuntimeException('خطا در ایجاد رکورد اجرای تسک.');
                }
    
                return [
                    'success' => true,
                    'message' => 'تسک SEO شروع شد.',
                    'execution_id' => (int)$executionId,
                    'execution' => ['id' => (int)$executionId],
                    'session_id' => $sessionId,
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_task.start_failed', ['error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
            }
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }
}
