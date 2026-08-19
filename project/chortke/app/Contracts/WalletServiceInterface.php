<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * @phpstan-type WalletMetadata array<string, mixed>
 * @phpstan-type WalletResult array<string, mixed>
 * @phpstan-type WalletFilters array<string, mixed>
 */
interface WalletServiceInterface
{
    public function getOrCreateWallet(int $userId): ?object;
    
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function deposit(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function depositInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function withdraw(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function withdrawInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function pay(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;

    public function hasBalance(int $userId, string $amount, string $currency = 'irt'): bool;
    
    public function completeWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool;

    /** Marks an already-spent locked hold as completed without touching balance/locked again. */
    public function finalizeLockedSpend(int $userId, string $transactionId): bool;

    /** Marks a hold as cancelled after releaseLockedFunds already refunded it. */
    public function finalizeLockedRefund(int $userId, string $transactionId): bool;
    
    public function cancelWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool;

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool;
    
    /** @return array{can_withdraw: bool, message: string} */
    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array;
    
    public function getWalletSummary(int $userId): \stdClass;
    
    public function transfer(int $fromUserId, int $toUserId, string $amount, string $currency = 'irt', string $description = ''): ?object;
    
    public function getBalance(int $userId, string $currency = 'irt'): string;

    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string;

    public function isWalletFrozen(int $userId): bool;

    // ── Query methods (CQRS Read Model) ────────────────────────
    /** @return array<string, string|null> */
    public function getWalletBalances(int $userId): array;
    /**
     * @param WalletFilters $filters
     * @return list<\stdClass>
     */
    public function getUserTransactions(int $userId, int $limit, int $offset, array $filters = []): array;
    /** @param WalletFilters $filters */
    public function countUserTransactions(int $userId, array $filters = []): int;
    /** @return list<\stdClass> */
    public function getAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array;
    public function countAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null): int;
    public function findTransactionById(int $id): ?\stdClass;
    /** @return list<\stdClass> */
    public function quickSearchTransactions(string $term, ?int $userId = null, int $limit = 5): array;

    /**
     * Consumes reserved/locked funds without returning them to the owner's
     * available balance. This is the settlement primitive for escrow payouts.
     */
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function spendLockedFunds(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;

    /**
     * Refund primitive: locked funds are returned to the owner's available
     * balance. It must never be used for an escrow payout.
     */
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function releaseLockedFunds(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
}
