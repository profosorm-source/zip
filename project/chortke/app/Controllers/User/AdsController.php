<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\AdSystemManager;
use App\Models\Ads;
use App\Models\BannerPlacement;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\UploadService;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * AdsController - The ultimate command center for Unified Modern Advertising (Unified UI).
 */
class AdsController extends BaseController
{
    private AdSystemManager $adManager;
    private Ads $adModel;
    private BannerPlacement $placementModel;
    private CustomTaskModerationService $moderationService;
    private UploadService $uploadService;
    private AdsBudgetSettlementService $adsBudgetSettlement;

    public function __construct(
        AdSystemManager $adManager,
        Ads $adModel,
        BannerPlacement $placementModel,
        CustomTaskModerationService $moderationService,
        UploadService $uploadService,
        AdsBudgetSettlementService $adsBudgetSettlement,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        $this->adManager = $adManager;
        $this->adModel = $adModel;
        $this->placementModel = $placementModel;
        $this->moderationService = $moderationService;
        $this->uploadService = $uploadService;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Unified Dashboard / "My Ads" Listing.
     */
    public function index(): void
    {
        $userId = (int)user_id();
        
        $ads = $this->adManager->getUserAds($userId);
        $summaryData = $this->adManager->getAdSummary($userId);

        view('user.ads.index', compact('ads', 'summaryData'));
    }

    /**
     * The AJAX Ad Wizard - Single Entry Point.
     */
    public function create(): void
    {
        // دریافت Placements از Model
        $placements = $this->placementModel->where('is_active', '=', 1)->get();
        
        view('user.ads.create', compact('placements'));
    }

    /**
     * High-speed AJAX storage directly forwarding payload to mapped Adapter strategies.
     */
    public function store(): void
    {
        header('Content-Type: application/json');
        $data = is_array($this->request->input()) ? $this->request->input() : [];
        $userId = (int)user_id();

        $type = $data['ad_type'] ?? null;

        if (!$type) {
            echo json_encode(['success' => false, 'message' => 'نوع تبلیغ نامعتبر است.']);
            return;
        }

        $data = $this->normalizeAdPayload((string)$type, $data);
        if ((string)$type === 'banner' && !empty($_FILES['image']['name'])) {
            try {
                $upload = $this->uploadService->upload($_FILES['image'], 'banners', ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024);
                $data['image_path'] = $upload['path'];
            } catch (\Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'آپلود تصویر بنر انجام نشد: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        try {
            // Unified ad creation via AdSystemManager (Saga + Escrow)
            $data['user_id'] = $userId;
            $result = $this->adManager->create((string)$type, $userId, $data);

            echo json_encode([
                'success' => true,
                'message' => 'تبلیغ با موفقیت و امنیت کامل ثبت شد.',
                'ad_id' => $result['ad_id'] ?? null
            ]);
        } catch (\Exception $e) {
            $this->logger->error('ads.store.failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
            $isDomainError = $e instanceof \Core\Exceptions\BusinessException || $e instanceof \InvalidArgumentException || $e instanceof \Core\Exceptions\ValidationException;
            $userMsg = $isDomainError ? $e->getMessage() : 'بروز خطا در ثبت آگهی. لطفاً ورودی‌ها را بررسی کرده و مجدداً تلاش کنید.';
            echo json_encode([
                'success' => false, 
                'message' => $userMsg
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Pause/Resume interaction directly from the list view.
     */
    public function toggleStatus(): void
    {
        header('Content-Type: application/json');
        $adId = $this->request->int('ad_id');
        $userId = (int)user_id();

        $result = $this->adManager->toggleAdStatus($adId, $userId);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    public function cancel(): void
    {
        header('Content-Type: application/json');
        $adId = int_value($this->request->param('id'));
        $userId = (int)user_id();
        $reason = trim($this->request->str('reason', 'لغو توسط تبلیغ‌دهنده'));
        $result = $this->adManager->cancelAd($adId, $userId, $reason !== '' ? $reason : 'لغو توسط تبلیغ‌دهنده');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Ultimate Unified Analytics & Execution Log.
     */
    public function show(): void
    {
        $adId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $ad = $this->adModel->where('id', '=', $adId)
            ->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->first();
        
        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/ads'));
        }

        $executions = $this->adManager->getAdExecutions($adId, $ad->type);
        $stats = []; // Detailed stats logic should be in a service if needed
        $finance = $this->adsBudgetSettlement->financeSnapshot($adId, (string)$ad->type);

        // آمار engagement نوتیفیکیشن برای تبلیغ‌های type=notification
        $adStats = [];
        if (($ad->type ?? '') === 'notification') {
            $notifModel = app(\App\Models\Notification::class);
            $adStats = $notifModel->getAdAnalytics($adId, 30) ?: [];
        }

        view('user.ads.show', compact('ad', 'executions', 'stats', 'finance', 'adStats'));
    }


    /**
     * آمار engagement نوتیفیکیشنِ یک تبلیغِ تبلیغ‌دهنده (JSON برای فرانت/موبایل)
     * GET /ads/{id}/notification-stats
     */
    public function notificationStats(): void
    {
        $userId = (int)user_id();
        $adId   = (int)($this->request->param('id') ?? 0);

        $ad = $this->adModel->where('id', '=', $adId)
            ->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$ad) {
            $this->response->json(['success' => false, 'message' => 'آگهی یافت نشد.'], 404);
            return;
        }
        if (($ad->type ?? '') !== 'notification') {
            $this->response->json(['success' => false, 'message' => 'این آگهی از نوع نوتیفیکیشن نیست.'], 422);
            return;
        }

        $days      = max(1, min(365, $this->request->int('days', 30)));
        $notifModel = app(\App\Models\Notification::class);

        $this->response->json([
            'success' => true,
            'ad_id'   => $adId,
            'days'    => $days,
            'stats'   => $notifModel->getAdAnalytics($adId, $days) ?: [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeAdPayload(string $type, array $data): array
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(str_value($data['currency']));
            if (in_array($data['currency'], ['irr', 'rial'], true)) {
                $data['currency'] = 'irt';
            }
        } else {
            $data['currency'] = 'irt';
        }

        if (!empty($data['target_link']) && empty($data['link'])) {
            $data['link'] = $data['target_link'];
        }
        if (!empty($data['link']) && empty($data['target_url'])) {
            $data['target_url'] = $data['link'];
        }

        if ($type === 'custom_task') {
            $count = int_value($data['total_count'] ?? $data['total_quantity'] ?? $data['quantity'] ?? 1);
            $price = str_value($data['price_per_task'] ?? 0);
            $data['total_count'] = max(1, $count);
            $data['total_quantity'] = $data['total_count'];
            if (empty($data['total_budget']) && is_numeric($price) && bccomp($price, '0', 8) > 0) {
                $data['total_budget'] = bcmul($price, (string)$data['total_count'], 8);
            }
            $data['proof_type'] = str_value($data['proof_type'] ?? 'text');
            $data['proof_description'] = str_value($data['proof_description'] ?? 'مدرک انجام تسک را طبق توضیح تسک ارسال کنید.');
            $data['deadline_hours'] = int_value($data['deadline_hours'] ?? 24);
        }

        if ($type === 'seo') {
            if (!empty($data['target_link']) && empty($data['site_url'])) {
                $data['site_url'] = $data['target_link'];
            }
            if (!empty($data['site_url']) && empty($data['target_url'])) {
                $data['target_url'] = $data['site_url'];
            }
            $data['budget'] = str_value($data['budget'] ?? $data['total_budget'] ?? 0);
            $data['total_budget'] = $data['budget'];
            $data['min_payout'] = str_value($data['min_payout'] ?? $data['price_per_click'] ?? 0);
            $seoPricePerClick = str_value($data['price_per_click'] ?? 0);
            $seoMaxDefault = (is_numeric($data['min_payout']) && is_numeric($seoPricePerClick) && bccomp($data['min_payout'], $seoPricePerClick, 8) >= 0) ? $data['min_payout'] : $seoPricePerClick;
            $data['max_payout'] = str_value($data['max_payout'] ?? $seoMaxDefault);
            $data['target_duration'] = int_value($data['target_duration'] ?? 60);
            $data['min_score'] = int_value($data['min_score'] ?? 40);
            $data['max_per_day'] = int_value($data['max_per_day'] ?? 10);
        }

        if (in_array($type, ['social_task', 'adtube'], true)) {
            $count = int_value($data['total_count'] ?? $data['quantity'] ?? 1);
            $price = str_value($data['price_per_task'] ?? 0);
            $data['total_count'] = max(1, $count);
            $data['total_budget'] = is_numeric($price) ? bcmul($price, (string)$data['total_count'], 8) : '0';
        }

        if (in_array($type, ['banner', 'notification'], true)) {
            $data['budget'] = str_value($data['budget'] ?? $data['total_budget'] ?? 0);
            $data['total_budget'] = $data['budget'];
        }

        return $data;
    }

    /**
     * تأیید submission یک تسک سفارشی (Unified — از CustomTaskAdController منتقل شد)
     */
    public function approveSubmission(): string
    {
        $userId = (int)user_id();
        $submissionId = int_value($this->request->param('id') ?? $this->request->post('submission_id') ?? 0);
        $note = trim($this->request->str('note'));

        if ($submissionId <= 0) {
            $this->session->setFlash('error', 'شناسه submission نامعتبر است.');
            return back();
        }

        $result = $this->moderationService->reviewSubmission($submissionId, $userId, 'approve', $note);

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        return back();
    }

    /**
     * رد submission یک تسک سفارشی (Unified — از CustomTaskAdController منتقل شد)
     */
    public function rejectSubmission(): string
    {
        $userId = (int)user_id();
        $submissionId = int_value($this->request->param('id') ?? $this->request->post('submission_id') ?? 0);
        $reason = trim($this->request->str('reason'));

        if ($submissionId <= 0) {
            $this->session->setFlash('error', 'شناسه submission نامعتبر است.');
            return back();
        }

        if (empty($reason)) {
            $this->session->setFlash('error', 'دلیل رد الزامی است.');
            return back();
        }

        $result = $this->moderationService->reviewSubmission($submissionId, $userId, 'reject', $reason);

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        return back();
    }
}

