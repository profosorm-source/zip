<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;

/**
 * StateMachineService - State machine enforcement for all modules
 * ✅ Prevents invalid state transitions
 * ✅ Validates business logic constraints
 * ✅ Ensures data consistency
 */
class StateMachineService
{
    /**
     * SocialAd valid state transitions
     */
    private const SOCIAL_AD_TRANSITIONS = [
        'pending'   => ['active', 'rejected'],
        'active'    => ['paused', 'cancelled'],
        'paused'    => ['active', 'cancelled'],
        'cancelled' => [],
        'rejected'  => [],
    ];

    /**
     * VitrineListing state machine
     */
    private const VITRINE_TRANSITIONS = [
        'pending'   => ['active', 'rejected'],
        'active'    => ['in_escrow', 'cancelled'],
        'in_escrow' => ['sold', 'disputed', 'cancelled'],
        'disputed'  => ['sold', 'cancelled'],
        'sold'      => [],
        'rejected'  => [],
        'cancelled' => [],
    ];

    /**
     * Influencer state machine
     */
    private const INFLUENCER_TRANSITIONS = [
        'pending'    => ['verified', 'rejected'],
        'verified'   => ['suspended'],
        'suspended'  => ['verified'],
        'rejected'   => ['pending'],
    ];

    /**
     * Dispute state machine
     */
    private const DISPUTE_TRANSITIONS = [
        'open'         => ['under_review', 'closed'],
        'under_review' => ['resolved'],
        'resolved'     => ['appealed'],
        'appealed'     => ['under_review', 'resolved'],
        'closed'       => [],
    ];

    /**
     * Withdrawal state machine (Critical Financial Flow)
     */
    private const WITHDRAWAL_TRANSITIONS = [
        'pending'    => ['processing', 'rejected', 'cancelled'],
        'processing' => ['completed', 'rejected', 'cancelled'],
        'completed'  => [],
        'rejected'   => [],
        'cancelled'  => [],
    ];

    /**
     * KYC state machine (Compliance and Trust)
     */
    private const KYC_TRANSITIONS = [
        'pending'      => ['under_review', 'rejected'],
        'under_review' => ['verified', 'rejected'],
        'verified'     => ['suspended', 'expired'],
        'suspended'    => ['verified', 'rejected'],
        'rejected'     => ['pending'],
        'expired'      => ['pending'],
    ];

    /**
     * Lottery state machine
     */
    private const LOTTERY_TRANSITIONS = [
        'upcoming'  => ['active', 'cancelled'],
        'active'    => ['drawing', 'cancelled'],
        'drawing'   => ['finished'],
        'finished'  => [],
        'cancelled' => [],
    ];

    /**
     * CustomTask state machine
     */
    private const CUSTOM_TASK_TRANSITIONS = [
        'pending'   => ['active', 'rejected'],
        'active'    => ['paused', 'completed', 'cancelled'],
        'paused'    => ['active', 'cancelled'],
        'cancelled' => [],
        'rejected'  => [],
        'completed' => [],
    ];

    /**
     * CustomTaskSubmission state machine
     */
    private const CUSTOM_TASK_SUBMISSION_TRANSITIONS = [
        'pending'     => ['in_progress', 'cancelled', 'expired'],
        'in_progress' => ['submitted', 'cancelled', 'expired'],
        'submitted'   => ['approved', 'rejected', 'disputed', 'expired'],
        'approved'    => [],
        'rejected'    => ['disputed'],
        'cancelled'   => [],
        'expired'     => [],
        'disputed'    => ['approved', 'rejected'],
    ];

    /**
     * Investment state machine
     */
    private const INVESTMENT_TRANSITIONS = [
        'active'    => ['frozen', 'closed', 'suspended'],
        'frozen'    => ['active', 'closed'],
        'closed'    => [],
        'suspended' => ['active', 'closed'],
    ];

    /**
     * PredictionGame state machine
     */
    private const PREDICTION_GAME_TRANSITIONS = [
        'open'      => ['closed', 'finished', 'cancelled'],
        'closed'    => ['finished', 'cancelled'],
        'finished'  => [],
        'cancelled' => [],
    ];

    /**
     * M-31 FIX: entity -> physical table allowlist used by executeTransition().
     * Previously ONLY 'custom_task' was mapped here, so although canTransition()/
     * getAllowedTransitions() described valid transitions for every module, the ATOMIC,
     * FOR UPDATE-locked executeTransition() rejected every other entity ('Entity/table mapping
     * is not allowed'). That meant withdrawals, KYC, disputes, escrow, investments, prediction
     * games, crypto deposits, vitrine listings, etc. had no serializable, race-safe transition
     * enforcement path at all. The map is a fixed, code-controlled allowlist (compared with
     * hash_equals against the caller-supplied table) so it does not widen SQL-injection surface.
     * @var array<string, string>
     */
    private const ENTITY_TABLES = [
        'social_ad'              => 'social_ads',
        'vitrine_listing'        => 'vitrine_listings',
        'influencer_profile'     => 'influencer_profiles',
        'dispute'                => 'disputes',
        'withdrawal'             => 'withdrawals',
        'kyc'                    => 'kyc_verifications',
        'lottery'                => 'lottery_rounds',
        'custom_task'            => 'ads',
        'custom_task_submission' => 'custom_task_submissions',
        'investment'             => 'investments',
        'prediction_game'        => 'prediction_games',
        'escrow'                 => 'escrow_transactions',
        'crypto_deposit'         => 'crypto_deposits',
    ];

    /**
     * Escrow state machine
     */
    private const ESCROW_TRANSITIONS = [
        // A pending hold may already have a matching locked wallet reservation.
        // Expiry/cancellation must therefore be allowed to refund it atomically.
        'pending'   => ['in_escrow', 'refunded', 'disputed', 'cancelled'],
        'in_escrow' => ['released', 'refunded', 'disputed'],
        'disputed'  => ['released', 'refunded'],
        'released'  => [],
        'refunded'  => [],
        'cancelled' => [],
    ];

    /**
     * CryptoDeposit state machine
     */
    private const CRYPTO_DEPOSIT_TRANSITIONS = [
        'pending'       => ['auto_verified', 'manual_review', 'rejected'],
        'manual_review' => ['verified', 'rejected'],
        'auto_verified' => [],
        'verified'      => [],
        'rejected'      => [],
    ];
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {    $this->db = $db;
    $this->logger = $logger;

        
        }

    // ─────────────────────────────────────────────────────────────────────────
    // Public Universal API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generic dynamic state validator for ANY supported entity
     */
    public function canTransition(string $entity, string $currentStatus, string $newStatus): bool
    {
        $allowed = $this->getAllowedTransitions($entity, $currentStatus);
        $valid = in_array($newStatus, $allowed, true);

        if (!$valid) {
            $this->logger->warning('state_transition_violation', [
                'entity'  => $entity,
                'from'    => $currentStatus,
                'to'      => $newStatus,
                'allowed' => $allowed
            ]);
        }

        return $valid;
    }

    /**
     * Retain BC specific method for SocialAd
     */
    public function canTransitionSocialAd(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('social_ad', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Vitrine
     */
    public function canTransitionVitrine(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('vitrine_listing', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Influencer
     */
    public function canTransitionInfluencer(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('influencer_profile', $currentStatus, $newStatus);
    }

    /**
     * Retain BC specific method for Dispute
     */
    public function canTransitionDispute(string $currentStatus, string $newStatus): bool
    {
        return $this->canTransition('dispute', $currentStatus, $newStatus);
    }

    /**
     * Get allowed next states
     * @return list<string>
     */
    public function getAllowedTransitions(string $entity, string $currentStatus): array
    {
        return match($entity) {
            'social_ad'              => self::SOCIAL_AD_TRANSITIONS[$currentStatus] ?? [],
            'vitrine_listing'        => self::VITRINE_TRANSITIONS[$currentStatus] ?? [],
            'influencer_profile'     => self::INFLUENCER_TRANSITIONS[$currentStatus] ?? [],
            'dispute'                => self::DISPUTE_TRANSITIONS[$currentStatus] ?? [],
            'withdrawal'             => self::WITHDRAWAL_TRANSITIONS[$currentStatus] ?? [],
            'kyc'                    => self::KYC_TRANSITIONS[$currentStatus] ?? [],
            'lottery'                => self::LOTTERY_TRANSITIONS[$currentStatus] ?? [],
            'custom_task'            => self::CUSTOM_TASK_TRANSITIONS[$currentStatus] ?? [],
            'custom_task_submission' => self::CUSTOM_TASK_SUBMISSION_TRANSITIONS[$currentStatus] ?? [],
            'investment'             => self::INVESTMENT_TRANSITIONS[$currentStatus] ?? [],
            'prediction_game'        => self::PREDICTION_GAME_TRANSITIONS[$currentStatus] ?? [],
            'escrow'                 => self::ESCROW_TRANSITIONS[$currentStatus] ?? [],
            'crypto_deposit'         => self::CRYPTO_DEPOSIT_TRANSITIONS[$currentStatus] ?? [],
            default                  => []
        };
    }

    /**
     * Is state terminal (no further transitions allowed)?
     */
    public function isTerminalState(string $entity, string $status): bool
    {
        $transitions = $this->getAllowedTransitions($entity, $status);
        return empty($transitions);
    }

    /**
     * Executing a state transition in Serializable Transaction with FOR UPDATE locking.
     *
     * @param callable(string): mixed $onSuccess
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function executeTransition(
        string $entity,
        string $table,
        int $id,
        string $newStatus,
        callable $onSuccess
    ): array {
        $expectedTable = self::ENTITY_TABLES[$entity] ?? null;
        if ($expectedTable === null || !hash_equals($expectedTable, $table)) {
            throw new \InvalidArgumentException('Entity/table mapping is not allowed for state transition');
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
                $this->db->beginTransaction();
            }

            // Lock entity record FOR UPDATE
            $stmt = $this->db->prepare("SELECT status FROM {$table} WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $id]);
            $record = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!is_object($record)) {
                if ($startedTransaction) {
                    $this->db->rollback();
                }
                return ['success' => false, 'message' => 'رکورد مورد نظر یافت نشد.'];
            }
            $recordValues = get_object_vars($record);
            $currentStatus = $recordValues['status'] ?? null;
            if (!is_string($currentStatus) || $currentStatus === '') {
                throw new \UnexpectedValueException('State transition row has an invalid status');
            }

            if (!$this->canTransition($entity, $currentStatus, $newStatus)) {
                if ($startedTransaction) {
                    $this->db->rollback();
                }
                return [
                    'success' => false,
                    'message' => "تغییر وضعیت غیرمجاز از {$currentStatus} به {$newStatus}."
                ];
            }

            $callbackResult = $onSuccess($currentStatus);

            $updateStmt = $this->db->prepare("UPDATE {$table} SET status = :status, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute(['status' => $newStatus, 'id' => $id]);

            if ($startedTransaction) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'message' => 'تغییر وضعیت با موفقیت انجام شد.',
                'data' => $callbackResult
            ];

        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('state_machine.transition_failed', [
                'entity' => $entity,
                'id' => $id,
                'new_status' => $newStatus,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سرور: ' . $e->getMessage()];
        }
    }
}

