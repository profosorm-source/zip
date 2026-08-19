<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Events\AccountDeletedEvent;
use App\Events\FeatureFlagChanged;
use App\Events\DisputeOpenedEvent;
use App\Events\PaymentCompletedEvent;
use App\Events\FraudScoreUpdatedEvent;
use App\Events\KYCApprovedEvent;

class EventsTest extends TestCase
{
    /** @test */
    public function verify_all_events_can_be_instantiated(): void
    {
        // 1. AccountDeletedEvent
        $event1 = new AccountDeletedEvent(12, 'alireza@example.com', 'reason_text');
        $this->assertEquals(12, $event1->userId);

        // 2. FeatureFlagChanged
        $event2 = new FeatureFlagChanged('feature_x', 'toggled');
        $this->assertEquals('feature_x', $event2->featureName);

        // 3. DisputeOpenedEvent
        $event3 = new DisputeOpenedEvent(45, 12, 13);
        $this->assertEquals(45, $event3->disputeId);

        // 4. PaymentCompletedEvent
        $event4 = new PaymentCompletedEvent(12, 'tx_123', 50000.0, 'irt', 'zarinpal');
        $this->assertEquals(12, $event4->userId);

        // 5. FraudScoreUpdatedEvent
        $event5 = new FraudScoreUpdatedEvent(12, 85);
        $this->assertEquals(12, $event5->userId);

        // 6. KYCApprovedEvent
        $event6 = new KYCApprovedEvent(12, 101);
        $this->assertEquals(12, $event6->userId);
    }
}
