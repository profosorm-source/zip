<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;
use App\Services\ReconciliationService;
use App\Services\Withdrawal\WithdrawalAdminService;

/**
 * CLI for the safe stuck-withdrawal review workflow (Section 8.5 / 8.7).
 *
 * This command is a thin orchestrator on top of:
 *   - ReconciliationService (detect/flag/list/admin-ops)
 *   - WithdrawalAdminService::autoResolveStuck() (safe auto-fix)
 *
 * Usage:
 *   php cli.php withdrawals:review:scan      [--minutes=120] [--limit=200]
 *   php cli.php withdrawals:review:auto-fix  [--stable=30]   [--limit=50] --admin-id=<id>
 *   php cli.php withdrawals:review:list      [--limit=50]    [--offset=0]
 *   php cli.php withdrawals:review:resolve   <id> --note="..." --admin-id=<id>
 *   php cli.php withdrawals:review:dismiss   <id> --note="..." --admin-id=<id>
 */
class StuckWithdrawalReviewCommand
{
    private ReconciliationService $reconciliation;
    private WithdrawalAdminService $withdrawalAdminService;
    public function __construct(
        ReconciliationService $reconciliation,
        WithdrawalAdminService $withdrawalAdminService,
        LoggerInterface $logger
    ) {        $this->reconciliation = $reconciliation;
        $this->withdrawalAdminService = $withdrawalAdminService;
        unset($logger);
}

    /** @param array<int|string, mixed> $argv */

    public function run(array $argv): void
    {
        $command = str_value($argv[1] ?? 'withdrawals:review:list');
        $opts = $this->parseOptions($argv);

        switch ($command) {
            case 'withdrawals:review:scan':
                $this->scan($opts);
                return;
            case 'withdrawals:review:auto-fix':
                $this->autoFix($opts);
                return;
            case 'withdrawals:review:list':
                $this->listOpen($opts);
                return;
            case 'withdrawals:review:resolve':
                $this->resolve($argv[2] ?? null, $opts);
                return;
            case 'withdrawals:review:dismiss':
                $this->dismiss($argv[2] ?? null, $opts);
                return;
            default:
                throw new \InvalidArgumentException("Unsupported command: {$command}");
        }
    }

    /** @param array<string, mixed> $opts */
    private function optionInt(array $opts, string $key, int $default): int
    {
        $value = $opts[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    /** @param array<string, mixed> $opts */
    private function scan(array $opts): void
    {
        $minutes = $this->optionInt($opts, 'minutes', ReconciliationService::DEFAULT_STUCK_MINUTES);
        $limit = $this->optionInt($opts, 'limit', ReconciliationService::STUCK_SCAN_BATCH);

        $r = $this->reconciliation->flagStuckWithdrawals($minutes, $limit);
        echo sprintf(
            "[scan] scanned=%d flagged=%d notified=%d skipped=%d (older_than=%dmin)\n",
            $r['scanned'], $r['flagged'], $r['notified'], $r['skipped'], $minutes
        );
    }

    /** @param array<string, mixed> $opts */
    private function autoFix(array $opts): void
    {
        $stable  = (bool)($opts['stable'] ?? true);
        $limit = $this->optionInt($opts, 'limit', 50);
        // L-15 FIX: defaulting to admin-id 0 wrote financial/administrative audit entries with a
        // non-existent actor, destroying accountability for withdrawal decisions. The acting
        // admin must now be identified explicitly.
        $adminId = $this->requireAdminId($opts);

        $r = $this->withdrawalAdminService->autoResolveStuck($adminId, $stable, $limit);
        echo sprintf(
            "[auto-fix] scanned=%d fixed=%d escalated=%d errors=%d (stable=%dmin)\n",
            int_value($r['scanned'] ?? 0),
            int_value($r['fixed'] ?? $r['resolved'] ?? 0),
            int_value($r['escalated'] ?? 0),
            int_value($r['errors'] ?? $r['failed'] ?? 0),
            $stable
        );
    }

    /** @param array<string, mixed> $opts */
    private function listOpen(array $opts): void
    {
        $limit = $this->optionInt($opts, 'limit', 50);
        $offset = $this->optionInt($opts, 'offset', 0);

        $rows = $this->reconciliation->listOpenReviews($limit, $offset);
        if (empty($rows)) {
            echo "No open stuck-withdrawal reviews.\n";
            return;
        }
        foreach ($rows as $r) {
            echo sprintf(
                "#%d  w=#%d  user=%d  status=%s  sev=%s  reason=%s  stuck=%dmin  amount=%s %s  notified=%s\n",
                (int)$r->id,
                (int)$r->withdrawal_id,
                (int)$r->user_id,
                (string)$r->review_status,
                (string)$r->severity,
                (string)$r->reason_code,
                (int)$r->stuck_minutes,
                (string)($r->withdrawal_amount ?? '?'),
                strtoupper((string)($r->withdrawal_currency ?? '')),
                $r->notified_admin_at ? 'yes' : 'no'
            );
        }
    }

    /** @param array<string, mixed> $opts */
    private function resolve(?string $idArg, array $opts): void
    {
        $id = (int)($idArg ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Review id is required.');
        }
        $noteValue = $opts['note'] ?? '';
        $note = is_string($noteValue) ? $noteValue : '';
        if ($note === '') {
            throw new \InvalidArgumentException('--note="..." is required.');
        }
        // L-15 FIX: defaulting to admin-id 0 wrote financial/administrative audit entries with a
        // non-existent actor, destroying accountability for withdrawal decisions. The acting
        // admin must now be identified explicitly.
        $adminId = $this->requireAdminId($opts);
        $ok = $this->reconciliation->adminResolveReview($id, $adminId, $note);
        echo $ok ? "Resolved review #{$id}.\n" : "Review #{$id} not in resolvable state.\n";
    }

    /** @param array<string, mixed> $opts */
    private function dismiss(?string $idArg, array $opts): void
    {
        $id = (int)($idArg ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Review id is required.');
        }
        $noteValue = $opts['note'] ?? '';
        $note = is_string($noteValue) ? $noteValue : '';
        if ($note === '') {
            throw new \InvalidArgumentException('--note="..." is required.');
        }
        // L-15 FIX: defaulting to admin-id 0 wrote financial/administrative audit entries with a
        // non-existent actor, destroying accountability for withdrawal decisions. The acting
        // admin must now be identified explicitly.
        $adminId = $this->requireAdminId($opts);
        $ok = $this->reconciliation->dismissReview($id, $adminId, $note);
        echo $ok ? "Dismissed review #{$id}.\n" : "Review #{$id} not in dismissable state.\n";
    }

    /**
     * L-15: resolve the acting admin id, refusing the unattributed default.
     *
     * @param array<string, mixed> $opts
     */
    private function requireAdminId(array $opts): int
    {
        $adminId = $this->optionInt($opts, 'admin-id', 0);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('--admin-id=<id> is required so the action is attributable.');
        }
        return $adminId;
    }

    /**
     * @param array<int|string, mixed> $argv
     * @return array<string, mixed>
     */
    private function parseOptions(array $argv): array
    {
        $opts = [];
        $count = count($argv);
        for ($i = 2; $i < $count; $i++) {
            $arg = (string)$argv[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $opts[$k] = trim($v, "\"' ");
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with((string)$next, '--')) {
                    $opts[$arg] = trim((string)$next, "\"' ");
                    $i++;
                } else {
                    $opts[$arg] = true;
                }
            }
        }
        return $opts;
    }
}
