<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Contracts\DomainEvent;

class DomainEventInterfaceTest extends TestCase
{
    /** @test */
    public function generic_event_implements_domain_event(): void
    {
        $event = new \Core\GenericEvent(['user_id' => 1, 'event_name' => 'test.event']);

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('test.event', $event->aggregateType());
        $this->assertEquals('1', $event->aggregateId());
        $this->assertIsArray($event->toPayload());
    }

    /** @test */
    public function generic_event_aggregate_type_falls_back_to_general(): void
    {
        $event = new \Core\GenericEvent(['some_key' => 'value']);

        $this->assertEquals('general', $event->aggregateType());
        $this->assertEquals('0', $event->aggregateId());
    }

    /** @test */
    public function generic_event_aggregate_id_priority(): void
    {
        // user_id takes priority
        $event = new \Core\GenericEvent(['user_id' => 5, 'id' => 99]);
        $this->assertEquals('5', $event->aggregateId());

        // id as fallback
        $event2 = new \Core\GenericEvent(['id' => 42]);
        $this->assertEquals('42', $event2->aggregateId());

        // aggregate_id
        $event3 = new \Core\GenericEvent(['aggregate_id' => 7]);
        $this->assertEquals('7', $event3->aggregateId());
    }

    /** @test */
    public function investment_created_event_implements_domain_event(): void
    {
        $event = new \App\Events\InvestmentCreatedEvent(10, 200, '100.0', 'usdt');

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('investment', $event->aggregateType());
        $this->assertEquals('200', $event->aggregateId());

        $payload = $event->toPayload();
        $this->assertEquals(10, $payload['user_id']);
        $this->assertEquals(200, $payload['investment_id']);
    }

    /** @test */
    public function score_delta_appended_event_implements_domain_event(): void
    {
        $event = new \App\Events\ScoreDeltaAppendedEvent('user', 33, 'trust', -5.0, 'fraud');

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('user', $event->aggregateType());
        $this->assertEquals('33', $event->aggregateId());

        $payload = $event->toPayload();
        $this->assertEquals('trust', $payload['domain']);
        $this->assertEquals(-5.0, $payload['delta']);
        $this->assertEquals('fraud', $payload['source']);
    }

    /** @test */
    public function settings_updated_does_not_implement_domain_event(): void
    {
        $event = new \App\Events\SettingsUpdated(['key1']);
        $this->assertNotInstanceOf(DomainEvent::class, $event);
    }
}
