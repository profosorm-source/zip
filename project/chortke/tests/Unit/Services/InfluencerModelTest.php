<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Models\InfluencerModel;

class InfluencerModelTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeModel(): InfluencerModel
    {
        return new InfluencerModel(m::mock(Database::class));
    }

    /** @test */
    public function profile_status_labels_are_keyed_by_status(): void
    {
        $labels = $this->makeModel()->profileStatusLabels();
        $this->assertArrayHasKey('pending', $labels);
        $this->assertArrayHasKey('verified', $labels);
        $this->assertArrayHasKey('rejected', $labels);
        $this->assertSame('تایید شده', $labels['verified']);
    }

    /** @test */
    public function pending_can_transition_to_admin_review_and_rejected(): void
    {
        $model = $this->makeModel();
        $transitions = $model->getAllowedTransitions(InfluencerModel::STATUS_PENDING);
        $this->assertContains(InfluencerModel::STATUS_PENDING_ADMIN_REVIEW, $transitions);
        $this->assertContains(InfluencerModel::STATUS_REJECTED, $transitions);
    }

    /** @test */
    public function verified_can_transition_to_suspended(): void
    {
        $model = $this->makeModel();
        $this->assertTrue($model->canTransitionTo(InfluencerModel::STATUS_VERIFIED, InfluencerModel::STATUS_SUSPENDED));
        $this->assertFalse($model->canTransitionTo(InfluencerModel::STATUS_VERIFIED, InfluencerModel::STATUS_REJECTED));
    }

    /** @test */
    public function rejected_is_terminal_state(): void
    {
        $model = $this->makeModel();
        $this->assertTrue($model->isTerminalStatus(InfluencerModel::STATUS_REJECTED));
        $this->assertFalse($model->isTerminalStatus(InfluencerModel::STATUS_PENDING));
    }

    /** @test */
    public function unknown_status_has_no_transitions(): void
    {
        $model = $this->makeModel();
        $this->assertSame([], $model->getAllowedTransitions('nonexistent'));
        $this->assertFalse($model->canTransitionTo('nonexistent', InfluencerModel::STATUS_VERIFIED));
        $this->assertTrue($model->isTerminalStatus('nonexistent'));
    }
}
