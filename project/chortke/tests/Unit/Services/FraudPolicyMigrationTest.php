<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست فاز E — Policy Migration: همه callerهای مالی fraud guard دارن
 */
/**
 * @group architecture
 */
class FraudPolicyMigrationTest extends TestCase
{
    public function testAssertFraudAllowedHelperExists(): void
    {
        $this->assertTrue(function_exists('assert_fraud_allowed'),
            'assert_fraud_allowed() helper باید وجود داشته باشه');
    }

    public function testAdapterBaseHasAssertFraudAllowed(): void
    {
        $this->assertTrue(
            method_exists(\App\Adapters\AdapterBase::class, 'assertFraudAllowed'),
            'AdapterBase باید assertFraudAllowed method داشته باشه'
        );
    }


    public function testFinancialCommitmentActionsResolveToTransactionStrategy(): void
    {
        $resolver = (new \ReflectionClass(\App\Services\AntiFraud\FraudStrategyResolver::class))
            ->newInstanceWithoutConstructor();
        $mapProperty = new \ReflectionProperty(\App\Services\AntiFraud\FraudStrategyResolver::class, 'map');
        $mapProperty->setAccessible(true);
        /** @var array<string, class-string> $map */
        $map = $mapProperty->getValue($resolver);

        foreach (['investment.pay', 'ad.budget_withdraw', 'vitrine.escrow', 'prediction.bet'] as $action) {
            $this->assertSame(\App\Services\AntiFraud\Strategies\TransactionFraudStrategy::class, $map[$action] ?? null);
        }
    }


    public function testWalletMutationConstructorNoFraudParam(): void
    {
        $ref = new \ReflectionClass(\App\Services\Wallet\WalletMutationService::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $types = array_map(static function (\ReflectionParameter $parameter): string {
            $type = $parameter->getType();
            return $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }, $params);

        $this->assertNotContains(
            \App\Contracts\AntiFraud\FraudGuardInterface::class, $types,
            'WalletMutationService constructor نباید FraudGuardInterface inject کنه'
        );
    }


}
