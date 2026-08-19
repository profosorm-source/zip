<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;
use App\Models\BankCard;
use App\Models\ManualDeposit;
use App\Services\OutboxService;
use App\Services\Payment\PaymentService;

/**
 * @phpstan-type ManualDepositCreateResult array{success: bool, message?: string, deposit_id?: int}
 */
class ManualDepositService
{
    private Database $db;
    private LoggerInterface $logger;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private PaymentService $paymentService;
 
    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        PaymentService $paymentService,
        \App\Services\Shared\IdempotencyService $idempotencyService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->paymentService = $paymentService;
        $this->idempotencyService = $idempotencyService;
    }

    /**
     * ثبت یک درخواست واریز دستی جدید.
     */
    /**
     * @param array<string, mixed> $data
     * @return ManualDepositCreateResult
     */
    public function create(int $userId, array $data, ?string $receiptPath = null): array
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        $effectiveIdempotencyKey = $idempotencyKey ?: null;

        return $this->idempotencyService->executeWithTransaction(
            'manual_deposit.create',
            $userId,
            $data,
            function() use ($userId, $data, $receiptPath) {
                $bankCardId   = int_value($data['bank_card_id'] ?? 0);
                $amount       = str_value($data['amount'] ?? '0');
                $trackingCode = str_value($data['tracking_code'] ?? '');
                $description  = str_value($data['user_description'] ?? '');

                if ($bankCardId <= 0 || \bccomp($amount, '0', 4) <= 0 || $trackingCode === '') {
                    return ['success' => false, 'message' => 'اطلاعات واریز ناقص است'];
                }

                $stmt = $this->db->prepare(
                    "INSERT INTO manual_deposits
                        (user_id, card_id, bank_card_id, amount, tracking_code, user_description, receipt_path, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
                );
                $stmt->execute([
                    $userId,
                    $bankCardId,
                    $bankCardId,
                    $amount,
                    $trackingCode,
                    $description,
                    $receiptPath,
                ]);

                $depositId = (int)$this->db->lastInsertId();

                $this->logger->info('manual_deposit.created', [
                    'deposit_id' => $depositId,
                    'user_id'    => $userId,
                    'amount'     => $amount,
                ]);

                return ['success' => true, 'deposit_id' => $depositId];
            },
            $effectiveIdempotencyKey === null ? null : str_value($effectiveIdempotencyKey)
        );
    }

    /** @return list<\stdClass> */
    public function listByStatus(string $status, int $limit, int $offset): array
    {
        $model = new ManualDeposit($this->db);
        return $model->getAll($status, $limit, $offset);
    }

    /** @return list<\stdClass> */
    public function listPending(int $limit, int $offset): array
    {
        $model = new ManualDeposit($this->db);
        return $model->getPendingDeposits($limit, $offset);
    }

    public function getDeposit(int $depositId): ?\stdClass
    {
        $model = new ManualDeposit($this->db);
        return $this->toObject($model->find($depositId));
    }

    public function getCard(int $cardId): ?\stdClass
    {
        return $this->toObject((new BankCard($this->db))->find($cardId));
    }

    public function approve(int $adminId, int $depositId, string $note): bool
    {
        $this->logger->info('manual_deposit.approve_requested', [
            'admin_id' => $adminId, 
            'deposit_id' => $depositId
        ]);
        
        try {
            $result = $this->paymentService->approveManualDeposit($depositId, $adminId);
            
            if (is_array($result) && ( (isset($result['success']) && $result['success'] === true) || isset($result['tx_id']) )) {
                $this->logger->info('manual_deposit.approved', ['deposit_id' => $depositId]);
                return true;
            }
            
            $this->logger->error('manual_deposit.approve_failed', [
                'deposit_id' => $depositId, 
                'result' => $result,
                'error' => $result['message'] ?? 'Unknown error'
            ]);

            if (env('APP_ENV') === 'testing') {
                throw new \RuntimeException('Manual deposit approval failed: ' . ($result['message'] ?? 'Unknown error'));
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('manual_deposit.approve_exception', [
                'deposit_id' => $depositId,
                'error' => $e->getMessage()
            ]);
            if (env('APP_ENV') === 'testing') {
                throw $e;
            }
            return false;
        }
    }

    public function reject(int $adminId, int $depositId, string $reason): bool
    {
        $this->logger->info('manual_deposit.reject_requested', [
            'admin_id' => $adminId, 
            'deposit_id' => $depositId
        ]);
        
        try {
            $this->db->beginTransaction();
            $model = new ManualDeposit($this->db);
            $deposit = $model->findForUpdate($depositId);
            
            if (!$deposit) {
                throw new \Core\Exceptions\NotFoundException('درخواست واریز یافت نشد');
            }
            
            // 🔐 M-27 FIX: only a still-pending deposit may be rejected. Without this guard a
            // deposit already approved (funds credited) or already rejected could be rejected
            // again — corrupting status/audit and enabling inconsistent reserve/refund. The
            // FOR UPDATE lock above serializes concurrent admins on the same row.
            if (($deposit->status ?? null) !== 'pending') {
                $this->db->commit();
                $this->logger->warning('manual_deposit.reject_skipped_non_pending', [
                    'deposit_id' => $depositId,
                    'status' => $deposit->status ?? null,
                    'admin_id' => $adminId,
                ]);
                return false;
            }

            $model->updateStatus($depositId, 'rejected', $reason, $adminId);
            $this->db->commit();
            
            $this->logger->info('manual_deposit.rejected', [
                'deposit_id' => $depositId, 
                'admin_id' => $adminId
            ]);
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('manual_deposit.reject_failed', [
                'deposit_id' => $depositId, 
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
