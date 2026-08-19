<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
use App\Models\BannerPlacement;
use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use App\Services\UploadService;
use Core\Database;
use Core\EventDispatcher;
use App\Services\Ads\AdsBudgetSettlementService;

class BannerService
{
    private Ads $bannerModel;
    private BannerPlacement $placementModel;
    private WalletServiceInterface $walletService;
    private UploadService $uploadService;
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;

    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private ?\Core\TransactionWrapper $transactionWrapper = null;
    private AdsBudgetSettlementService $adsBudgetSettlementService;

    /**
     * ROOT-CAUSE HELPER (principled, consistent across project)
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) {
            return null;
        }
        /** @var \stdClass $obj */
        $obj = is_object($data) ? $data : (object)(is_array($data) ? $data : (array)$data);
        return $obj;
    }

    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $bannerModel,
        BannerPlacement $placementModel,
        WalletServiceInterface $walletService,
        UploadService $uploadService,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null,
        ?\Core\TransactionWrapper $transactionWrapper = null,
        ?AdsBudgetSettlementService $adsBudgetSettlementService = null
    ) {
        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;

        $this->bannerModel = $bannerModel;
        $this->placementModel = $placementModel;
        $this->walletService = $walletService;
        $this->uploadService = $uploadService;
        $this->outboxService = $outboxService;
        $this->transactionWrapper = $transactionWrapper;
        $this->adsBudgetSettlementService = $adsBudgetSettlementService ?? new AdsBudgetSettlementService(
            $db,
            $logger,
            new \App\Services\Settings\AppSettings($cache, $logger, new \App\Models\Setting($db)),
            app(\App\Domain\Financial\Services\FinancialEscrowService::class),
            $walletService
        );
    }

    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        if ($this->transactionWrapper === null) {
            $this->transactionWrapper = new \Core\TransactionWrapper($this->db);
        }

        return $this->transactionWrapper;
    }

    /**
     * دریافت بنرهای فعال با اعمال به‌روزرسانی دسته‌جمعی آماری
     */
    /** @return array{banners: list<\stdClass>, placement: object} */
    public function getActiveBanners(string $placement): array
    {
        $placementObj = $this->toObject($this->placementModel->findBySlug($placement));
        if (!$placementObj || !isset($placementObj->id) || !$placementObj->is_active) {
            return ['banners' => [], 'placement' => (object)[]];
        }

        $banners = $this->bannerModel->getActiveBannersByPlacement($placement);

        if (\count($banners) > $placementObj->max_banners) {
            $banners = \array_slice($banners, 0, $placementObj->max_banners);
        }

        // H-06 Fix: به‌روزرسانی بافر شده بازدیدها در Redis جهت جلوگیری از Lock Contention در دیتابیس
        $bannerIds = array_map(fn($b) => (int)$b->id, $banners);
        if ($bannerIds !== []) {
            $redis = $this->cache->redis();
            if ($redis !== null) {
                foreach ($bannerIds as $id) {
                    $redis->hIncrBy($this->cache->redisKey('banner_impressions_buffer'), (string)$id, 1);
                }
            } else {
                // Phase 4: real delivery budget consumption for served impressions.
                foreach ($bannerIds as $id) {
                    $this->adsBudgetSettlementService->consumeDeliveryBudget((int)$id, 'banner', 'impression', 1, null, [
                        'placement' => $placement,
                        'source' => 'banner_service.getActiveBanners',
                    ]);
                }
            }
        }

        return [
            'banners' => $banners,
            'placement' => $placementObj,
        ];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function createBanner(array $data, int $createdBy): array
    {
        $errors = $this->validateBanner($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $data['created_by'] = $createdBy;

        if (isset($data['image_file']) && is_array($data['image_file']) && ($data['image_file']['error'] ?? null) === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }
            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $data['type'] = 'banner'; // اجبار به نوع بنر
        $data['user_id'] = $createdBy; // نگاشت استاندارد مالک تبلیغ
        
        $id = $this->bannerModel->create($data);
        if (!$id) {
            return ['success' => false, 'errors' => ['general' => 'خطا در ایجاد بنر']];
        }

        $this->logger->info('banner_created', ['message' => "بنر جدید با شناسه " . (string)$id . " ایجاد شد"]);
        return ['success' => true, 'banner_id' => $id];
    }

    /**
     * خرید و ثبت بنر توسط کاربر به صورت تراکنشی و ایمن (Atomicity)
     */
    /**
     * 🛡️ Fix: استفاده از TransactionWrapper به جای transaction دستی
     * قبلی: beginTransaction/commit/rollBack دستی (بدون compensation mechanism)
     * جدید: TransactionWrapper با Saga-like rollback
     */
    /** @param array<string, mixed> $data
     *  @param array<string, mixed>|null $imageFile
     *  @return array<string, mixed> */
    public function purchaseUserBanner(int $userId, array $data, ?array $imageFile): array
    {
        // آپلود امن تصویر قبل از قفل تراکنش (این عملیات غیر تراکنشی است)
        $imagePath = null;
        if ($imageFile && !empty($imageFile['name']) && $imageFile['error'] === UPLOAD_ERR_OK) {
            $up = $this->uploadBannerImage($imageFile);
            if (!$up['success']) {
                return ['success' => false, 'message' => $up['error'] ?? 'خطا در آپلود تصویر'];
            }
            $imagePath = isset($up['path']) ? (string)(is_scalar($up['path']) ? $up['path'] : '') : null;
        }

        try {
            // 🛡️ استفاده از TransactionWrapper برای مدیریت خودکار commit/rollback
            return $this->getTransactionWrapper()->run(function() use ($userId, $data, $imagePath) {
                // ۱. محاسبات مالی
                $durationDays = \max(1, (int)(is_numeric($data['duration_days'] ?? null) ? $data['duration_days'] : 1));
                $bannerType = $data['banner_type'] ?? 'user';
                $category = $data['category'] ?? '';

                $pricePerDay = ($bannerType === 'startup' && $category === 'startup') ? 500 : 2000;
                $totalPrice = $pricePerDay * $durationDays;

                if ($bannerType === 'startup' && $durationDays === 7) {
                    $totalPrice = 0;
                }

                // ۲. کسر وجه از کیف پول (داخل TransactionWrapper)
                if ($totalPrice > 0) {
                    assert_fraud_allowed($userId, 'banner.purchase', ['amount' => (string)$totalPrice]);
                    $debit = $this->walletService->withdraw($userId, (string)$totalPrice, 'irt', [
                        'type' => 'user_banner',
                        'description' => "خرید بنر تبلیغاتی {$durationDays} روزه"
                    ]);
                    if (!$debit['success']) {
                        if ($imagePath) $this->deleteBannerImage($imagePath);
                        throw new \Core\Exceptions\InsufficientBalanceException(is_string($debit['message'] ?? null) ? $debit['message'] : 'موجودی حساب برای خرید بنر کافی نیست.');
                    }
                }

                // ۳. محاسبه زمان
                $now = new \DateTime();
                $startDate = $now->format('Y-m-d H:i:s');
                $now->modify("+{$durationDays} days");
                $endDate = $now->format('Y-m-d H:i:s');

                // ۴. درج بنر
                $adId = $this->bannerModel->create([
                    'type' => 'banner',
                    'user_id' => $userId,
                    'title' => $data['title'] ?? 'بدون عنوان',
                    'image_path' => $imagePath,
                    'link' => $data['link'] ?? null,
                    'placement' => $data['placement'] ?? 'sidebar',
                    'banner_type' => $bannerType,
                    'category' => $category,
                    'total_budget' => $totalPrice,
                    'remaining_budget' => $totalPrice,
                    'status' => 'pending',
                    'is_active' => 0,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'alt_text' => $data['alt_text'] ?? null,
                ]);

                if (!$adId) {
                    throw new \Core\Exceptions\ApplicationException('خطای سیستمی در ذخیره‌سازی بنر');
                }

                $this->logger->activity('banner.purchase', "خرید بنر #" . (string)$adId, $userId, ['amount' => $totalPrice]);

                return ['success' => true, 'banner_id' => $adId];
            });
        } catch (\Throwable $e) {
            if (isset($imagePath) && $imagePath) {
                $this->deleteBannerImage($imagePath);
            }
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('banner.purchase_failed', ['error' => $e->getMessage(), 'user' => $userId]);
            return ['success' => false, 'message' => 'بروز خطای غیرمنتظره در ثبت بنر. مجدداً تلاش کنید.'];
        }
    }

    /**
     * لغو بنر در حال انتظار و برگشت وجه به صورت ایمن
     */
    /** @return array<string, mixed> */
    public function cancelPendingBanner(int $bannerId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // قفل کردن رکورد برای جلوگیری از Race Conditions
            $stmt = $this->db->prepare("SELECT * FROM ads WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$bannerId, $userId]);
            /** @var \stdClass|null $banner */
            $banner = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$banner || $banner->type !== 'banner') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'بنر مورد نظر یافت نشد.'];
            }

            if ($banner->status !== 'pending') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'فقط بنرهای در انتظار تایید قابل لغو هستند.'];
            }

            // ۱. لغو در سیستم
            $updated = $this->bannerModel->update($bannerId, [
                'status' => 'cancelled',
                'deleted_at' => date('Y-m-d H:i:s')
            ]);

            if (!$updated) {
                throw new \Core\Exceptions\ApplicationException("خطا در بروزرسانی وضعیت بنر #{$bannerId}");
            }

            // ۲. برگشت وجه
            $refundAmount = (string)($banner->total_budget ?? '0');
            if ($refundAmount > 0) {
                $payload = [
                    'user_id' => $userId,
                    'amount' => (string)$refundAmount,
                    'currency' => 'irt',
                    'metadata' => [
                        'type' => 'banner_refund',
                        'description' => "برگشت هزینه لغو بنر #{$bannerId}",
                        'banner_id' => $bannerId,
                        'idempotency_key' => "banner_refund_{$bannerId}_{$userId}"
                    ]
                ];
                
                if ($this->outboxService) {
                    $this->outboxService->record('banner_refund', $bannerId, \App\Events\Registry\EventRegistry::BANNER_REVENUE_GENERATED, $payload);
                }
            }

            $this->db->commit();
            $this->logger->activity('banner.cancel', "لغو بنر #{$bannerId}", $userId, ['refund' => $refundAmount]);

            return ['success' => true, 'message' => 'بنر با موفقیت لغو و هزینه آن بازگشت داده شد.'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('banner.cancel_failed', ['error' => $e->getMessage(), 'banner' => $bannerId]);
            return ['success' => false, 'message' => 'خطا در لغو درخواست.'];
        }
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function updateBanner(int $id, array $data): array
    {
        $banner = $this->toObject($this->bannerModel->find($id));
        if (!$banner) { 
        return ['success' => false, 'errors' => ['general' => 'بنر یافت نشد']];
        }

        $errors = $this->validateBanner($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        if (isset($data['image_file']) && is_array($data['image_file']) && ($data['image_file']['error'] ?? null) === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }

            // حذف تصویر قبلی با مکانیزم ضد Path Traversal
            if ($banner->image_path) {
                $this->deleteBannerImage($banner->image_path);
            }

            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $result = $this->bannerModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'errors' => ['general' => 'خطا در بروزرسانی بنر']];
        }

        $this->logger->info('banner_updated', ['message' => "بنر {$id} بروزرسانی شد"]);
        return ['success' => true];
    }

    /** @return array<string, mixed> */
    public function deleteBanner(int $id): array
    {
        $banner = $this->toObject($this->bannerModel->find($id));
        if (!$banner) { 
        return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        // استفاده از متد حذف ایمن داخلی مدل Core
        $this->bannerModel->delete($id);

        // حذف ایمن تصویر بنر با تضمین کامل جلوگیری از Path Traversal
        if ($banner->image_path) {
            $this->deleteBannerImage($banner->image_path);
        }

        $this->logger->warning('banner_deleted', ['message' => "بنر {$id} حذف شد"]);
        return ['success' => true, 'message' => 'بنر با موفقیت حذف شد'];
    }

    /**
     * متد کمکی امن برای حذف فایل تصویر بنر جهت پیشگیری کامل از آسیب‌پذیری Path Traversal
     */
    private function deleteBannerImage(?string $imagePath): void
    {
        if (empty($imagePath)) {
            return;
        }

        $this->uploadService->delete($imagePath);
    }

    /** @return array<string, mixed> */
    public function toggleBanner(int $id): array
    {
        $banner = $this->toObject($this->bannerModel->find($id));
        if (!$banner) { 
        return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        $newStatus = $banner->is_active ? 0 : 1;
        $this->bannerModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        $this->logger->info('banner_toggle', ['message' => "بنر {$id} {$statusText} شد"]);
        return ['success' => true, 'is_active' => $newStatus, 'message' => "بنر با موفقیت {$statusText} شد"];
    }

    /** @return array<string, mixed> */
    public function trackClick(int $bannerId): array
    {
        $banner = $this->toObject($this->bannerModel->find($bannerId));
        if (!$banner || !isset($banner->id) || !$banner->is_active) {
            return ['success' => false, 'redirect' => '/'];
        }

        $userId = auth() ? user_id() : null;
        $ip = get_client_ip();

        try {
            // Phase 4: click is both analytics and real budget consumption.
            // The finance service owns its transaction, escrow lock and idempotent accounting.
            $this->adsBudgetSettlementService->consumeDeliveryBudget($bannerId, 'banner', 'click', 1, $userId, [
                'source' => 'banner_service.trackClick',
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('banner.trackClick.failed', [
                'error' => $e->getMessage(),
                'banner_id' => $bannerId
            ]);
        }

        $redirectUrl = $banner->link ?: '/';
        if (!$this->validateRedirectUrl($redirectUrl)) {
            $this->logger->warning('banner.unsafe_redirect', ['url' => $redirectUrl, 'banner_id' => $bannerId]);
            $redirectUrl = '/';
        }

        return ['success' => true, 'redirect' => $redirectUrl];
    }

    /**
     * بررسی امنیت URL هدایت برای جلوگیری از Open Redirect و XSS (javascript: و غیره)
     */
    private function validateRedirectUrl(string $url): bool
    {
        if (empty($url) || $url === '/') {
            return true;
        }

        $parsed = parse_url($url);
        $mobileScheme = strtolower((string)(is_scalar(config('app.mobile.scheme', 'chortke')) ? config('app.mobile.scheme', 'chortke') : ''));
        // اصلاح کلیدی معماری کلاینت موبایل (Mobile Banner Deep Link Shield):
        // دریافت پویا و بدون هاردکد شمای اختصاصی اپلیکیشن موبایل از لایه کانفیگ جهت سازگاری با تغییرات برندینگ و دامنه‌های تولیدی
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https', $mobileScheme], true)) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if (empty($host)) {
            return false;
        }

        // Whitelist domains
        $allowedDomains = ['chortke.com', 'trusted-partner.com', 'example.com'];
        $currentHost = app()->request->header('host');
        if ($currentHost !== '') {
            $allowedDomains[] = $currentHost;
        }

        // To prevent sub-domain spoofing or @ bypass, check that the host matches or ends with one of the allowed domains
        foreach ($allowedDomains as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function deactivateExpired(): int
    {
        // Phase 5: از query خام legacy که فقط status را عوض می‌کرد استفاده نمی‌کنیم؛
        // reconciliation جدید، escrow/refund مانده بودجه را هم همزمان انجام می‌دهد.
        $result = $this->adsBudgetSettlementService->reconcileLifecycle(200);
        $count = (is_numeric($result['completed'] ?? null) ? (int)$result['completed'] : 0) + (is_numeric($result['expired'] ?? null) ? (int)$result['expired'] : 0);
        if ($count > 0) {
            $this->logger->info('banners_expired', ['message' => "{$count} تبلیغ/بنر منقضی یا تمام‌شده reconcile شد", 'result' => $result]);
        }
        return $count;
    }

    public function deactivateExpiredBanners(): int
    {
        return $this->deactivateExpired();
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function updatePlacement(int $id, array $data): array
    {
        $placement = $this->toObject($this->placementModel->find($id));
        if (!$placement) { 
        return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $result = $this->placementModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی'];
        }

        $this->logger->info('placement_updated', ['message' => "جایگاه {$placement->slug} بروزرسانی شد"]);
        return ['success' => true, 'message' => 'جایگاه با موفقیت بروزرسانی شد'];
    }

    /** @return array<string, mixed> */
    public function togglePlacement(int $id): array
    {
        $placement = $this->toObject($this->placementModel->find($id));
        if (!$placement) { 
        return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $newStatus = $placement->is_active ? 0 : 1;
        $this->placementModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        return ['success' => true, 'is_active' => $newStatus, 'message' => "جایگاه {$statusText} شد"];
    }

    /** @param array<string, mixed> $file
     *  @return array<string, mixed> */
    protected function uploadBannerImage(array $file): array
    {
        $result = $this->uploadService->upload(
            $file,
            'banners',
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            2 * 1024 * 1024 // 2MB
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message']];
        }

        return ['success' => true, 'path' => $result['path'], 'filename' => $result['filename']];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    protected function validateBanner(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate) {
            if (empty($data['title'])) {
                $errors['title'] = 'عنوان بنر الزامی است';
            }
            if (empty($data['placement'])) {
                $errors['placement'] = 'جایگاه بنر الزامی است';
            }
        }

        if (!empty($data['title']) && \mb_strlen((string)(is_scalar($data['title']) ? $data['title'] : '')) > 255) {
            $errors['title'] = 'عنوان حداکثر 255 کاراکتر';
        }

        if (!empty($data['link'])) {
            if (!filter_var((string)(is_scalar($data['link']) ? $data['link'] : ''), FILTER_VALIDATE_URL) || !$this->validateRedirectUrl((string)(is_scalar($data['link']) ? $data['link'] : ''))) {
                $errors['link'] = 'لینک معتبر نیست (باید با http یا https شروع شود)';
            }
        }

        $validPlacements = ['header', 'footer', 'sidebar', 'homepage', 'dashboard_user', 'dashboard_admin'];
        if (!empty($data['placement']) && !\in_array($data['placement'], $validPlacements)) {
            $errors['placement'] = 'جایگاه نامعتبر است';
        }

        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            if (\strtotime((string)(is_scalar($data['end_date']) ? $data['end_date'] : '')) <= \strtotime((string)(is_scalar($data['start_date']) ? $data['start_date'] : ''))) {
                $errors['end_date'] = 'تاریخ پایان باید بعد از تاریخ شروع باشد';
            }
        }
        return $errors;
    }

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed> */
    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at');

        if (!empty($q)) {
            $like = "%{$q}%";
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('link', 'LIKE', $like);
            });
        }

        if (!empty($filters['placement'])) {
            $query->where('placement', '=', e($filters['placement'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['status'])) {
            $query->where('status', '=', e($filters['status'], ENT_QUOTES, 'UTF-8'));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', '=', (int)(is_numeric($filters['is_active']) ? $filters['is_active'] : 0));
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('created_at', 'DESC')->limit($limit)->offset($offset)->get()
        ];
    }

    /**
     * تمام Placements حاصل کریں
     */
    /** @return list<\stdClass> */
    public function getAllPlacements(): array
    {
        return $this->placementModel->all();
    }

    /**
     * Placement کو ID سے تلاش کریں
     */
    public function findPlacement(int $id): ?object
    {
        $placement = $this->toObject($this->placementModel->find($id));
        if (!$placement) { 
        return null;
        }
        return $placement;
    }

    /**
     * Placement کو slug سے تلاش کریں
     */
    public function findPlacementBySlug(string $slug): ?object
    {
        return $this->placementModel->findBySlug($slug);
    }

    /**
     * تمام فعال Placements حاصل کریں
     */
    /** @return list<\stdClass> */
    public function getActivePlacements(): array
    {
        return $this->db->table('banner_placements')
            ->where('is_active', '=', true)
            ->orderBy('display_order', 'ASC')
            ->get();
    }

    /**
     * تخلیه بافر بازدیدها از Redis به دیتابیس
     * باید توسط کرون‌جاب دوره‌ای (مثلاً هر ۵ دقیقه) صدا زده شود
     */
    public function flushImpressionsBuffer(): int
    {
        $redis = $this->cache->redis();
        if ($redis === null) {
            return 0;
        }
        $key = $this->cache->redisKey('banner_impressions_buffer');
        $processingKey = $key . ':processing:' . uniqid('', true);

        // Finding #9 Fix: Atomic Redis drain via key rename prevents race conditions between concurrent workers
        try {
            if (!$redis->rename($key, $processingKey)) {
                return 0;
            }
        } catch (\Throwable $e) {
            return 0;
        }

        $rawData = $redis->hGetAll($processingKey);
        $data = is_array($rawData) ? $rawData : [];

        if ($data === []) {
            try { $redis->del($processingKey); } catch (\Throwable $ignore) {}
            return 0;
        }

        $processed = 0;
        foreach ((array)$data as $bannerId => $count) {
            $count = (int)$count;
            if ($count <= 0) continue;

            $result = $this->adsBudgetSettlementService->consumeDeliveryBudget((int)$bannerId, 'banner', 'impression', $count, null, [
                'source' => 'banner_service.flushImpressionsBuffer',
                'buffer_count' => $count,
            ]);

            if (empty($result['success']) && (($result['code'] ?? '') !== 'budget_exhausted')) {
                // If settlement failed, return unconsumed count back to primary Redis buffer
                $redis->hIncrBy($key, (string)$bannerId, $count);
            } else {
                $processed++;
            }
        }

        try { $redis->del($processingKey); } catch (\Throwable $ignore) {}
        return $processed;
    }

    /**
     * Banner stats حاصل کریں
     */
    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $totalBanners = $this->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at')->count();
        $activeBanners = $this->db->table('ads')->where('type', '=', 'banner')->where('is_active', '=', true)->whereNull('deleted_at')->count();
        $pendingBanners = $this->db->table('ads')->where('type', '=', 'banner')->where('status', '=', 'pending')->whereNull('deleted_at')->count();
        $summary = $this->toObject($this->db->fetch("SELECT COALESCE(SUM(impressions),0) AS total_impressions, COALESCE(SUM(clicks),0) AS total_clicks FROM ads WHERE type = 'banner' AND deleted_at IS NULL"));
        $totalImpressions = (int)($summary->total_impressions ?? 0);
        $totalClicks = (int)($summary->total_clicks ?? 0);

        return [
            'total' => $totalBanners,
            'active' => $activeBanners,
            'pending' => $pendingBanners,
            'total_banners' => $totalBanners,
            'active_banners' => $activeBanners,
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
        ];
    }
}
