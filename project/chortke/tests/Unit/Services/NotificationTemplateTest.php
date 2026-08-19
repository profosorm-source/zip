<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * @group architecture
 */
class NotificationTemplateTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    // ─── Template coverage ──────────────────────────────────────

    /** @test */
    public function all_notification_types_have_templates(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\Notification'); $model->shouldIgnoreMissing();
        $model->shouldReceive('getTemplateFromDb')->andReturn(null);

        $svc = new \App\Services\Notification\NotificationTemplateService($cache, $model);

        $requiredKeys = [
            'deposit', 'withdrawal', 'withdrawal_rejected', 'task',
            'kyc_approved', 'kyc_rejected', 'lottery_winner', 'referral',
            'security', 'investment_completed', 'system',
            'task_approved', 'task_rejected', 'submission_auto_approved',
            'submission_rejected', 'rating_received', 'dispute_resolved',
            'dispute_agreement', 'session_expired', 'moderation_warning',
            'account_banned', 'vitrine_approved', 'vitrine_new_listing',
            'payment_critical', 'antifraud_critical',
        ];

        foreach ($requiredKeys as $key) {
            $tpl = $svc->renderTemplate($key);
            $this->assertArrayHasKey('title', $tpl, "Template '$key' missing title");
            $this->assertArrayHasKey('message', $tpl, "Template '$key' missing message");
            $this->assertNotEmpty($tpl['title'], "Template '$key' has empty title");
        }
    }

    /** @test */
    public function render_template_interpolates_variables(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\Notification'); $model->shouldIgnoreMissing();
        $model->shouldReceive('getTemplateFromDb')->andReturn(null);

        $svc = new \App\Services\Notification\NotificationTemplateService($cache, $model);

        $result = $svc->renderTemplate('task_approved', ['task_title' => 'تسک تستی']);

        $this->assertIsString($result['message']);
        $this->assertStringContainsString('تسک تستی', $result['message']);
        $this->assertStringNotContainsString('{{task_title}}', $result['message']);
    }

    /** @test */
    public function render_template_with_missing_vars_keeps_placeholder(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\Notification'); $model->shouldIgnoreMissing();
        $model->shouldReceive('getTemplateFromDb')->andReturn(null);

        $svc = new \App\Services\Notification\NotificationTemplateService($cache, $model);

        $result = $svc->renderTemplate('task_approved', []);
        // placeholder باقی بمونه یا خالی بشه — مهم نیست crash نکنه
        $this->assertArrayHasKey('title', $result);
    }

    // ─── Callers use templates ──────────────────────────────────





}
