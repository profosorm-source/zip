<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for BUGFIX-VERIFY-SECTION-2026-06.
 *
 * Background:
 *   Five `verifySection*` methods were embedded inside production
 *   controllers as ad-hoc E2E QA scenarios:
 *     - LotteryController::verifyLotterySection8
 *     - CustomTaskController::verifyCustomTaskSection11
 *     - VitrineController::verifyVitrineSection12      (publicly routed!)
 *     - PaymentController::verifyScheduledPaymentSection85
 *     - InfluencerController::verifyInfluencerSection9
 *
 *   Risks they introduced:
 *     1. The Vitrine one was wired to /system-vitrine-verification on
 *        the public route group, so any unauthenticated visitor could
 *        trigger creation of fake users + wallets in the live database.
 *     2. The other four were dead code (no route, no caller) inflating
 *        each controller by ~200 lines (~95 KB total).
 *     3. They are the kind of code that future authors silently copy:
 *        "we already do this kind of thing in CustomTaskController,
 *        so it must be OK to add another one."
 *
 *   They were removed and rehomed conceptually to tests/Integration/.
 *   This test pins the absence so they cannot be re-introduced unnoticed.
 */
/**
 * @group architecture
 */
class NoVerifySectionMethodsTest extends TestCase
{
    /** @test */
    public function controllers_do_not_contain_verifysection_qa_methods(): void
    {
        $controllerRoot = dirname(__DIR__, 3) . '/app/Controllers';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllerRoot));

        $offenders = [];
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $src = file_get_contents($file->getPathname());
            $this->assertIsString($src);
            // Strip comments so a docblock referring to the legacy methods
            // (e.g. this test's filename inside the project README) does
            // not trip the assertion.
            $codeOnly = (string)preg_replace('!//[^\n]*|/\*.*?\*/!ms', '', $src);
            if (preg_match_all('/function\s+(verify\w*Section\w*)\s*\(/i', $codeOnly, $m)) {
                foreach ($m[1] as $method) {
                    $offenders[] = $file->getPathname() . '::' . $method . '()';
                }
            }
        }

        $this->assertEmpty($offenders,
            "Production controllers must not contain verifySection* QA methods. "
            . "Move E2E scenarios to tests/Integration/ instead. Offenders:\n  - "
            . implode("\n  - ", $offenders));
    }

    /** @test */
    public function public_routes_do_not_expose_a_verification_qa_endpoint(): void
    {
        $publicRoutes = file_get_contents(dirname(__DIR__, 3) . '/routes/public.php');
        $this->assertIsString($publicRoutes);
        $this->assertStringNotContainsString(
            'system-vitrine-verification',
            $publicRoutes,
            'The /system-vitrine-verification route was an unauthenticated public '
            . 'wrapper around an E2E QA method that mutated the live database. '
            . 'It must stay removed.'
        );
        // Generic guard: no /system-*-verification or /test-*-run style routes
        // on the public group.
        $this->assertNotRegExp(
            '!router->get\([\'"]/(?:system|test|qa|verify)-[^\'"]*-(?:verification|run|scenario)[\'"]!',
            $publicRoutes,
            'Public routes must not expose verification / scenario runners.'
        );
    }
}
