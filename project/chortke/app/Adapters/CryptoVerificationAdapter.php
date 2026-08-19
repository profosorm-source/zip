<?php

namespace App\Adapters;

/**
 * Interface CryptoVerificationAdapter
 *
 * Implementations MUST wrap blockchain network verification inside a CircuitBreaker and enforce a strict Timeout using try/catch.
 */
interface CryptoVerificationAdapter
{
    /**
     * Verify a crypto transaction
     *
     * @param string $network The blockchain network (TRC20, BNB20, TON, SOL)
     * @param string $txHash The transaction hash
     * @param string $fromWallet Sender wallet address
     * @param string $toWallet Receiver wallet address
     * @param string $expectedAmount Expected amount in the transaction (decimal string; never float)
     * @return array<string, mixed> Verification result with 'status' and optional 'reason'
     */
    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array;
}

