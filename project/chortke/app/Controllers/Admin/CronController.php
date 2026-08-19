<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Scheduler;

/**
 * CronController — مدیریت Cron Jobs
 */
class CronController extends BaseAdminController
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->scheduler = $scheduler;
    }

    public function index(): void
    {
        $this->requirePermission('admin.view_cron_jobs');
        $this->view('admin/cron/index', ['title' => 'مدیریت Cron Jobs']);
    }

    /**
     * اجرای دستی جاب‌ها
     * ⚠️ توجه: این متد باید فقط تحت شرایط خاص و با مجوز بالا اجرا شود.
     */
    public function run(): void
    {
        $this->requirePermission('admin.execute_cron_jobs');

        if (!$this->request->isPost()) {
            $this->jsonError('متد نامعتبر است', [], 405);
        }

        try {
            $this->logger->info('admin.cron_manual_trigger', [
                'admin_id' => (int)$this->userId(),
                'ip' => $this->request->ip()
            ]);

            // پشتیبانی از اجرای یک جاب خاص اگر ارسال شده باشد
            $jobName = $this->request->get('job');

            // استفاده از Scheduler برای اجرای جاب‌ها به صورت امن
            $results = [];
            if ($jobName) {
                // اجرای یک جاب خاص
                $result = $this->scheduler->executeJobByName(str_value($jobName));
                $results[$jobName] = $result;
            } else {
                // اجرای تمام جاب‌های در انتظار
                $results = $this->scheduler->run();
            }

            // هندل کردن وضعیت skipped یا خطا
            if (empty($results)) {
                 $results = ['system' => ['status' => 'skipped', 'reason' => 'no_jobs_to_execute']];
            }
            
            $this->jsonSuccess('Cron jobs executed successfully', [
                'results' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('admin.cron_execution_failed', [
                'error' => $e->getMessage(),
                'admin_id' => (int)$this->userId()
            ]);
            $this->jsonError('خطا در اجرای جاب‌ها: ' . $e->getMessage(), [], 500);
        }
    }
}