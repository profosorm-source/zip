<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Api;

use PHPUnit\Framework\TestCase;

class AppConfigControllerTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\Api\AppConfigController::class));
    }

    public function testExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\Api\AppConfigController::class);
        $this->assertTrue($ref->isSubclassOf(\App\Controllers\Api\BaseApiController::class));
    }

    public function testHasConfigEndpoint(): void
    {
        $this->assertTrue(
            method_exists(\App\Controllers\Api\AppConfigController::class, 'config'),
            "AppConfigController باید متد config داشته باشه"
        );
    }

    public function testInjectsFeatureFlagService(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\Api\AppConfigController::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $types = array_map(static function (\ReflectionParameter $parameter): string {
            $type = $parameter->getType();
            return $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }, $params);

        $this->assertContains(\App\Services\FeatureFlagService::class, $types);
    }

}
