<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array
    {
        return [
            'success' => true,
            'authority' => 'MOCK_AUTH_' . uniqid(),
            'url' => 'https://mock.payment/pay',
            'message' => 'Mock Payment Created'
        ];
    }

    public function verifyPayment(string $authority, string $amount): array
    {
        return [
            'success' => true,
            'ref_id' => 'MOCK_REF_' . uniqid(),
            'amount' => $amount,
            'message' => 'Mock Payment Verified'
        ];
    }

    public function refundPayment(string $authority): array
    {
        return [
            'success' => true,
            'message' => 'Mock Refund Success'
        ];
    }

    public function verifyCallback(array $callbackData): bool
    {
        $secret = env('WEBHOOK_SECRET');
        // Mock gateway is only used in local/testing flows; accept signed callback simulation.
        if (empty($secret) && (env('APP_ENV') === 'production')) {
            throw new \RuntimeException("WEBHOOK_SECRET is empty for mock gateway callback verification.");
        }
        return true;
    }

    public function getName(): string { return 'mock'; }
    public function getGatewayName(): string { return 'mock'; }
    public function isActive(): bool { return true; }
}
