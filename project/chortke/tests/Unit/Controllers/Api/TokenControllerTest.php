<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Api;

use PHPUnit\Framework\TestCase;

class TokenControllerTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Api\TokenController::class));
    }

    public function testExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\Api\TokenController::class);
        $this->assertTrue($ref->isSubclassOf(\App\Controllers\Api\BaseApiController::class));
    }

    public function testHasTokenEndpoints(): void
    {
        $required = ['issue', 'refresh', 'revoke', 'list', 'revokeById'];
        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(\App\Controllers\Api\TokenController::class, $method),
                "TokenController باید {$method} داشته باشه"
            );
        }
    }

    public function testInjectsApiTokenService(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\Api\TokenController::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $types = array_map(static function (\ReflectionParameter $parameter): string {
            $type = $parameter->getType();
            return $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }, $params);

        $this->assertContains(\App\Services\ApiTokenService::class, $types);
    }

}
