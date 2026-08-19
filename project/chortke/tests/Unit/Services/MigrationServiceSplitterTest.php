<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\MigrationService;

/**
 * Regression test for BUGFIX-MIGRATION-MULTI-STMT-2026-06.
 *
 * Background:
 *   Previously, MigrationService called PDO::exec($entireFile) which against
 *   MySQL/MariaDB only checks the FIRST statement's error and silently
 *   swallows errors from subsequent statements. The platform's wave-3 and
 *   wave-4 reconciliation migrations contained multi-column ALTER batches
 *   whose middle clauses sometimes failed (e.g. depending on the order of
 *   prior CREATE/ALTER), leaving the schema half-applied while
 *   schema_migrations marked the file as fully executed.
 *
 * This test pins the contract of splitSqlStatements() so the runner can
 * never silently regress to single-call exec() again.
 */
class MigrationServiceSplitterTest extends TestCase
{
    /** @test */
    public function splits_a_simple_multi_statement_sql_into_pieces(): void
    {
        $sql = "UPDATE a SET x = 1; UPDATE b SET y = 2;";
        $stmts = MigrationService::splitSqlStatements($sql);
        $stmts = array_values(array_filter(array_map('trim', $stmts)));
        $this->assertCount(2, $stmts);
        $this->assertSame('UPDATE a SET x = 1', $stmts[0]);
        $this->assertSame('UPDATE b SET y = 2', $stmts[1]);
    }

    /** @test */
    public function semicolon_inside_string_literal_does_not_split(): void
    {
        $sql = "INSERT INTO t (msg) VALUES ('hello; world'); UPDATE x SET y = 1;";
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString("'hello; world'", $stmts[0]);
    }

    /** @test */
    public function semicolon_inside_double_quoted_string_does_not_split(): void
    {
        $sql = 'INSERT INTO t (msg) VALUES ("a;b"); UPDATE x SET y=1;';
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(2, $stmts);
    }

    /** @test */
    public function escaped_quote_inside_string_keeps_string_open(): void
    {
        $sql = "INSERT INTO t VALUES ('it\\'s; ok'); UPDATE x SET y=1;";
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(2, $stmts,
            'Escaped single-quote must NOT close the string literal, so the '
            . "';' inside it must not act as a statement terminator.");
    }

    /** @test */
    public function line_comment_does_not_split_on_semicolon(): void
    {
        $sql = "-- this; is; a; comment\nSELECT 1; SELECT 2;";
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        // The line comment + first SELECT live together in stmts[0];
        // stmts[1] is the second SELECT. Crucially we want exactly TWO
        // executable pieces, not five (which is what a naive split would yield).
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('SELECT 1', $stmts[0]);
        $this->assertStringContainsString('SELECT 2', $stmts[1]);
    }

    /** @test */
    public function block_comment_does_not_split_on_semicolon(): void
    {
        $sql = "/* a; b; c */ SELECT 1; SELECT 2;";
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(2, $stmts);
    }

    /** @test */
    public function multi_column_alter_batch_yields_a_single_statement(): void
    {
        // This is the canonical shape that previously bit us in production.
        $sql = <<<SQL
ALTER TABLE bank_cards
  ADD COLUMN IF NOT EXISTS owner_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS iban VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS card_hash VARCHAR(128) NULL;
SQL;
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS owner_name', $stmts[0]);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS iban',       $stmts[0]);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS card_hash',  $stmts[0]);
    }

    /** @test */
    public function trailing_statement_without_semicolon_is_still_returned(): void
    {
        $sql = "SELECT 1; SELECT 2";
        $stmts = array_values(array_filter(array_map('trim', MigrationService::splitSqlStatements($sql))));
        $this->assertCount(2, $stmts);
        $this->assertSame('SELECT 2', $stmts[1]);
    }

    /**
     * @test  BUGFIX-MIGRATION-STMT-COMMENT-2026-06
     *
     * The runner previously skipped any statement whose first non-whitespace
     * char was `-` (because it tested str_starts_with($trimmed, '--')). With
     * the splitter keeping comments inside their statement, a typical
     * migration that opens with a description line:
     *
     *   -- adds X
     *   ALTER TABLE t ADD COLUMN x …;
     *   CREATE INDEX i ON t (x);
     *
     * would lose the ALTER entirely and only run the CREATE INDEX — which
     * then failed because the column did not exist. Splitter itself is OK;
     * we just want to assert that callers can still detect "statement
     * contains real SQL" after stripping the leading comment.
     */
    public function statement_with_leading_comment_still_contains_real_sql(): void
    {
        $sql = "-- description\nALTER TABLE t ADD COLUMN x INT;\nCREATE INDEX ix ON t (x);";
        $stmts = MigrationService::splitSqlStatements($sql);
        // strip comments the same way the runner now does
        $codeOnlies = array_map(
            fn(string $s): string => trim((string)preg_replace('!--[^\n]*|/\*.*?\*/!ms', '', $s)),
            $stmts
        );
        $codeOnlies = array_values(array_filter($codeOnlies, fn($s) => $s !== ''));
        $this->assertCount(2, $codeOnlies,
            'Both ALTER and CREATE INDEX must survive comment stripping.');
        $this->assertStringContainsString('ALTER TABLE', $codeOnlies[0]);
        $this->assertStringContainsString('CREATE INDEX', $codeOnlies[1]);
    }
}
