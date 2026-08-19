<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class RejectWithdrawalJob
{
    private \Core\Database $db;
    private \App\Models\Investment $investmentModel;
    private \App\Models\InvestmentWithdrawal $withdrawalModel;
    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        \Core\Database $db,
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentWithdrawal $withdrawalModel,
        \App\Contracts\LoggerInterface $logger
    ,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->db = $db;
        $this->investmentModel = $investmentModel;
        $this->withdrawalModel = $withdrawalModel;
        $this->logger = $logger;
        $this->outbox = $outbox;
}

    /** @return array<string, mixed> */
    public function handle(int $withdrawalId, int $adminId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $withdrawal = $this->db->fetch("SELECT * FROM investment_withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId]);
            if (!$withdrawal || $withdrawal->status !== \App\Models\InvestmentWithdrawal::STATUS_PENDING) {
                throw new \Core\Exceptions\InvalidStateException('درخواست معتبر نیست.');
            }

            $investment = $this->db->fetch("SELECT * FROM investments WHERE id = ? FOR UPDATE", [(int)$withdrawal->investment_id]);

            if ($investment) {
                // H-I5 Fix: Restore the reserved balance back to investment's current balance
                $currentBalance = is_scalar($investment->current_balance ?? null) ? (string)$investment->current_balance : '0';
                $reservedAmount = is_scalar($withdrawal->amount ?? null) ? (string)$withdrawal->amount : '0';
                if (!is_numeric($currentBalance) || !is_numeric($reservedAmount) || bccomp($reservedAmount, '0', 8) < 0) {
                    throw new \Core\Exceptions\InvalidStateException('مبلغ یا موجودی درخواست برداشت نامعتبر است.');
                }
                $restoredBalance = bcadd($currentBalance, $reservedAmount, 8);
                $this->investmentModel->update((int)$investment->id, [
                    'current_balance' => $restoredBalance
                ]);
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'           => \App\Models\InvestmentWithdrawal::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);
            $this->db->commit();

            $this->outbox?->record('investment', $withdrawalId, 'investment.withdrawal_rejected', [
                'user_id' => $withdrawal->user_id,
                'reason' => $reason
            ]);
            
            $this->logger->info('investment_withdrawal_rejected', ['message' => "Admin {$adminId} rejected withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('investment_withdrawal_reject_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
