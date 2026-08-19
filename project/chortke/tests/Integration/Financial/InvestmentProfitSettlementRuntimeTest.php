<?php

declare(strict_types=1);

namespace Tests\Integration\Financial;

use App\Services\InvestmentService;
use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Real MariaDB/ledger coverage for investment profit and loss settlement.
 * No mocks, alternate container, or test-only production API is used.
 */
final class InvestmentProfitSettlementRuntimeTest extends TestCase
{
    private Database $db;
    private InvestmentService $service;
    /** @var list<int> */
    private array $investmentIds = [];
    /** @var list<int> */
    private array $tradeIds = [];
    private int $transactionFloor;
    private int $ledgerFloor;
    private int $outboxFloor;
    private ?string $originalFeeConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Application::getInstance()->container;
        $this->db = $container->make(Database::class);
        $this->service = $container->make(InvestmentService::class);
        $this->transactionFloor = (int)$this->db->fetchColumn('SELECT COALESCE(MAX(id),0) FROM transactions');
        $this->ledgerFloor = (int)$this->db->fetchColumn('SELECT COALESCE(MAX(id),0) FROM ledger_entries');
        $this->outboxFloor = (int)$this->db->fetchColumn('SELECT COALESCE(MAX(id),0) FROM outbox_events');
        $row = $this->db->fetch("SELECT config_values FROM feature_flags WHERE name='investment_fees'");
        $this->originalFeeConfig = isset($row->config_values) ? (string)$row->config_values : null;
        $this->db->query(
            "UPDATE feature_flags SET config_values=? WHERE name='investment_fees'",
            [json_encode(['site_fee_percent'=>'10','tax_percent'=>'9'], JSON_THROW_ON_ERROR)]
        );
    }

    protected function tearDown(): void
    {
        if ($this->investmentIds !== []) {
            $marks = implode(',', array_fill(0, count($this->investmentIds), '?'));
            $this->db->query("DELETE FROM investment_profits WHERE investment_id IN ({$marks})", $this->investmentIds);
        }
        if ($this->tradeIds !== []) {
            $marks = implode(',', array_fill(0, count($this->tradeIds), '?'));
            $this->db->query("DELETE FROM trading_records WHERE id IN ({$marks})", $this->tradeIds);
        }
        if ($this->investmentIds !== []) {
            $marks = implode(',', array_fill(0, count($this->investmentIds), '?'));
            $this->db->query("DELETE FROM investments WHERE id IN ({$marks})", $this->investmentIds);
        }
        $this->db->query('DELETE FROM ledger_entries WHERE id>?', [$this->ledgerFloor]);
        $this->db->query('DELETE FROM transactions WHERE id>?', [$this->transactionFloor]);
        $this->db->query('DELETE FROM outbox_events WHERE id>?', [$this->outboxFloor]);
        $this->db->query(
            "UPDATE feature_flags SET config_values=? WHERE name='investment_fees'",
            [$this->originalFeeConfig]
        );
        parent::tearDown();
    }

    public function test_profit_loss_replay_and_invalid_trade_are_atomic_and_auditable(): void
    {
        $investmentId = $this->createInvestment('1000');
        $profitTradeId = $this->createTrade('closed');

        $profit = $this->service->applyProfitLossToBatch(
            [$investmentId],
            $profitTradeId,
            '10',
            '2026-W33',
            4
        );
        $this->assertTrue((bool)($profit['success'] ?? false));
        $this->assertSame(1, int_value($profit['processed'] ?? 0));
        $this->assertFalse((bool)($profit['idempotent_replay'] ?? true));

        $investment = $this->db->fetch('SELECT * FROM investments WHERE id=?', [$investmentId]);
        $this->assertNotNull($investment);
        $this->assertMoney('1081.90000000', $investment->current_balance);
        $this->assertMoney('81.90000000', $investment->total_profit);
        $this->assertMoney('0.00000000', $investment->total_loss);
        $this->assertMoney('81.90000000', $investment->profit_earned);

        $profitRow = $this->db->fetch(
            'SELECT * FROM investment_profits WHERE investment_id=? AND trading_record_id=?',
            [$investmentId, $profitTradeId]
        );
        $this->assertNotNull($profitRow);
        $this->assertSame('profit', (string)$profitRow->profit_type);
        $this->assertSame('paid', (string)$profitRow->status);
        $this->assertSame('2026-W33', (string)$profitRow->period);
        $this->assertMoney('100.00000000', $profitRow->gross_amount);
        $this->assertMoney('10.00000000', $profitRow->site_fee_amount);
        $this->assertMoney('8.10000000', $profitRow->tax_amount);
        $this->assertMoney('81.90000000', $profitRow->net_amount);
        $this->assertMoney('1000.00000000', $profitRow->balance_before);
        $this->assertMoney('1081.90000000', $profitRow->balance_after);
        $this->assertNotSame('', (string)$profitRow->transaction_id);

        $this->assertSame(1, $this->countTransactionType('investment_trading_profit'));
        $this->assertSame(1, $this->countTransactionType('investment_platform_fee'));
        $this->assertSame(1, $this->countTransactionType('investment_tax'));
        $this->assertLedgerTransfer('external_trading_return', 'investment_pool', '100.00000000');
        $this->assertLedgerTransfer('investment_pool', 'platform_revenue', '10.00000000');
        $this->assertLedgerTransfer('investment_pool', 'tax_payable', '8.10000000');
        $this->assertAllNewLedgerTransactionsBalanced();

        $transactionCount = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM transactions WHERE id>?', [$this->transactionFloor]);
        $ledgerCount = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM ledger_entries WHERE id>?', [$this->ledgerFloor]);
        $outboxCount = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM outbox_events WHERE id>?', [$this->outboxFloor]);

        $replay = $this->service->applyProfitLossToBatch([$investmentId], $profitTradeId, '10', '2026-W33', 4);
        $this->assertTrue((bool)($replay['success'] ?? false));
        $this->assertSame(0, int_value($replay['processed'] ?? -1));
        $this->assertTrue((bool)($replay['idempotent_replay'] ?? false));
        $this->assertSame(1, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM investment_profits WHERE investment_id=? AND trading_record_id=?',
            [$investmentId, $profitTradeId]
        ));
        $this->assertSame($transactionCount, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM transactions WHERE id>?', [$this->transactionFloor]));
        $this->assertSame($ledgerCount, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM ledger_entries WHERE id>?', [$this->ledgerFloor]));
        $this->assertSame($outboxCount, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM outbox_events WHERE id>?', [$this->outboxFloor]));
        $this->assertMoney('1081.90000000', $this->db->fetchColumn('SELECT current_balance FROM investments WHERE id=?', [$investmentId]));

        $lossTradeId = $this->createTrade('closed');
        $loss = $this->service->applyProfitLossToBatch([$investmentId], $lossTradeId, '-10', '2026-W34', 4);
        $this->assertTrue((bool)($loss['success'] ?? false));
        $this->assertSame(1, int_value($loss['processed'] ?? 0));

        $afterLoss = $this->db->fetch('SELECT * FROM investments WHERE id=?', [$investmentId]);
        $this->assertInstanceOf(\stdClass::class, $afterLoss);
        $this->assertMoney('973.71000000', $afterLoss->current_balance);
        $this->assertMoney('81.90000000', $afterLoss->total_profit);
        $this->assertMoney('108.19000000', $afterLoss->total_loss);
        $this->assertMoney('-26.29000000', $afterLoss->profit_earned);
        $lossRow = $this->db->fetch('SELECT * FROM investment_profits WHERE investment_id=? AND trading_record_id=?', [$investmentId,$lossTradeId]);
        $this->assertInstanceOf(\stdClass::class, $lossRow);
        $this->assertSame('loss', (string)$lossRow->profit_type);
        $this->assertMoney('-108.19000000', $lossRow->gross_amount);
        $this->assertMoney('-108.19000000', $lossRow->net_amount);
        $this->assertMoney('0.00000000', $lossRow->site_fee_amount);
        $this->assertMoney('0.00000000', $lossRow->tax_amount);
        $this->assertSame(1, $this->countTransactionType('investment_trading_loss'));
        $this->assertLedgerTransfer('investment_pool', 'external_trading_loss', '108.19000000');
        $this->assertAllNewLedgerTransactionsBalanced();

        $openTradeId = $this->createTrade('open');
        $beforeInvalid = $this->db->fetch('SELECT current_balance,total_profit,total_loss,profit_earned FROM investments WHERE id=?', [$investmentId]);
        $beforeInvalidTransactions = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM transactions WHERE id>?', [$this->transactionFloor]);
        $invalid = $this->service->applyProfitLossToBatch([$investmentId], $openTradeId, '5', '2026-W35', 4);
        $this->assertFalse((bool)($invalid['success'] ?? true));
        $this->assertSame(0, int_value($invalid['processed'] ?? -1));
        $this->assertFalse((bool)($invalid['idempotent_replay'] ?? true));
        $this->assertSame(0, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM investment_profits WHERE investment_id=? AND trading_record_id=?',
            [$investmentId,$openTradeId]
        ));
        $this->assertSame($beforeInvalidTransactions, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM transactions WHERE id>?', [$this->transactionFloor]));
        $afterInvalid = $this->db->fetch('SELECT current_balance,total_profit,total_loss,profit_earned FROM investments WHERE id=?', [$investmentId]);
        $this->assertSame((array)$beforeInvalid, (array)$afterInvalid);

        try {
            $this->service->applyProfitLossToBatch([$investmentId], $lossTradeId, '0', '2026-W36', 4);
            $this->fail('Zero-percent settlement must be rejected before opening a transaction.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('درصد', $e->getMessage());
        }
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_concurrent_workers_settle_each_trade_investment_pair_exactly_once(): void
    {
        $this->assertSame(1, (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='investments' AND index_name='uq_investments_one_active_per_user'"
        ));
        $this->assertSame(1, (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='prediction_bets' AND index_name='uq_prediction_bets_user_game'"
        ));
        $investmentId = $this->createInvestment('1000');
        $tradeId = $this->createTrade('closed');
        $root = dirname(__DIR__, 3);
        $barrierDir = sys_get_temp_dir() . '/investment-settlement-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($barrierDir, 0700));
        $worker = $barrierDir . '/worker.php';
        $script = <<<'PHP'
<?php
require $argv[1] . '/bootstrap/testing.php';
$ready = $argv[4] . '/ready-' . $argv[5];
file_put_contents($ready, '1');
$deadline = microtime(true) + 10;
while (!is_file($argv[4] . '/go')) {
    if (microtime(true) > $deadline) { fwrite(STDERR, 'barrier timeout'); exit(70); }
    usleep(1000);
}
$service = \Core\Application::getInstance()->container->make(\App\Services\InvestmentService::class);
$result = $service->applyProfitLossToBatch([(int)$argv[2]], (int)$argv[3], '10', '2026-W40', 4);
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
PHP;
        file_put_contents($worker, $script);

        $processes = [];
        try {
            for ($i = 1; $i <= 2; $i++) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY,$worker,$root,(string)$investmentId,(string)$tradeId,$barrierDir,(string)$i],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
                    $pipes,
                    $root
                );
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process,$pipes];
            }

            $deadline = microtime(true) + 10;
            while ((!is_file($barrierDir.'/ready-1') || !is_file($barrierDir.'/ready-2')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($barrierDir.'/ready-1');
            $this->assertFileExists($barrierDir.'/ready-2');
            file_put_contents($barrierDir.'/go', '1');

            $results = [];
            foreach ($processes as [$process,$pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $diagnostic = is_string($stderr) && $stderr !== '' ? $stderr : str_value($stdout);
                $this->assertSame(0, $exit, $diagnostic);
                $stdoutText = str_value($stdout);
                $lines = array_values(array_filter(array_map('trim', explode("\n", $stdoutText))));
                $lastLine = end($lines);
                $this->assertIsString($lastLine);
                $decoded = json_decode($lastLine, true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $results[] = $decoded;
            }
            $processes = [];

            $processedValues = array_values(array_unique(array_map(
                static fn(array $result): int => (int)($result['processed'] ?? -1),
                $results
            )));
            sort($processedValues);
            $this->assertSame([0,1], $processedValues);
            $this->assertSame(1, count(array_filter($results, static fn(array $result): bool => (bool)($result['idempotent_replay'] ?? false))));
            $this->assertSame(1, (int)$this->db->fetchColumn(
                'SELECT COUNT(*) FROM investment_profits WHERE investment_id=? AND trading_record_id=?',
                [$investmentId,$tradeId]
            ));
            $this->assertMoney('1081.90000000', $this->db->fetchColumn('SELECT current_balance FROM investments WHERE id=?', [$investmentId]));
            $this->assertSame(1, $this->countTransactionType('investment_trading_profit'));
            $this->assertSame(1, $this->countTransactionType('investment_platform_fee'));
            $this->assertSame(1, $this->countTransactionType('investment_tax'));
            $this->assertAllNewLedgerTransactionsBalanced();
        } finally {
            foreach ($processes as [$process,$pipes]) {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (is_resource($process)) proc_terminate($process);
            }
            foreach (glob($barrierDir.'/*') ?: [] as $file) @unlink($file);
            @rmdir($barrierDir);
        }
    }

    private function createInvestment(string $balance): int
    {
        $this->db->query(
            "INSERT INTO investments (user_id,amount,currency,current_balance,status,profit_earned,total_profit,total_loss,start_date,created_at,updated_at)
             VALUES (1,?,'usdt',?,'active',0,0,0,DATE_SUB(NOW(),INTERVAL 8 DAY),NOW(),NOW())",
            [$balance,$balance]
        );
        $id = (int)$this->db->lastInsertId();
        $this->investmentIds[] = $id;
        return $id;
    }

    private function createTrade(string $status): int
    {
        $this->db->query(
            "INSERT INTO trading_records (user_id,admin_id,investment_id,symbol,pair,type,direction,amount,price,open_price,close_price,profit_loss,profit_loss_amount,profit_loss_percent,currency,status,is_deleted,open_time,close_time,created_at,updated_at)
             VALUES (1,4,?,'XAUUSD','XAU/USD','buy','buy',1,2000,2000,2100,0,0,0,'usdt',?,0,DATE_SUB(NOW(),INTERVAL 1 DAY),NOW(),NOW(),NOW())",
            [$this->investmentIds[0] ?? 0,$status]
        );
        $id = (int)$this->db->lastInsertId();
        $this->tradeIds[] = $id;
        return $id;
    }

    private function countTransactionType(string $type): int
    {
        return (int)$this->db->fetchColumn('SELECT COUNT(*) FROM transactions WHERE id>? AND type=?', [$this->transactionFloor,$type]);
    }

    private function assertLedgerTransfer(string $debitAccount, string $creditAccount, string $amount): void
    {
        $transactionId = $this->db->fetchColumn(
            'SELECT d.transaction_id FROM ledger_entries d JOIN ledger_entries c ON c.transaction_id=d.transaction_id WHERE d.id>? AND d.account=? AND d.debit>0 AND c.account=? AND c.credit>0 LIMIT 1',
            [$this->ledgerFloor,$debitAccount,$creditAccount]
        );
        $this->assertNotFalse($transactionId, "Missing ledger transfer {$debitAccount} -> {$creditAccount}.");
        $row = $this->db->fetch('SELECT SUM(debit) debit,SUM(credit) credit FROM ledger_entries WHERE transaction_id=?', [str_value($transactionId)]);
        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertMoney($amount, $row->debit);
        $this->assertMoney($amount, $row->credit);
    }

    private function assertAllNewLedgerTransactionsBalanced(): void
    {
        $unbalanced = (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries WHERE id>? GROUP BY transaction_id HAVING ABS(SUM(debit)-SUM(credit))>0.00000001) x',
            [$this->ledgerFloor]
        );
        $this->assertSame(0, $unbalanced);
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame($expected, number_format(float_value($actual), 8, '.', ''));
    }
}
