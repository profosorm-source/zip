<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Analytics\AnalyticsService;
use Core\Logger;

/**
 * AdminAnalyticsController
 * داشبورد تحلیلات برای مدیران
 */
class AdminAnalyticsController extends BaseAdminController
{
    private AnalyticsService $analyticsService;
    // $logger inherited from parent

    public function __construct(AnalyticsService $analyticsService, Logger $logger) {
        parent::__construct(null, null, null, null, $logger);
        $this->analyticsService = $analyticsService;
        $this->logger          = $logger;
    }

    /**
     * Dashboard اصلی - نمای کلی سیستم
     */
    public function dashboard(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
                $period = 'month';
            }

            $data = $this->analyticsService->getComprehensiveDashboard($period);

            view('admin.analytics.dashboard', [
                'title'  => 'داشبورد تحلیلات',
                'period' => $period,
                'data'   => $data,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.dashboard.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری داشبورد');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات کاربران
     */
    public function users(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $userMetrics = $this->analyticsService->getUserMetrics($period);
            $growthChart = $this->analyticsService->getUserGrowthChart(30);

            view('admin.analytics.users', [
                'title'       => 'تحلیلات کاربران',
                'period'      => $period,
                'metrics'     => $userMetrics,
                'growth_data' => json_encode($growthChart),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.users.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات تراکنش‌ها
     */
    public function transactions(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $transactionMetrics = $this->analyticsService->getTransactionMetrics($period);
            $volumeChart = $this->analyticsService->getTransactionVolumeChart(30);

            view('admin.analytics.transactions', [
                'title'        => 'تحلیلات تراکنش‌ها',
                'period'       => $period,
                'metrics'      => $transactionMetrics,
                'volume_data'  => json_encode($volumeChart),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.transactions.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات وظایف اجتماعی
     */
    public function socialTasks(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $socialMetrics = $this->analyticsService->getSocialTaskMetrics($period);

            view('admin.analytics.social-tasks', [
                'title'   => 'تحلیلات وظایف اجتماعی',
                'period'  => $period,
                'metrics' => $socialMetrics,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.social_tasks.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات وظایف سفارشی
     */
    public function customTasks(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $customMetrics = $this->analyticsService->getCustomTaskMetrics($period);

            view('admin.analytics.custom-tasks', [
                'title'   => 'تحلیلات وظایف سفارشی',
                'period'  => $period,
                'metrics' => $customMetrics,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.custom_tasks.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات امتیازات و نظرات
     */
    public function ratings(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $ratingMetrics = $this->analyticsService->getRatingMetrics($period);

            view('admin.analytics.ratings', [
                'title'   => 'تحلیلات امتیازات',
                'period'  => $period,
                'metrics' => $ratingMetrics,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.ratings.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * تحلیلات درآمد
     */
    public function revenue(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            
            $revenueData = $this->analyticsService->getRevenueBreakdown($period);

            view('admin.analytics.revenue', [
                'title'   => 'تحلیلات درآمد',
                'period'  => $period,
                'metrics' => $revenueData,
                'data'    => $revenueData,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.revenue.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری تحلیلات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * سلامت سیستم
     */
    public function systemHealth(): void
    {
        try {
            $period = $this->request->str('period', 'month');
            $health = $this->analyticsService->getSystemHealth();

            view('admin.analytics.system-health', [
                'title'   => 'سلامت سیستم',
                'period'  => $period,
                'metrics' => $health,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('analytics.system_health.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بارگذاری اطلاعات');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * API برای بارگذاری داده‌های نمودار
     */
    public function getChartData(): void
    {
        try {
            $type   = $this->request->get('type');
            $period = $this->request->str('period', 'month');

            // Validate chart type
            $allowedTypes = ['user_growth', 'transactions'];
            if (!$type || !in_array($type, $allowedTypes, true)) {
                $this->response->json([
                    'success' => false,
                    'error'   => 'نوع نمودار نامعتبر است',
                    'allowed' => $allowedTypes
                ], 400);
                return;
            }

            $data = match($type) {
                'user_growth'      => $this->analyticsService->getUserGrowthChart(30),
                'transactions'     => $this->analyticsService->getTransactionVolumeChart(30),
            };

            $this->response->json(['success' => true, 'data' => $data]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('analytics.chart_data.failed', ['error' => $e->getMessage()]);
            $this->response->json(['success' => false, 'error' => 'خطا در بارگذاری داده'], 500);
        }
    }

    /**
     * صادر کردن گزارش
     */
    public function exportReport(): void
    {
        $format = $this->request->post('format') ?? 'pdf';
        $period = $this->request->post('period') ?? 'month';

        // Generate report based on format
        // در صورت شکست، Exception به GlobalExceptionMiddleware می‌رسد و
        // پاسخ استاندارد JSON/HTML خطا را تولید می‌کند (بدون نشت پیام خام).
        match($format) {
            'csv'   => $this->analyticsService->generateCSV(),
            'excel' => $this->analyticsService->generateExcel(),
            'pdf'   => $this->analyticsService->generatePDF(),
            default => throw new \InvalidArgumentException('فرمت نامعتبر'),
        };

        $this->logger->info('analytics.report_exported', [
            'format' => $format,
            'period' => $period,
            'admin_id' => (int) (int)$this->userId(),
        ]);
    }
}
