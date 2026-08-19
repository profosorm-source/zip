<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\LoggerInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\WalletServiceInterface;
use App\Models\PaymentLog;
use App\Services\OutboxService;
use App\Services\Payment\PaymentCommandService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\SagaOrchestrator;
use App\Services\Shared\IdempotencyService;
use Core\Application;
use Core\Database;

final class PaymentRuntimeFactory
{
    public static function make(): PaymentCommandService
    {
        $container = Application::getInstance()->container;
        $database = $container->make(Database::class);
        $logger = $container->make(LoggerInterface::class);
        $gateway = new DeterministicPaymentGateway();
        $factory = new PaymentGatewayFactory(
            $logger,
            static fn(string $name): PaymentGatewayInterface => $name === 'runtime-test'
                ? $gateway
                : throw new \RuntimeException('Unknown deterministic gateway: ' . $name)
        );

        return new PaymentCommandService(
            $logger,
            $container->make(PaymentLog::class),
            $factory,
            $container->make(IdempotencyService::class),
            $database,
            $container->make(WalletServiceInterface::class),
            new SagaOrchestrator($database, $logger),
            null,
            null,
            $container->make(OutboxService::class)
        );
    }
}
