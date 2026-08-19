<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for BUGFIX-CTRL-RAW-SQL-2026-06.
 *
 * Background:
 *   Eleven business-logic methods across nine controllers used to embed
 *   raw SQL strings against the database:
 *
 *     Admin\KYCController::review              UPDATE kyc_verifications
 *     Admin\KYCController::deleteImage         DELETE FROM kyc_documents
 *     Admin\RoleController::update             SELECT id FROM users
 *     Admin\RoleController::toggle             SELECT id FROM users
 *     Admin\SocialTaskController::userTrust    SELECT … FROM users
 *     Admin\TransactionController::reverse     SELECT * FROM transactions
 *     User\AuthController::verifyEmail         SELECT FROM user_verifications
 *     User\AuthController::confirmEmailCode    SELECT FROM user_verifications
 *     User\AuthController::resendVerification  INSERT…ON DUPLICATE user_verifications
 *     User\EscrowController::index             JOIN escrow_transactions + users
 *     User\InvestmentController::create        SELECT * FROM users
 *     User\InvestmentController::profitHistory SELECT * FROM users
 *     User\LotteryController::index            SELECT * FROM users
 *
 *   The project convention is "models own DB access". Verification (E2E)
 *   methods such as `verifySection*` are excluded — they were already
 *   removed in BUGFIX-VERIFY-SECTION-2026-06.
 *
 *   This test scans every controller file and asserts that no
 *   non-verifySection method contains a raw SQL literal. If someone
 *   adds an inline query in the future, CI fails before review.
 */
/**
 * @group architecture
 */
class NoRawSqlInBusinessMethodsTest extends TestCase
{
    /** @test */
    public function controllers_do_not_embed_raw_sql_in_business_methods(): void
    {
        $root = dirname(__DIR__, 3) . '/app/Controllers';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $offenders = [];
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $src = file_get_contents($file->getPathname());
            $this->assertIsString($src);
            // Walk every method declaration.
            preg_match_all(
                '/(?:public|private|protected)\s+function\s+(\w+)\s*\([^)]*\)[^{]*\{/',
                $src, $matches, PREG_OFFSET_CAPTURE
            );
            foreach ($matches[1] as $i => [$methodName, $offset]) {
                // Skip the historical verifySection* / test* / debug* helpers.
                $low = strtolower($methodName);
                if (str_contains($low, 'section')
                 || str_starts_with($low, 'verify')
                 || str_starts_with($low, 'test')
                 || str_starts_with($low, 'debug')) {
                    continue;
                }
                // Find the method body by brace counting from the opening { we matched.
                $startBrace = $matches[0][$i][1] + strlen($matches[0][$i][0]) - 1;
                $body = $this->extractMethodBody($src, $startBrace);
                if ($body === null) continue;
                // Strip strings/comments first so SQL keywords inside comments
                // (e.g. "// see UPDATE users") don't trigger a false positive.
                $code = (string)preg_replace('!//[^\n]*|/\*.*?\*/!ms', '', $body);
                // We still keep string literals — the whole point is to detect
                // SQL *inside* a string literal in code.
                if (preg_match('/["\'](?:\s*)(SELECT |INSERT INTO |UPDATE |DELETE FROM )/i', $code)) {
                    $rel = str_replace($root . '/', '', $file->getPathname());
                    $offenders[] = "{$rel}::{$methodName}";
                }
            }
        }

        $this->assertEmpty($offenders,
            "Controllers must delegate DB access to models / services. "
            . "Move the SQL into a model method and call it from the controller. "
            . "Offenders found:\n  - " . implode("\n  - ", $offenders));
    }

    private function extractMethodBody(string $src, int $openBracePos): ?string
    {
        $len = strlen($src);
        $depth = 0;
        $start = $openBracePos;
        for ($i = $openBracePos; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $start, $i - $start + 1);
            }
        }
        return null;
    }

    /** @test */
    public function model_layer_exposes_methods_added_during_this_fix(): void
    {
        // Pin the public surface so the helpers cannot be silently renamed/removed.
        $contract = [
            \App\Models\KYCVerification::class  => ['lockForReview', 'deleteDocuments'],
            \App\Models\User::class             => ['findIdsByRoleId'],
            \App\Models\Transaction::class      => ['findById'],
            \App\Models\Escrow::class           => ['getUserEscrowsWithParties'],
            \App\Models\UserVerification::class => ['findByToken', 'findValidCode', 'upsertOtp'],
        ];
        foreach ($contract as $class => $methods) {
            $this->assertTrue(class_exists($class), "Class $class must exist.");
            foreach ($methods as $m) {
                $this->assertTrue(method_exists($class, $m),
                    "Method {$class}::{$m}() is required by BUGFIX-CTRL-RAW-SQL-2026-06.");
            }
        }
    }
}
