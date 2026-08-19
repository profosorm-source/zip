<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Verify that all SQL queries targeting users table use 'mobile' column
 * consistently (the actual DB column name).
 *
 * DB column: users.mobile VARCHAR(15)
 */
/**
 * @group architecture
 */
class MobileColumnConsistencyTest extends TestCase
{
    public function testUserModelSearchableUsesMobile(): void
    {
        $ref = new \ReflectionClass(\App\Models\User::class);
        $prop = $ref->getProperty('searchable');
        $prop->setAccessible(true);
        $this->assertContains('mobile', $prop->getValue());
    }









    public function testAuthControllerDeclaresUserService(): void
    {
        $ref = new \ReflectionClass(\App\Controllers\User\AuthController::class);
        $this->assertTrue($ref->hasProperty('userService'));
        $prop = $ref->getProperty('userService');
        $this->assertEquals('App\Controllers\User\AuthController', $prop->getDeclaringClass()->getName());
    }
}
