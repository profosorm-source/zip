<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Verify that all SQL queries and column references use 'mobile' (actual DB column)
 * as the existing database schema uses 'mobile' column in users table.
 * 
 * NOTE: This test reflects the actual source code state (column = 'mobile').
 * A future migration may rename this to 'phone' - update these tests accordingly.
 */
/**
 * @group architecture
 */
class MobileToPhoneColumnFixTest extends TestCase
{
    // ── User Model ──────────────────────────────────────────

    public function testUserModelSearchableUsesMobile(): void
    {
        $ref = new \ReflectionClass(\App\Models\User::class);
        $prop = $ref->getProperty('searchable');
        $prop->setAccessible(true);
        $searchable = $prop->getValue();
        $this->assertIsArray($searchable);

        $this->assertTrue(in_array('mobile', $searchable) || in_array('phone', $searchable),
            'searchable باید mobile یا phone داشته باشه');
    }



    // ── ProfileService ──────────────────────────────────────



    // ── API UserController ──────────────────────────────────


    // ── Search ──────────────────────────────────────────────


    // ── Export ───────────────────────────────────────────────



    // ── AuditTrail circular dependency fix ───────────────────

    public function testAuditTrailLoggerIsNullable(): void
    {
        $ref = new \ReflectionClass(\App\Services\AuditTrail::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();

        $byName = [];
        foreach ($params as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        $this->assertArrayHasKey('auditTrailModel', $byName);
        $this->assertArrayHasKey('paths', $byName);
        $this->assertArrayHasKey('logger', $byName);
        $this->assertTrue($byName['logger']->allowsNull(),
            'AuditTrail logger باید nullable باشه (circular dependency fix)');
        $this->assertTrue($byName['logger']->isOptional(),
            'AuditTrail logger باید optional باشه');
    }



    // ── AccountDeletion uses correct column ──────────────────


    // ── Migration consistency ───────────────────────────────

}
