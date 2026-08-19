<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Adapters\BankInquiryAdapter;
use App\Adapters\BankInquiryManager;
use Core\Application;
use PHPUnit\Framework\TestCase;

final class BankInquiryContainerIntegrationTest extends TestCase
{
    public function test_production_container_resolves_tagged_bank_adapters(): void
    {
        $container = Application::getInstance()->container;
        $tagged = $container->tagged('bank_inquiry_adapters');

        $this->assertCount(2, $tagged);
        foreach ($tagged as $adapter) {
            $this->assertInstanceOf(BankInquiryAdapter::class, $adapter);
        }

        $this->assertInstanceOf(
            BankInquiryManager::class,
            $container->make(BankInquiryManager::class)
        );
    }
}
