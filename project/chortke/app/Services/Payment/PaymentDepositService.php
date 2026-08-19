<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\SagaOrchestrator;
use Core\Database;

/**
 * PaymentDepositService — واریزهای خارجی (دستی و کریپتو)
 *
 * مسئولیت‌ها:
 *   - approveManualDeposit()  : تأیید ادمین روی واریز دستی + saga
 *   - fulfillCryptoDeposit()  : تکمیل واریز کریپتو + saga
 */
class PaymentDepositService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }


    private LoggerInterface $logger;
    private Database $db;
    private WalletServiceInterface $walletService;
    private SagaOrchestrator $sagaOrchestrator;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        WalletServiceInterface $walletService,
        SagaOrchestrator $sagaOrchestrator
    ) {
        $this->logger           = $logger;
        $this->db               = $db;
        $this->walletService    = $walletService;
        $this->sagaOrchestrator = $sagaOrchestrator;
    }

    /** @return array<string, mixed> */
    public function approveManualDeposit(int $depositId, int $adminId): array
    {
        $existing = $this->toObject($this->db->selectOne(
            "SELECT status, transaction_id FROM manual_deposits WHERE id = ?",
            [$depositId]
        ));
        if (!$existing) {
            return ['success' => false, 'message' => 'واریزی یافت نشد', 'deposit_id' => $depositId];
        }
        if (($existing->status ?? '') === 'approved') {
            $this->logger->info('manual_deposit.approve_idempotent_already_approved', ['deposit_id' => $depositId, 'admin_id' => $adminId]);
            return [
                'success' => true,
                'message' => 'این واریزی قبلاً تایید شده است',
                'deposit_id' => $depositId,
                'tx_id' => $existing->transaction_id ?? null,
            ];
        }

        $saga = $this->sagaOrchestrator;

        // BUGFIX-SAGA-TX-ROOT: مشابه FinancialEscrowService — بدون Transaction Root،
        // اگر مرحله credit_wallet بعد از موفقیت update_status خطا بدهد، جبران‌سازی
        // Closure-based توسط SagaRecoveryWorker (که هرگز زمان‌بندی نشده) قابل بازیابی
        // نیست. کل Saga داخل یک تراکنش اتمیک اجرا می‌شود.
        $result = $this->db->transactional(function () use ($saga, $depositId, $adminId) {
        return $saga
            ->setSaga('manual_deposit_approval', ['deposit_id' => $depositId, 'admin_id' => $adminId])
            ->addStep('update_status', function ($ctx) {
                $deposit = $this->toObject($this->db->selectOne(
                    "SELECT status FROM manual_deposits WHERE id = ? FOR UPDATE",
                    [$ctx['deposit_id']]
                ));
                if (!$deposit) throw new \Core\Exceptions\NotFoundException('واریزی یافت نشد');
                if ($deposit->status === 'approved') throw new \Core\Exceptions\InvalidStateException('این واریزی قبلاً تایید شده است');
                if ($deposit->status === 'rejected') throw new \Core\Exceptions\InvalidStateException('این واریزی قبلاً رد شده است');

                $this->db->prepare(
                    "UPDATE manual_deposits SET status = 'approved', approved_at = NOW(), admin_id = ? WHERE id = ?"
                )->execute([$ctx['admin_id'], $ctx['deposit_id']]);

                $deposit = $this->toObject($this->db->selectOne("SELECT * FROM manual_deposits WHERE id = ?", [$ctx['deposit_id']]));
                return array_merge($ctx, (array)$deposit);
            }, function ($err, $res) {
                $this->db->prepare(
                    "UPDATE manual_deposits SET status = 'pending', approved_at = NULL WHERE id = ?"
                )->execute([$res['deposit_id']]);
            })
            ->addStep('credit_wallet', function ($ctx) {
                $res = $this->walletService->deposit(
                    intval($ctx['user_id']),
                    strval($ctx['amount']),
                    $ctx['currency'] ?? 'irt',
                    [
                        'type'            => 'manual_deposit',
                        'ref_id'          => $ctx['deposit_id'],
                        'idempotency_key' => 'manual_deposit_approve_' . strval($ctx['deposit_id']),
                    ]
                );
                if (empty($res['success'])) throw new \Core\Exceptions\ApplicationException((is_string($res['message'] ?? null) ? $res['message'] : 'خطا در واریز به کیف پول'));
                $this->db->prepare(
                    "UPDATE manual_deposits SET transaction_id = ? WHERE id = ?"
                )->execute([$res['transaction_id'], $ctx['deposit_id']]);
                return ['tx_id' => $res['transaction_id']];
            }, function ($err, $res) {
                if (isset($res['tx_id'])) {
                    $this->walletService->reverseTransaction($res['tx_id'], null, 'سیستمی: لغو تایید دستی');
                }
            })
            ->execute();
        });

        if (!is_array($result)) throw new \UnexpectedValueException('Manual deposit saga must return an array.');
        return [
            'success'    => true,
            'message'    => 'واریز دستی تأیید شد',
            'deposit_id' => $depositId,
            'tx_id'      => $result['tx_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function fulfillCryptoDeposit(int $depositId): array
    {
        $saga = $this->sagaOrchestrator;

        // BUGFIX-SAGA-TX-ROOT: رجوع به توضیح کامل در approveManualDeposit()
        $result = $this->db->transactional(function () use ($saga, $depositId) {
        return $saga
            ->setSaga('crypto_deposit_fulfillment', ['deposit_id' => $depositId])
            ->addStep('verify_and_update', function ($ctx) {
                $deposit = $this->toObject($this->db->selectOne(
                    "SELECT * FROM crypto_deposits WHERE id = ? FOR UPDATE",
                    [$ctx['deposit_id']]
                ));
                if (!$deposit || $deposit->status !== 'pending') {
                    throw new \Core\Exceptions\InvalidStateException('تراکنش نامعتبر یا قبلاً پردازش شده');
                }
                $this->db->prepare(
                    "UPDATE crypto_deposits SET status = 'completed', confirmed_at = NOW() WHERE id = ?"
                )->execute([$ctx['deposit_id']]);
                return array_merge($ctx, (array)$deposit);
            }, function ($err, $res) {
                $this->db->prepare(
                    "UPDATE crypto_deposits SET status = 'pending', confirmed_at = NULL WHERE id = ?"
                )->execute([$res['deposit_id']]);
            })
            ->addStep('credit_wallet', function ($ctx) {
                $res = $this->walletService->deposit(
                    intval($ctx['user_id']),
                    strval($ctx['amount']),
                    'usdt',
                    ['type' => 'crypto_deposit', 'ref_id' => $ctx['deposit_id']]
                );
                if (empty($res['success'])) throw new \Core\Exceptions\ApplicationException((is_string($res['message'] ?? null) ? $res['message'] : 'خطا در واریز به کیف پول'));
                return ['tx_id' => $res['transaction_id']];
            }, function ($err, $res) {
                if (isset($res['tx_id'])) {
                    $this->walletService->reverseTransaction($res['tx_id'], null, 'سیستمی: لغو واریز کریپتو');
                }
            })
            ->execute();
        });
        if (!is_array($result)) throw new \UnexpectedValueException('Crypto deposit saga must return an array.');
        return $result;
    }
}
