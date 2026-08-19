<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ContractsTest extends TestCase
{
    /** @test */
    public function verify_all_contracts_exist_and_are_compilable(): void
    {
        $contracts = [
            'App\Contracts\LoggerInterface',
            'App\Contracts\CacheInterface',
            'App\Contracts\CircuitBreakerInterface',
            'App\Contracts\NotificationServiceInterface',
            'App\Contracts\PaymentGatewayInterface',
            'App\Contracts\RateLimiterInterface',
            'App\Contracts\FeatureFlagRepositoryInterface',
            'App\Contracts\OutboxServiceInterface',
            'App\Contracts\SearchServiceInterface',
            'App\Contracts\WalletServiceInterface',
            'App\Contracts\UploadServiceInterface',
            'App\Contracts\EmailServiceInterface',
            'App\Contracts\CurrencyServiceInterface',
            'App\Contracts\MetricsCollectorInterface',
            'App\Contracts\NotificationChannelInterface',
            'App\Contracts\SearchProviderInterface',
            'App\Contracts\ValidatorFactoryInterface',
            'App\Contracts\AntiFraud\FraudCheckStrategyInterface',
            'App\Contracts\AntiFraud\FraudGuardInterface',
        ];

        foreach ($contracts as $contract) {
            $this->assertTrue(interface_exists($contract) || class_exists($contract), "Contract {$contract} is missing or not compilable.");
        }
    }
}
