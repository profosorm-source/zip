<?php
namespace App\Controllers\Admin;
use App\Models\Ads;
use App\Services\Shared\DashboardStatsService;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * Admin — مدیریت آگهی‌های SEO
 */
class SeoAdController extends BaseAdminController
{
    private Ads $model;
    private DashboardStatsService $analytics;
    private AdsBudgetSettlementService $adsBudgetSettlement;

    public function __construct(
        Ads $m,
        DashboardStatsService $a,
        AdsBudgetSettlementService $adsBudgetSettlement,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->model = $m;
        $this->analytics = $a;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
    }

    public function index(): void
    {
        $status = $this->request->str('status', '');
        // استفاده از فیلتر نوع seo در متد جدید adminList
        $items = $this->model->adminList('seo', $status, 30, 0);
        
        // آمار کلی با Shared Analytics از جدول یکپارچه ads
        $overview = $this->analytics->getTrend('seo_executions', 'created_at', 30);
        $totalAds = $this->analytics->getCount('ads', ['type' => 'seo']);
        $activeAds = $this->analytics->getCount('ads', ['type' => 'seo', 'status' => 'active']);
        
        view('admin.seo-ad.index', [
            'title' => 'مدیریت آگهی‌های SEO',
            'items' => $items,
            'status' => $status,
            'stats' => [
                'total_ads' => $totalAds,
                'active_ads' => $activeAds,
                'trend' => $overview
            ],
        ]);
    }

    public function approve(): void
    {
        // PRIMARY: SEO admin approval delegates to unified Ads action.
        $id = $this->request->int('id');
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'approve', (int)user_id(), 'تأیید از پنل تخصصی SEO');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'عملیات انجام نشد.');
        redirect(url('/admin/seo-ad'));
    }

    public function reject(): void
    {
        // PRIMARY: reject must refund through unified Ads budget settlement.
        $id = $this->request->int('id');
        $reason = trim($this->request->str('reason', 'رد از پنل تخصصی SEO'));
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'reject', (int)user_id(), $reason !== '' ? $reason : 'رد از پنل تخصصی SEO');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'رد کمپین انجام نشد.');
        redirect(url('/admin/seo-ad'));
    }

    public function pause(): void
    {
        // PRIMARY: pause delegates to unified Ads action so status/is_active stay consistent.
        $id = $this->request->int('id');
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'pause', (int)user_id(), 'توقف از پنل تخصصی SEO');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'توقف کمپین انجام نشد.');
        redirect(url('/admin/seo-ad'));
    }
}
