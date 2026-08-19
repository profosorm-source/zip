<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\PaymentGatewayInterface;

final class DeterministicPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array
    {
        return ['success' => true, 'authority' => 'RUNTIME-CREATE', 'redirect_url' => 'https://gateway.invalid/pay'];
    }

    public function verifyPayment(string $authority, string $amount): array
    {
        // Deliberate overlap widens the race window in concurrent callback tests.
        usleep(100_000);
        return ['success' => true, 'status' => 'verified', 'amount' => $amount, 'ref_id' => 'RUNTIME-REF-001'];
    }

    public function verifyCallback(array $callbackData): bool
    {
        return ($callbackData['signature'] ?? null) === 'deterministic-signature';
    }

    public function refundPayment(string $authority): array
    {
        return ['success' => true];
    }

    public function getName(): string
    {
        return 'runtime-test';
    }

    public function isActive(): bool
    {
        return true;
    }
}
