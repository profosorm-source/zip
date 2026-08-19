<?php

namespace App\Controllers\User;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Services\Search\SearchOrchestrator;
use App\Services\Seo\SeoService;
use App\Services\Shared\DashboardStatsService;

/**
 * SeoController — انجام تسک‌های SEO توسط کاربران (Workers)
 */
class SeoController extends BaseUserController
{
    private Ads $adModel;
    private SeoExecution $executionModel;
    private SeoService $seoService;

    public function __construct(
        Ads $adModel,
        SeoExecution $executionModel,
        SeoService $seoService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->seoService = $seoService;
    }

    /** لیست آگهی‌های فعال */
    public function index(): void
    {
        $userId = (int)user_id();
        $search = trim($this->request->str('search'));
        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $result = $this->adModel->searchAdminTasks($search, ['type' => 'seo', 'status' => 'active'], $perPage, $offset);
        $ads = $result['items'] ?? [];
        $total = $result['total'] ?? 0;

        // آمار کاربر
        $stats = $this->executionModel->getUserStats($userId);
        $todayCount = $this->executionModel->countByUserToday($userId);

        view('user.seo.index', [
            'title' => 'تسک‌های SEO',
            'ads' => $ads,
            'stats' => [
                'total' => $stats,
                'today' => $todayCount
            ],
            'search' => $search,
            'page' => $page,
            'totalPages' => (int)ceil($total / $perPage),
            'total' => $total,
        ]);
    }

    /** شروع تسک (AJAX) */
    public function start(): void
    {
        $body = $this->request->body();
        $adId = int_value($body['ad_id'] ?? 0);
        $userId = (int)user_id();

        if ($adId <= 0) {
            $this->response->json(['success' => false, 'message' => 'آگهی نامعتبر']);
            return;
        }

        $result = $this->seoService->startTask($adId, $userId);
        if (!empty($result['success']) && !empty($result['execution_id'])) {
            $result['redirect_url'] = url('/seo/' . int_value($result['execution_id']) . '/execute');
        }
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    /** صفحه اجرای تسک (WebView) */
    public function execute(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $execution = $this->executionModel->findByUser($executionId, $userId);

        if (!$execution) {
            $this->session->setFlash('error', 'تسک یافت نشد');
            redirect(url('/seo'));
        }

        if ($execution->status !== 'started') {
            $this->session->setFlash('error', 'این تسک قابل انجام نیست');
            redirect(url('/seo'));
        }

        $ad = $this->adModel->find($execution->ad_id);

        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد');
            redirect(url('/seo'));
        }

        view('user.seo.execute', [
            'title' => 'اجرای تسک',
            'execution' => $execution,
            'ad' => $ad,
        ]);
    }

    /** تکمیل تسک (AJAX) */
    public function complete(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();

        foreach (['duration', 'scroll_depth', 'interactions'] as $field) {
            if (!array_key_exists($field, $body) || !is_numeric($body[$field])) {
                $this->response->json(['success' => false, 'message' => "فیلد {$field} باید عددی باشد."], 422);
                return;
            }
        }
        $interactionTypes = $body['interaction_types'] ?? [];
        if (!is_array($interactionTypes) || array_filter($interactionTypes, 'is_string') !== $interactionTypes) {
            $this->response->json(['success' => false, 'message' => 'ساختار interaction_types نامعتبر است.'], 422);
            return;
        }
        $clientMode = $body['client_mode'] ?? 'web';
        if (!is_string($clientMode) || !in_array($clientMode, ['web', 'mobile_web', 'mobile_app'], true)) {
            $this->response->json(['success' => false, 'message' => 'client_mode نامعتبر است.'], 422);
            return;
        }

        $engagementData = [
            'duration' => int_value($body['duration'] ?? 0),
            'active_time' => int_value($body['active_time'] ?? $body['duration'] ?? 0),
            'scroll_depth' => float_value($body['scroll_depth'] ?? $body['scrollDepth'] ?? 0),
            'interactions' => int_value($body['interactions'] ?? 0),
            'target_opened' => !empty($body['target_opened']) ? 1 : 0,
            'focus_blur_count' => int_value($body['focus_blur_count'] ?? 0),
            'client_mode' => $clientMode,
            'interaction_types' => array_values($interactionTypes),
            'behavior' => [
                'scroll_speed' => float_value($body['scroll_speed'] ?? 0),
                'mouse_pattern' => str_value($body['mouse_pattern'] ?? 'normal'),
                'pause_count' => int_value($body['pause_count'] ?? 0),
                'interaction_types' => array_values($interactionTypes),
                'target_opened' => !empty($body['target_opened']) ? 1 : 0,
                'focus_blur_count' => int_value($body['focus_blur_count'] ?? 0),
                'client_mode' => $clientMode,
            ],
        ];

        $result = $this->seoService->completeTask($executionId, $userId, $engagementData);
        $this->response->json($result);
    }

    /** لغو تسک (AJAX) */
    public function cancel(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $result = $this->seoService->cancelTask($executionId, $userId);
        $this->response->json($result);
    }

    /** تاریخچه تسک‌ها */
    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1, $this->request->int('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $executions = $this->executionModel->getByUser($userId, $limit, $offset);
        $total = $this->executionModel->countByUser($userId);
        $totalPages = ceil($total / $limit);

        view('user.seo.history', [
            'title' => 'تاریخچه تسک‌ها',
            'executions' => $executions,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    /** جزئیات یک تسک انجام شده */
    public function showExecution(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $execution = $this->executionModel->findByUser($executionId, $userId);

        if (!$execution) {
            redirect(url('/seo/history'));
        }

        $ad = $this->adModel->find($execution->ad_id);

        view('user.seo.show-execution', [
            'title' => 'جزئیات تسک',
            'execution' => $execution,
            'ad' => $ad,
        ]);
    }

    /** ثبت گزارش برای تسک سئو (AJAX) */
    public function report(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();
        $reason = trim(str_value($body['reason'] ?? ''));
        $description = trim(str_value($body['description'] ?? ''));

        if (empty($reason)) {
            $this->response->json(['success' => false, 'message' => 'دلیل گزارش الزامی است']);
            return;
        }

        $execution = $this->executionModel->findByUser($executionId, $userId);
        if (!$execution) {
            $this->response->json(['success' => false, 'message' => 'تسک یافت نشد']);
            return;
        }

        $result = $this->seoService->reportTask($userId, $execution->ad_id, $reason, $description);
        $this->response->json($result);
    }

    /** ثبت امتیاز برای تسک سئو (AJAX) */
    public function rate(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();
        $stars = int_value($body['stars'] ?? 0);
        $comment = trim(str_value($body['comment'] ?? ''));

        if ($stars < 1 || $stars > 5) {
            $this->response->json(['success' => false, 'message' => 'امتیاز نامعتبر است']);
            return;
        }

        $execution = $this->executionModel->findByUser($executionId, $userId);
        if (!$execution) {
            $this->response->json(['success' => false, 'message' => 'تسک یافت نشد']);
            return;
        }

        $result = $this->seoService->rateTask($userId, $execution->ad_id, $stars, $comment);
        $this->response->json($result);
    }
}
