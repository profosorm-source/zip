<?php
namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Services\Settings\AppSettings;

/**
 * Maintenance Mode Controller
 * 
 * مدیریت حالت تعمیر
 */
class MaintenanceController extends BaseAdminController
{
    private AppSettings $appSettings;

    public function __construct(
        AppSettings $appSettings,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->appSettings = $appSettings;
    }

    /** فعالسازی Maintenance Mode */
    public function enable(): void
    {
        $adminId = $this->requireAdminId();

        $this->appSettings->set('maintenance_mode', 'true');
        $this->appSettings->clearCache();

        $this->logger->info('Maintenance mode enabled', [
            'by_user' => $adminId
        ]);
        
        if (function_exists('is_ajax') && is_ajax()) {
            $this->response->json(['success' => true, 'message' => 'حالت تعمیر فعال شد.']);
            return;
        }
        
        $this->session->setFlash('حالت تعمیر فعال شد.', 'success');
        $this->response->back();
    }

    /** غیرفعالسازی Maintenance Mode */
    public function disable(): void
    {
        $adminId = $this->requireAdminId();

        $this->appSettings->set('maintenance_mode', 'false');
        $this->appSettings->clearCache();

        $this->logger->info('Maintenance mode disabled', [
            'by_user' => $adminId
        ]);
        
        if (function_exists('is_ajax') && is_ajax()) {
            $this->response->json(['success' => true, 'message' => 'حالت تعمیر غیرفعال شد.']);
            return;
        }
        
        $this->session->setFlash('حالت تعمیر غیرفعال شد.', 'success');
        $this->response->back();
    }

    /** وضعیت فعلی */
    public function status(): void
    {
        $isEnabled = $this->appSettings->get('maintenance_mode', 'false') === 'true';
        
        $this->response->json([
            'success' => true,
            'maintenance_mode' => $isEnabled
        ]);
    }
}
