<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Api;

use PHPUnit\Framework\TestCase;

class WalletControllerTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Api\WalletController::class));
    }

    public function testExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\Api\WalletController::class);
        $this->assertTrue($ref->isSubclassOf(\App\Controllers\Api\BaseApiController::class));
    }

    public function testHasWalletApiEndpoints(): void
    {
        $required = ['balance', 'transactions'];
        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(\App\Controllers\Api\WalletController::class, $method),
                "Api WalletController باید {$method} داشته باشه"
            );
        }
    }
}
