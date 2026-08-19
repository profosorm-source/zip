<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\User;

use PHPUnit\Framework\TestCase;

class WalletControllerTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\User\WalletController::class));
    }

    public function testExtendsBaseUserController(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\User\WalletController::class);
        $this->assertTrue($ref->isSubclassOf(\App\Controllers\User\BaseUserController::class));
    }

    public function testHasWalletEndpoints(): void
    {
        $required = ['index', 'depositIndex', 'history'];
        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(\App\Controllers\User\WalletController::class, $method),
                "WalletController باید {$method} داشته باشه"
            );
        }
    }
}
