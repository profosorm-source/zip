<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for BUGFIX-SIDEBAR-DB-IN-VIEW-2026-06.
 *
 * Background:
 *   The admin sidebar partial used to issue eight separate
 *   Database::getInstance() queries inline — one per nav-item badge:
 *       withdrawals/pending, kyc_verifications/pending,
 *       account_deletion_logs/requested, payment_logs/pending_verification,
 *       tickets/open|pending, bug_report_comments, sentry_issues/unresolved,
 *       system_alerts/active.
 *
 *   Two problems with that:
 *     1. Every admin page paid for eight extra round-trips and embedded
 *        raw SQL inside the view layer (violates the project's
 *        "models own DB access" rule).
 *     2. If the DB connection or any table was unhappy, the view itself
 *        would throw — taking the entire admin chrome down with it.
 *
 *   Fix: introduce a single helper `sidebar_badges()` in
 *   helpers/view_helper.php that resolves all counters through the
 *   existing service / model layer (KYCQueryService, WithdrawalQueryService,
 *   AccountDeletionLog, Ticket model, ...). The helper is invoked once
 *   per admin request from `view()` and exposed as `$sidebarBadges`.
 *
 *   This test pins the contract so the inline queries cannot drift back
 *   into the view.
 */
/**
 * @group architecture
 */
class SidebarNoDbInViewTest extends TestCase
{
    private string $sidebarSrc;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/views/partials/admin/sidebar.php');
        $this->assertIsString($source);
        $this->sidebarSrc = $source;
    }

    /** @test */
    public function sidebar_does_not_call_database_directly(): void
    {
        // The view must not touch Core\Database in any form.
        $codeOnly = (string)preg_replace('!//[^\n]*|/\*.*?\*/!ms', '', $this->sidebarSrc);
        $this->assertStringNotContainsString('Database::getInstance', $codeOnly,
            'Admin sidebar must not call Database::getInstance() directly.');
        $this->assertStringNotContainsString('->selectOne(', $codeOnly,
            'Admin sidebar must not call ->selectOne() — go through sidebar_badges().');
        $this->assertDoesNotContainRawSql();
    }

    /** @test */
    public function sidebar_reads_counters_from_helper_array(): void
    {
        $this->assertStringContainsString('$sidebarBadges', $this->sidebarSrc,
            'Admin sidebar must consume the $sidebarBadges array prepared by view_helper.');
        // At least the canonical counters must be referenced.
        foreach (['withdrawals_pending','kyc_pending','tickets_open','sentry_unresolved'] as $key) {
            $this->assertStringContainsString("'$key'", $this->sidebarSrc,
                "Sidebar must reference the '$key' counter from \$sidebarBadges.");
        }
    }

    /** @test */
    public function helper_function_exists_and_returns_expected_keys(): void
    {
        require_once dirname(__DIR__, 3) . '/helpers/view_helper.php';
        $this->assertTrue(function_exists('sidebar_badges'),
            'sidebar_badges() helper must be defined in helpers/view_helper.php.');

        $badges = sidebar_badges();
        $this->assertIsArray($badges);
        $expectedKeys = [
            'kyc_pending', 'withdrawals_pending', 'account_deletions',
            'payment_logs_pending', 'tickets_open', 'bug_reports',
            'sentry_unresolved', 'system_alerts_active',
        ];
        foreach ($expectedKeys as $k) {
            $this->assertArrayHasKey($k, $badges,
                "sidebar_badges() must always return key '$k' (even if zero).");
            $this->assertIsInt($badges[$k], "Counter '$k' must be int.");
        }
    }

    private function assertDoesNotContainRawSql(): void
    {
        $codeOnly = (string)preg_replace('!//[^\n]*|/\*.*?\*/!ms', '', $this->sidebarSrc);
        $this->assertNotRegExp(
            '/["\'](SELECT |INSERT INTO |UPDATE |DELETE FROM )/i',
            $codeOnly,
            'Sidebar view must not embed raw SQL strings.'
        );
    }
}
