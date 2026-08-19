<?php

namespace App\Controllers\Admin;

use Core\Response;

use App\Services\User\UserService;
use App\Models\AccountDeletionLog;
use App\Services\User\AccountDeletionService;
use Core\Logger;
use App\Controllers\Admin\BaseAdminController;

/**
 * Controller: AccountDeletionManagementController
 * صفحه مدیریت درخواست‌های حذف حساب از طرف Admin
 */
class AccountDeletionManagementController extends BaseAdminController
{
    private UserService $userService;
    private AccountDeletionLog $deletionLogModel;
    private AccountDeletionService $deletionService;
    // $logger inherited from parent

    public function __construct(
        UserService $userService,
        AccountDeletionLog $deletionLogModel,
        AccountDeletionService $deletionService,
        Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->deletionLogModel = $deletionLogModel;
        $this->deletionService = $deletionService;
        $this->logger = $logger;
    }

    /**
     * نمایش درخواست‌های حذف معلق
     */
    public function pending(): void
    {
        try {
            // گرفتن تمام درخواست‌های معلق
            $pendingDeletions = $this->deletionLogModel->getPendingDeletions();

            // تحریک تازه‌سازی صفحه
            $data = [
                'pending_deletions' => $pendingDeletions,
                'total_count' => count($pendingDeletions),
            ];

            view('admin/account-deletion/pending', $data);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.pending.failed', [
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'خطا: دریافت درخواست‌های معلق ناموفق بود');
            redirect('/admin/dashboard');
        }
    }

    /**
     * نمایش تاریخچه حذف‌شده‌ها
     */
    public function history(): void
    {
        try {
            // گرفتن تاریخچه حذف‌شده‌ها
            $deletedAccounts = $this->deletionLogModel->getDeletedAccounts();

            $data = [
                'deleted_accounts' => $deletedAccounts,
                'total_count' => count($deletedAccounts),
            ];

            view('admin/account-deletion/history', $data);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.history.failed', [
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'خطا: دریافت تاریخچه ناموفق بود');
            redirect('/admin/dashboard');
        }
    }

    public function stats(): void
    {
        try {
            $pending = $this->deletionLogModel->getPendingDeletions();
            $deleted = $this->deletionLogModel->getDeletedAccounts();

            $totalDataSize = 0;
            foreach ($pending as $deletion) {
                $totalDataSize += 1024 * 1024; // Base estimate 1MB per user
            }

            view('admin/account-deletion/stats', [
                'stats' => [
                    'pending_count' => count($pending),
                    'deleted_count' => count($deleted),
                    'total_data_size' => $this->formatBytes($totalDataSize),
                    'expiring_soon' => count(array_filter($pending, function($d) {
                        $data = is_object($d) ? get_object_vars($d) : (is_array($d) ? $d : []);
                        $expiresAt = $data['expires_at'] ?? null;
                        return is_string($expiresAt) && strtotime($expiresAt) - time() < 86400;
                    })),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.stats.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: دریافت آمار ناموفق بود');
            redirect('/admin/dashboard');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * حذف فوری (بدون انتظار ۷ روز)
     */
    public function forceDelete(): void
    {
        try {
            $userId = $this->request->int('user_id');

            if (!$userId) {
                $this->session->setFlash('error', 'شناسه کاربر الزامی است');
                redirect('/admin/account-deletion/pending');
            }

            // بررسی وجود درخواست
            $deletion = $this->deletionLogModel->getUserDeletionRequest($userId);
            if (!$deletion) {
                $this->session->setFlash('error', 'درخواست حذف برای این کاربر یافت نشد');
                redirect('/admin/account-deletion/pending');
            }

            // حذف فوری
            $this->deletionService->deleteUserAccount($userId);

            $this->logger->info('admin.account_deletion.force_deleted', [
                'user_id' => $userId,
                'admin_id' => user_id()
            ]);

            $this->session->setFlash('success', 'حساب کاربری با موفقیت حذف شد');
            redirect('/admin/account-deletion/history');

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.force_delete.failed', [
                'error' => $e->getMessage(),
                'user_id' => $this->request->int('user_id')
            ]);
            $this->session->setFlash('error', 'خطا: حذف ناموفق بود');
            redirect('/admin/account-deletion/pending');
        }
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletion(): void
    {
        try {
            $userId = $this->request->int('user_id');

            if (!$userId) {
                $this->session->setFlash('error', 'شناسه کاربر الزامی است');
                redirect('/admin/account-deletion/pending');
            }

            // لغو درخواست
            $this->deletionService->cancelDeletion($userId);

            $this->logger->info('admin.account_deletion.cancelled', [
                'user_id' => $userId,
                'admin_id' => user_id()
            ]);

            $this->session->setFlash('success', 'درخواست حذف با موفقیت لغو شد');
            redirect('/admin/account-deletion/pending');

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.cancel.failed', [
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'خطا: لغو ناموفق بود');
            redirect('/admin/account-deletion/pending');
        }
    }

    /**
     * دریافت جزئیات کاربر برای حذف
     */
    public function getUserDetails(): void
    {
        try {
            $userId = $this->request->int('user_id');

            if (!$userId) {
                $this->response->json(['success' => false, 'error' => 'شناسه کاربر الزامی است'], 400);
                return;
            }

            $user = $this->userService->find($userId);
            if (!$user) {
                $this->response->json(['success' => false, 'error' => 'کاربر یافت نشد'], 404);
                return;
            }

            // ✅ Audit Log for admin accessing user PII
            $this->logger->warning('admin.pii.accessed', [
                'admin_id' => user_id(),
                'target_user_id' => $userId,
                'action' => 'get_user_details_for_deletion'
            ]);

            $deletion = $this->deletionLogModel->getUserDeletionRequest($userId);

            $uEmail = is_string($user->email ?? null) ? $user->email : '';
            $uMobile = is_string($user->mobile ?? null) ? $user->mobile : '';
            $uNationalId = is_string($user->national_id ?? null) ? $user->national_id : '';
            $uFullName = is_string($user->full_name ?? null) ? $user->full_name : '';
            $uUsername = is_string($user->username ?? null) ? $user->username : '';
            $uId = isset($user->id) && is_numeric($user->id) ? (int)$user->id : 0;
            $uCreatedAt = is_string($user->created_at ?? null) ? $user->created_at : '';
            $uLastActivity = is_string($user->last_activity_at ?? null) ? $user->last_activity_at : '';

            // ✅ Mask Email PII
            $maskedEmail = $uEmail;
            if (strpos($uEmail, '@') !== false) {
                [$name, $domain] = explode('@', $uEmail);
                $maskedEmail = substr($name, 0, 1) . '***@' . $domain;
            } else {
                $maskedEmail = '***';
            }

            // ✅ Mask Mobile PII
            $maskedMobile = !empty($uMobile) 
                ? substr($uMobile, 0, 4) . '***' . substr($uMobile, -2)
                : null;

            // ✅ Mask National ID PII
            $maskedNationalId = !empty($uNationalId)
                ? substr($uNationalId, 0, 3) . '****' . substr($uNationalId, -1)
                : null;

            // ✅ Access Control for Deletion Reason
            $showReason = false;
            $currentUser = user();
            if ($currentUser && isset($currentUser->role) && $currentUser->role === 'super_admin') {
                $showReason = true;
            }

            $this->response->json([
                'success' => true,
                'user' => [
                    'id' => $uId,
                    'username' => $uUsername !== '' ? $uUsername : $maskedEmail,
                    'email' => $maskedEmail,
                    'mobile' => $maskedMobile,
                    'national_id' => $maskedNationalId,
                    'full_name' => $uFullName !== '' ? $uFullName : null,
                    'created_at' => $uCreatedAt,
                    'last_activity' => $uLastActivity !== '' ? $uLastActivity : 'N/A'
                ],
                'deletion' => $deletion ? [
                    'requested_at' => $deletion->requested_at,
                    'expires_at' => $deletion->expires_at,
                    'status' => $deletion->status,
                    'reason' => $showReason ? ($deletion->reason ?? '') : 'HIDDEN'
                ] : null
            ]);
            return;

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.get_details.failed', [
                'error' => $e->getMessage()
            ]);
            $this->response->json(['success' => false, 'error' => 'خطا: دریافت اطلاعات ناموفق'], 500);
            return;
        }
    }

    /**
     * دریافت آمار حذف
     */
    public function getStats(): void
    {
        try {
            $pending = $this->deletionLogModel->getPendingDeletions();
            $deleted = $this->deletionLogModel->getDeletedAccounts();

            $totalDataSize = count($pending) * 1024 * 1024; // Base estimate 1MB per user

            $this->response->json([
                'success' => true,
                'stats' => [
                    'pending_count' => count($pending),
                    'deleted_count' => count($deleted),
                    'total_data_size' => $this->formatBytes($totalDataSize),
                    'expiring_soon' => count(array_filter($pending, function($d) {
                        $data = is_object($d) ? get_object_vars($d) : (is_array($d) ? $d : []);
                        $expires = $data['expires_at'] ?? null;
                        return is_string($expires) && strtotime($expires) - time() < 86400;
                    }))
                ]
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.get_stats.failed', [
                'error' => $e->getMessage()
            ]);
            $this->response->json(['success' => false, 'error' => 'خطا: دریافت آمار ناموفق'], 500);
        }
    }


}
