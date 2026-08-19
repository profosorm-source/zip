<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Ads;
use App\Services\Search\SearchOrchestrator;
use App\Services\Analytics\AnalyticsService;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * AdminAdsController — Unified Advertising Dashboard for Administrators.
 *
 * Provides a single-pane view of ALL advertisements across all types:
 * social_task, custom_task, seo, adtube, banner, notification.
 *
 * Detail views and type-specific operations are DELEGATED to dedicated
 * sub-controllers (AdminSocialTaskController, AdminAdTaskController, etc.)
 * to avoid God Class anti-pattern.
 */
class AdminAdsController extends BaseAdminController
{
    private Ads $adsModel;
    private AnalyticsService $analyticsService;
    private AdsBudgetSettlementService $adsBudgetSettlement;

    public function __construct(
        Ads $adsModel,
        AnalyticsService $analyticsService,
        AdsBudgetSettlementService $adsBudgetSettlement,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->adsModel = $adsModel;
        $this->analyticsService = $analyticsService;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
    }

    /**
     * Unified listing: /admin/ads
     * Filters: ?type= | ?status= | ?search= | ?user_id= | ?date_from= | ?date_to=
     */
    public function index(): void
    {
        $page   = max(1, $this->request->int('page', 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $filters = [
            'type'      => $this->request->get('type') ?? '',
            'status'    => $this->request->get('status') ?? '',
            'search'    => trim($this->request->str('search', '')),
            'user_id'   => $this->request->get('user_id') ?? '',
            'date_from' => $this->request->get('date_from') ?? '',
            'date_to'   => $this->request->get('date_to') ?? '',
        ];

        // Unified count & list from canonical ads table.
        // جستجو/user/date در مدل adminList قدیمی اعمال نمی‌شد؛ اینجا از مسیر canonical searchAdminTasks
        // استفاده می‌کنیم تا leftover فیلترهای UI بی‌اثر نماند.
        $advancedFilters = [
            'type' => $filters['type'],
            'status' => $filters['status'],
            'user_id' => $filters['user_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ];
        $result = $this->adminSearchAds($filters['search'], $advancedFilters, $limit, $offset);
        $items = $result['items'];
        $total = $result['total'];

        // Overview stats across all types
        $overview = $this->buildOverviewStats();

        view('admin.ads.index', [
            'title'      => 'مدیریت یکپارچه تبلیغات',
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => (int)ceil($total / $limit),
            'filters'    => $filters,
            'overview'   => $overview,
            'statusLabels'  => $this->adsModel->statusLabels(),
            'statusClasses' => $this->adsModel->statusClasses(),
            'typeLabels'    => $this->typeLabels(),
        ]);
    }

    /**
     * Show any ad regardless of type.
     * Delegates to type-specific controller for detail operations if needed.
     */
    public function show(): void
    {
        $id = $this->request->int('id');
        $ad = $this->adsModel->find($id);

        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/admin/ads'));
        }

        // Gather execution counts per type (if applicable)
        $executionCount = 0;
        $executionStats = [];

        // Redirect to type-specific admin controller for deep operations
        $typeDetailUrl = match ($ad->type) {
            'custom_task' => url('/admin/custom-tasks/' . $id),
            'social_task' => url('/admin/social-tasks/' . $id),
            'seo'         => url('/admin/seo-ad'),
            'banner'      => url('/admin/banners/' . $id),
            default       => null,
        };

        $finance = $this->adsBudgetSettlement->financeSnapshot($id, (string)$ad->type);

        view('admin.ads.show', [
            'title'          => 'جزئیات آگهی #' . $id,
            'ad'             => $ad,
            'typeDetailUrl'  => $typeDetailUrl,
            'executionCount' => $executionCount,
            'executionStats' => $executionStats,
            'finance'        => $finance,
            'statusLabels'   => $this->adsModel->statusLabels(),
            'statusClasses'  => $this->adsModel->statusClasses(),
            'typeLabels'     => $this->typeLabels(),
        ]);
    }

    /**
     * Bulk status change (approve / reject / pause / resume / delete).
     * Only generic operations; type-specific logic (e.g., payout on approval)
     * should be handled by the respective sub-controller.
     */
    public function bulkAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $ids    = $this->request->post('ids') ?? [];
        $action = $this->request->str('action');
        $reason = trim($this->request->str('reason'));

        if (empty($ids) || !is_array($ids) || !in_array($action, ['approve','reject','pause','resume','cancel','delete'], true)) {
            echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $updated = 0;
        $failed = [];
        $adminId = (int)user_id();
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $result = $this->adsBudgetSettlement->applyAdminAction($id, $action, $adminId, $reason);
            if (!empty($result['success'])) {
                $updated++;
            } else {
                $failed[] = ['id' => $id, 'message' => $result['message'] ?? 'ناموفق'];
            }
        }

        $this->logger->activity('admin.ads.bulk', 'عملیات گروهی روی آگهی‌ها', $adminId, [
            'action' => $action, 'count' => $updated, 'failed' => count($failed)
        ]);

        echo json_encode([
            'success' => $updated > 0 && empty($failed),
            'partial' => $updated > 0 && !empty($failed),
            'updated' => $updated,
            'failed' => $failed,
            'message' => empty($failed) ? 'عملیات گروهی انجام شد.' : 'بخشی از عملیات انجام نشد.'
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Single unified admin action for one ad.
     */
    public function action(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $id = $this->request->int('id');
        $action = $this->request->str('action');
        $reason = trim($this->request->str('reason'));
        $result = $this->adsBudgetSettlement->applyAdminAction($id, $action, (int)user_id(), $reason);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX: Unified ad statistics for dashboard charts.
     */
    public function stats(): void
    {
        header('Content-Type: application/json');

        $days = min(90, max(7, $this->request->int('days', 30)));

        $stats = [
            'by_type'   => $this->analyticsService->getAdsByTypeStats($days),
            'by_status' => $this->analyticsService->getAdsByStatusStats(),
            'budget'    => $this->analyticsService->getAdsBudgetStats($days),
        ];

        echo json_encode(['success' => true, 'stats' => $stats]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function buildOverviewStats(): array
    {
        $total     = $this->adsModel->adminCount('', '');
        $active    = $this->adsModel->adminCount('', 'active');
        $pending   = $this->adsModel->adminCount('', 'pending');
        $completed = $this->adsModel->adminCount('', 'completed');

        return [
            'total'     => $total,
            'active'    => $active,
            'pending'   => $pending,
            'completed' => $completed,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function adminSearchAds(string $q = '', array $filters = [], int $limit = 30, int $offset = 0): array
    {
        // delegate به Ads::searchAdminWithUser() — Raw SQL + getDb() حذف شد
        return $this->adsModel->searchAdminWithUser($q, $filters, $limit, $offset);
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        return [
            'social_task'  => 'شبکه‌های اجتماعی',
            'custom_task'  => 'تسک سفارشی',
            'seo'          => 'سئو و کلیک',
            'adtube'       => 'AdTube',
            'banner'       => 'بنر',
            'notification' => 'نوتیفیکیشن',
        ];
    }
}
