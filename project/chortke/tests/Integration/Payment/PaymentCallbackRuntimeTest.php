<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Payment\PaymentCommandService;
use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\PaymentRuntimeFactory;

/**
 * Runtime payment callback contract with a deterministic gateway boundary.
 * All persistence, idempotency, saga, wallet, ledger and outbox components are real.
 */
final class PaymentCallbackRuntimeTest extends TestCase
{
    private Database $db;
    private PaymentCommandService $service;
    private int $userId = 1;
    private string $authority;
    private string $nonce;
    private int $paymentId;
    private string $amount = '12500.00000000';
    private string $walletBalanceBefore;
    private int $idempotencyIdBefore;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        config_set('app.key', 'testing-app-key-32-characters-long!!');
        $this->outputBufferLevel = ob_get_level();
        ob_start();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-payment-runtime-test.log');
        $container = Application::getInstance()->container;
        $this->db = $container->make(Database::class);
        $this->service = PaymentRuntimeFactory::make();

        $this->authority = 'RUNTIME' . strtoupper(bin2hex(random_bytes(8)));
        $this->nonce = bin2hex(random_bytes(16));
        $this->walletBalanceBefore = (string) $this->db->fetchColumn(
            'SELECT balance_irt FROM wallets WHERE user_id = ?',
            [$this->userId]
        );
        $this->idempotencyIdBefore = (int) $this->db->fetchColumn('SELECT COALESCE(MAX(id),0) FROM idempotency_keys');

        $this->db->query(
            "INSERT INTO payment_logs"
            . " (user_id,gateway,amount,currency,status,authority,request_data,idempotency_key,created_at,updated_at)"
            . " VALUES (?, 'runtime-test', ?, 'irt', 'pending', ?, ?, ?, NOW(), NOW())",
            [
                $this->userId,
                $this->amount,
                $this->authority,
                json_encode(['callback_nonce' => $this->nonce], JSON_THROW_ON_ERROR),
                $this->nonce,
            ]
        );
        $this->paymentId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->authority)) {
            $this->db->query('DELETE FROM outbox_events WHERE aggregate_type = ? AND aggregate_id = ?', ['payment', (string) $this->paymentId]);
            $this->db->query('DELETE FROM idempotency_keys WHERE id > ? AND user_id = ?', [$this->idempotencyIdBefore, $this->userId]);
            $this->db->query('DELETE FROM transactions WHERE gateway_transaction_id = ?', [$this->authority]);
            $this->db->query('DELETE FROM payment_logs WHERE authority = ?', [$this->authority]);
            if (isset($this->walletBalanceBefore)) {
                $this->db->query(
                    'UPDATE wallets SET balance_irt = ?, updated_at = NOW() WHERE user_id = ?',
                    [$this->walletBalanceBefore, $this->userId]
                );
            }
        }
        while (isset($this->outputBufferLevel) && ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function test_successful_callback_credits_once_completes_log_and_records_outbox(): void
    {
        $first = $this->service->callback('runtime-test', $this->validPayload(), $this->userId, '127.0.0.1', 'Payment-Runtime-Test/1.0');
        $this->assertTrue((bool) ($first['success'] ?? false), (json_encode($first) ?: ''));
        $this->assertSame('RUNTIME-REF-001', $first['ref_id'] ?? null);

        $payment = $this->paymentRow();
        $this->assertSame('completed', (string) $payment->status);
        $this->assertSame('RUNTIME-REF-001', (string) $payment->ref_id);
        $this->assertNotNull($payment->paid_at);

        $expectedBalance = bcadd($this->walletBalanceBefore, $this->amount, 8);
        $this->assertSame($expectedBalance, $this->walletBalance());
        $this->assertSame(1, $this->gatewayDepositTransactionCount());

        $events = $this->db->fetchAll(
            "SELECT event_type,payload FROM outbox_events WHERE aggregate_type='payment' AND aggregate_id=? ORDER BY id",
            [(string) $this->paymentId]
        );
        $this->assertCount(2, $events);
        $eventTypes = array_values(array_unique(array_map(static fn(object $e): string => (string) $e->event_type, $events)));
        sort($eventTypes);
        $this->assertSame(['notification.deposit_success', 'payment.completed'], $eventTypes);

        $idempotency = $this->db->fetch(
            "SELECT status,result FROM idempotency_keys WHERE id > ? AND user_id = ? AND action = 'payment_callback' ORDER BY id DESC LIMIT 1",
            [$this->idempotencyIdBefore, $this->userId]
        );
        $this->assertNotNull($idempotency);
        $this->assertSame('completed', (string) $idempotency->status);

        $second = $this->service->callback('runtime-test', $this->validPayload(), $this->userId, '127.0.0.1', 'Payment-Runtime-Test/1.0');
        $this->assertFalse((bool) ($second['success'] ?? true));
        $this->assertStringContainsString('قبلاً تکمیل', str_value($second['message'] ?? ''));
        $this->assertSame($expectedBalance, $this->walletBalance());
        $this->assertSame(1, $this->gatewayDepositTransactionCount());
        $this->assertSame(2, (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM outbox_events WHERE aggregate_type='payment' AND aggregate_id=?",
            [(string) $this->paymentId]
        ));
    }

    public function test_amount_mismatch_fails_without_any_financial_side_effect(): void
    {
        $payload = $this->validPayload();
        $payload['amount'] = '99999.00000000';

        $result = $this->service->callback('runtime-test', $payload, $this->userId, '127.0.0.1', 'Payment-Runtime-Test/1.0');

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertStringContainsString('مبلغ', str_value($result['message'] ?? ''));
        $this->assertSame('pending', (string) $this->paymentRow()->status);
        $this->assertSame($this->walletBalanceBefore, $this->walletBalance());
        $this->assertSame(0, $this->gatewayDepositTransactionCount());
        $this->assertSame(0, (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM outbox_events WHERE aggregate_type='payment' AND aggregate_id=?",
            [(string) $this->paymentId]
        ));
    }

    public function test_invalid_nonce_fails_before_gateway_and_persistence(): void
    {
        $payload = $this->validPayload();
        $payload['nonce'] = bin2hex(random_bytes(16));

        $result = $this->service->callback('runtime-test', $payload, $this->userId, '127.0.0.1', 'Payment-Runtime-Test/1.0');

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertStringContainsString('نشانه', str_value($result['message'] ?? ''));
        $this->assertSame('pending', (string) $this->paymentRow()->status);
        $this->assertSame($this->walletBalanceBefore, $this->walletBalance());
        $this->assertSame(0, $this->gatewayDepositTransactionCount());
    }

    public function test_cancelled_callback_marks_payment_cancelled_without_credit(): void
    {
        $payload = $this->validPayload();
        $payload['status'] = 'cancel';

        $result = $this->service->callback('runtime-test', $payload, $this->userId, '127.0.0.1', 'Payment-Runtime-Test/1.0');

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertStringContainsString('لغو', str_value($result['message'] ?? ''));
        $this->assertSame('cancelled', (string) $this->paymentRow()->status);
        $this->assertSame($this->walletBalanceBefore, $this->walletBalance());
        $this->assertSame(0, $this->gatewayDepositTransactionCount());
    }

    public function test_two_concurrent_callbacks_credit_wallet_exactly_once(): void
    {
        $worker = base_path('tests/Support/payment_callback_worker.php');
        $resultFiles = [$this->tempFile('payment-cb-a-'), $this->tempFile('payment-cb-b-')];
        $logFiles = [$this->tempFile('payment-cb-log-a-'), $this->tempFile('payment-cb-log-b-')];

        $processes = [];
        foreach ([0, 1] as $index) {
            $command = [PHP_BINARY, $worker, $this->authority, $this->nonce, (string) $this->userId, $resultFiles[$index]];
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFiles[$index], 'a'],
                2 => ['file', $logFiles[$index], 'a'],
            ], $pipes, base_path());
            if (!is_resource($process)) $this->fail('Unable to start callback worker.');
            $processes[$index] = $process;
        }

        $exitCodes = [];
        foreach ($processes as $process) {
            $exitCodes[] = proc_close($process);
        }

        try {
            sort($exitCodes);
            $this->assertContains($exitCodes, [[0, 0], [0, 1]], implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), $logFiles)));
            $workerResults = array_map(
                fn(string $file): array => $this->decodeArray(str_value(file_get_contents($file))),
                $resultFiles
            );
            $businessSuccesses = array_values(array_filter(
                $workerResults,
                fn(array $worker): bool => (bool) ($worker['ok'] ?? false) && (bool) ($this->requireArray($worker['result'] ?? null)['success'] ?? false)
            ));
            $this->assertNotEmpty($businessSuccesses, (json_encode($workerResults) ?: ''));

            // The loser may observe one of three valid fail-closed/idempotent states:
            // lock conflict (409), in-progress response, or the cached completed result.
            foreach ($workerResults as $workerResult) {
                if (!(bool) ($workerResult['ok'] ?? false)) {
                    $state = 'lock_conflict';
                    $detailsAreValid = int_value($workerResult['code'] ?? 0) === 409
                        && str_contains(str_value($workerResult['message'] ?? ''), 'Concurrency lock failed');
                } elseif (!(bool) ($this->requireArray($workerResult['result'] ?? null)['success'] ?? false)) {
                    $state = 'in_progress';
                    $detailsAreValid = str_contains(str_value($this->requireArray($workerResult['result'] ?? null)['message'] ?? ''), 'پردازش');
                } else {
                    $state = 'cached_or_primary_success';
                    $detailsAreValid = ($this->requireArray($workerResult['result'] ?? null)['ref_id'] ?? null) === 'RUNTIME-REF-001';
                }
                $this->assertContains($state, ['lock_conflict', 'in_progress', 'cached_or_primary_success']);
                $this->assertTrue($detailsAreValid, (json_encode($workerResult) ?: ''));
            }

            $expectedBalance = bcadd($this->walletBalanceBefore, $this->amount, 8);
            $this->assertSame('completed', (string) $this->paymentRow()->status);
            $this->assertSame($expectedBalance, $this->walletBalance());
            $this->assertSame(1, $this->gatewayDepositTransactionCount());
            $this->assertSame(2, (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM outbox_events WHERE aggregate_type='payment' AND aggregate_id=?",
                [(string) $this->paymentId]
            ));
        } finally {
            foreach (array_merge($resultFiles, $logFiles) as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if (!is_string($file)) $this->fail('Unable to allocate payment worker file.');
        return $file;
    }

    /** @return array<int|string,mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<int|string,mixed> */
    private function requireArray(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /** @return array<string,string> */
    private function validPayload(): array
    {
        return [
            'authority' => $this->authority,
            'nonce' => $this->nonce,
            'amount' => $this->amount,
            'status' => 'ok',
            'signature' => 'deterministic-signature',
        ];
    }

    private function paymentRow(): \stdClass
    {
        $row = $this->db->fetch('SELECT * FROM payment_logs WHERE id = ?', [$this->paymentId]);
        $this->assertInstanceOf(\stdClass::class, $row);
        return $row;
    }

    private function walletBalance(): string
    {
        return str_value($this->db->fetchColumn('SELECT balance_irt FROM wallets WHERE user_id = ?', [$this->userId]));
    }

    private function gatewayDepositTransactionCount(): int
    {
        return int_value($this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE user_id=? AND type='gateway_deposit' AND gateway_transaction_id=?",
            [$this->userId, $this->authority]
        ));
    }
}

