<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;

class FraudManagementService
{
    private VelocityAndScoreModel $model;
    private IPQualityService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;

    public function __construct(
        VelocityAndScoreModel $model,
        IPQualityService $ipQualityService,
        BrowserFingerprintService $fingerprintService
    ) {
                $this->model = $model;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
    }

    /**
     * @return list<\stdClass>
     */
    public function getIpBlacklist(): array
    {
        if (method_exists($this->model, 'getIpBlacklist')) {
            return $this->model->getIpBlacklist();
        }
        try { return db()->fetchAll('SELECT * FROM ip_blacklist ORDER BY created_at DESC') ?: []; } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.management.getIpBlacklist']);
            return [];
        }
    }

    public function blockIp(string $ip, string $reason, ?int $duration = null): void
    {
        $this->ipQualityService->blacklistIP($ip, $reason, $duration);
    }

    public function deleteIpBlacklistEntry(int $id): void
    {
        if (method_exists($this->model, 'deleteIpBlacklistEntry')) { $this->model->deleteIpBlacklistEntry($id); return; }
        try { db()->query('DELETE FROM ip_blacklist WHERE id = ?', [$id]); } catch (\Throwable $e) {
            @error_log('[FraudManagement] db operation failed: ' . $e->getMessage());
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.management.deleteIpBlacklistEntry', 'id' => $id]);
        }
    }

    /**
     * @return list<\stdClass>
     */
    public function getDeviceBlacklist(): array
    {
        if (method_exists($this->model, 'getDeviceBlacklist')) {
            return $this->model->getDeviceBlacklist();
        }
        try { return db()->fetchAll('SELECT * FROM device_blacklist ORDER BY created_at DESC') ?: []; } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.management.getDeviceBlacklist']);
            return [];
        }
    }

    public function blockDevice(string $fingerprint, string $reason, ?int $duration = null): void
    {
        $this->fingerprintService->blacklistFingerprint($fingerprint, $reason, $duration);
    }

    public function deleteDeviceBlacklistEntry(int $id): void
    {
        if (method_exists($this->model, 'deleteDeviceBlacklistEntry')) { $this->model->deleteDeviceBlacklistEntry($id); return; }
        try { db()->query('DELETE FROM device_blacklist WHERE id = ?', [$id]); } catch (\Throwable $e) {
            @error_log('[FraudManagement] db operation failed: ' . $e->getMessage());
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.management.deleteDeviceBlacklistEntry', 'id' => $id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getFraudLogs(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        if (method_exists($this->model, 'getFraudLogs')) {
            $logs = $this->model->getFraudLogs($perPage, $offset);
            $total = method_exists($this->model, 'getFraudLogsCount') ? $this->model->getFraudLogsCount() : count($logs);
        } else {
            try {
                $logs = db()->fetchAll(
                    "SELECT fl.*, u.full_name, u.email
                     FROM fraud_logs fl
                     LEFT JOIN users u ON u.id = fl.user_id
                     ORDER BY fl.created_at DESC LIMIT {$perPage} OFFSET {$offset}"
                ) ?: [];
                $total = (int)(db()->fetch('SELECT COUNT(*) AS c FROM fraud_logs')->c ?? 0);
            } catch (\Throwable $e) {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.management.getFraudLogs']);
                $logs = []; $total = 0;
            }
        }

        return [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => (int)ceil($total / $perPage),
            'total' => $total,
            'perPage' => $perPage,
        ];
    }
}
