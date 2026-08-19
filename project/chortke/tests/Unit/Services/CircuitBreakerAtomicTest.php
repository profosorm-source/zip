<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * @group architecture
 */
class CircuitBreakerAtomicTest extends TestCase
{



    public function testCallMethodIsBackwardCompatible(): void
    {
        $ref = new \ReflectionMethod(\Core\CircuitBreaker::class, 'call');
        $params = $ref->getParameters();

        // باید 2 required + 1 optional param داشته باشه
        $this->assertGreaterThanOrEqual(2, count($params));
        $this->assertEquals('serviceName', $params[0]->getName());
        $this->assertEquals('operation', $params[1]->getName());

        // fallback optional باید باشه
        if (count($params) >= 3) {
            $this->assertTrue($params[2]->isOptional(),
                'fallback parameter باید optional باشه');
        }
    }

}
