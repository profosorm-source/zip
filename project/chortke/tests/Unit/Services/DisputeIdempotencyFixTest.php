<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * @group architecture
 */
class DisputeIdempotencyFixTest extends TestCase
{
    public function testDisputeCommandServiceInjectsIdempotencyService(): void
    {
        $ref = new \ReflectionClass(\App\Services\Dispute\DisputeCommandService::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains('idempotencyService', $names,
            'DisputeCommandService باید IdempotencyService inject کنه');
    }


    public function testAllCriticalServicesHaveIdempotency(): void
    {
        $services = [
            'TicketCommandService' => \App\Services\TicketCommandService::class,
            'DisputeCommandService' => \App\Services\Dispute\DisputeCommandService::class,
            'LotteryCommandService' => \App\Services\Lottery\LotteryCommandService::class,
        ];

        foreach ($services as $name => $class) {
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            $this->assertNotNull($ctor);
            $params = $ctor->getParameters();
            $types = array_map(function($p) {
                $t = $p->getType();
                return $t instanceof \ReflectionNamedType ? $t->getName() : '';
            }, $params);

            $hasIdempotency = in_array(\App\Services\Shared\IdempotencyService::class, $types, true);
            $this->assertTrue($hasIdempotency, "{$name} باید IdempotencyService inject کنه");
        }
    }

}
