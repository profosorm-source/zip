<?php

namespace App\Controllers\Admin;

use Core\Response;

use App\Services\AntiFraud\FraudManagementService;
use App\Services\ScoreService;
use InvalidArgumentException;

class FraudManagementController extends BaseAdminController
{
    private FraudManagementService $fraudManagementService;
    private ScoreService $scoreService;

    public function __construct(
        FraudManagementService $fraudManagementService,
        ScoreService $scoreService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->fraudManagementService = $fraudManagementService;
        $this->scoreService = $scoreService;
    }

    public function ipBlacklist(): string
    {
        $ips = $this->fraudManagementService->getIpBlacklist();
        return view('admin/fraud/ip-blacklist', ['ips' => $ips]);
    }

    public function blockIP(): void
    {
        $ip = $this->request->str('ip');
        $reason = $this->request->str('reason', 'مسدود شده توسط ادمین');
        $duration = $this->request->input('duration');
        $adminId = int_value($this->session->get('user_id'));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->session->setFlash('error', 'IP نامعتبر است');
            $this->response->redirect(url('/admin/fraud/ip-blacklist'));
        }

        $this->fraudManagementService->blockIp(
            $ip,
            $reason,
            $duration !== null ? int_value($duration) : null
        );

        $this->auditLog(
            'ip_blocked',
            'ip_block',
            0,
            null,
            ['ip' => $ip, 'reason' => $reason]
        );
        $this->session->setFlash('success', 'IP با موفقیت مسدود شد');
        $this->response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function unblockIP(): void
    {
        $id = $this->request->int('id');

        $this->fraudManagementService->deleteIpBlacklistEntry($id);

        $adminId = int_value($this->session->get('user_id'));
        $this->auditLog(
            'ip_unblocked',
            'ip_block',
            0,
            ['id' => $id],
            ['unblocked' => true]
        );
        $this->session->setFlash('success', 'مسدودیت IP برداشته شد');
        $this->response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function deviceBlacklist(): string
    {
        $devices = $this->fraudManagementService->getDeviceBlacklist();
        return view('admin/fraud/device-blacklist', ['devices' => $devices]);
    }

    public function blockDevice(): void
    {
        $fingerprint = $this->request->str('fingerprint');
        $reason = $this->request->str('reason', 'مسدود شده توسط ادمین');
        $adminId = int_value($this->session->get('user_id'));

        $this->fraudManagementService->blockDevice($fingerprint, $reason, null);

        $this->auditLog(
            'device_blocked',
            'device_block',
            0,
            null,
            ['fingerprint' => $fingerprint, 'reason' => $reason]
        );
        $this->session->setFlash('success', 'دستگاه با موفقیت مسدود شد');
        $this->response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function unblockDevice(): void
    {
        $id = $this->request->int('id');

        $this->fraudManagementService->deleteDeviceBlacklistEntry($id);

        $adminId = int_value($this->session->get('user_id'));
        $this->auditLog(
            'device_unblocked',
            'device_block',
            0,
            ['id' => $id],
            ['unblocked' => true]
        );
        $this->session->setFlash('success', 'مسدودیت دستگاه برداشته شد');
        $this->response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function fraudLogs(): string
    {
        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;

        $result = $this->fraudManagementService->getFraudLogs($page, $perPage);

        return view('admin/fraud/logs', [
            'logs' => $result['logs'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Instead of hard reset, create auditable set adjustment.
     */
    public function resetFraudScore(): void
    {
        $adminId = int_value($this->session->get('user_id'));
        $userId = $this->request->int('user_id');
        $reason = $this->request->str('reason', 'Reset by admin');

        try {
            $result = $this->scoreService->createAdjustment(
                $userId,
                'fraud',
                'set',
                0,
                $reason,
                null,
                $adminId
            );

            $ok = (bool)($result['success'] ?? false);

            if ($ok) {
                $this->auditLog(
                    'fraud_score_reset',
                    'user_fraud_score',
                    $userId,
                    ['old_score' => 'unknown'],
                    ['new_score' => 0, 'reason' => $reason]
                );
                $this->session->setFlash('success', 'Fraud score با adjustment ریست شد.');
            } else {
                $this->session->setFlash('error', 'ریست Fraud score ناموفق بود.');
            }
        } catch (InvalidArgumentException $e) {
            $this->session->setFlash('error', $e->getMessage());
        }

        $this->response->back();
    }
}