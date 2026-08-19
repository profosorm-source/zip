<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Core\GenericEvent;
use App\Contracts\DomainEvent;

class GenericEventBehaviorTest extends TestCase
{
    /** @test */
    public function extends_event_base_class(): void
    {
        $event = new GenericEvent(['key' => 'value']);
        $this->assertInstanceOf(\Core\Event::class, $event);
    }

    /** @test */
    public function implements_domain_event(): void
    {
        $event = new GenericEvent([]);
        $this->assertInstanceOf(DomainEvent::class, $event);
    }

    /** @test */
    public function get_data_returns_full_array(): void
    {
        $data = ['user_id' => 1, 'action' => 'test'];
        $event = new GenericEvent($data);
        $this->assertEquals($data, $event->getData());
    }

    /** @test */
    public function get_data_by_key(): void
    {
        $event = new GenericEvent(['user_id' => 42, 'name' => 'Ali']);
        $this->assertEquals(42, $event->getData('user_id'));
        $this->assertEquals('Ali', $event->getData('name'));
        $this->assertNull($event->getData('nonexistent'));
    }

    /** @test */
    public function aggregate_type_from_event_name(): void
    {
        $event = new GenericEvent(['event_name' => 'wallet.deposited']);
        $this->assertEquals('wallet.deposited', $event->aggregateType());
    }

    /** @test */
    public function aggregate_type_from_aggregate_type_key(): void
    {
        $event = new GenericEvent(['aggregate_type' => 'order', 'event_name' => 'ignored']);
        $this->assertEquals('order', $event->aggregateType());
    }

    /** @test */
    public function aggregate_type_defaults_to_general(): void
    {
        $event = new GenericEvent(['foo' => 'bar']);
        $this->assertEquals('general', $event->aggregateType());
    }

    /** @test */
    public function aggregate_id_priority_user_id_first(): void
    {
        $event = new GenericEvent(['user_id' => 5, 'id' => 99, 'aggregate_id' => 7]);
        $this->assertEquals('5', $event->aggregateId());
    }

    /** @test */
    public function aggregate_id_falls_back_to_id(): void
    {
        $event = new GenericEvent(['id' => 42]);
        $this->assertEquals('42', $event->aggregateId());
    }

    /** @test */
    public function aggregate_id_defaults_to_zero(): void
    {
        $event = new GenericEvent(['foo' => 'bar']);
        $this->assertEquals('0', $event->aggregateId());
    }

    /** @test */
    public function to_payload_returns_array(): void
    {
        $data = ['user_id' => 1, 'amount' => '100'];
        $event = new GenericEvent($data);
        $this->assertEquals($data, $event->toPayload());
    }

    /** @test */
    public function to_payload_handles_empty_data(): void
    {
        $event = new GenericEvent([]);
        $this->assertEquals([], $event->toPayload());
    }

    /** @test */
    public function stop_propagation_works(): void
    {
        $event = new GenericEvent([]);
        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    /** @test */
    public function set_data_modifies_event(): void
    {
        $event = new GenericEvent(['a' => 1]);
        $event->setData('b', 2);
        $this->assertEquals(2, $event->getData('b'));
        $this->assertEquals(1, $event->getData('a'));
    }
}
