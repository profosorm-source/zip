<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\InvestmentWithdrawal;
use App\Models\NotificationPreference;
use App\Models\PaymentGateway;
use App\Models\SecurityLog;
use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class ModelBoundaryContractsRuntimeTest extends TestCase
{
    private Database $db;
    private int $preferenceUserId;
    private string $gatewayName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Application::getInstance()->container->make(Database::class);
        $suffix = random_int(100000, 999999);
        $this->preferenceUserId = 2000000000 + $suffix;
        $this->gatewayName = 'phase20-boundary-' . $suffix;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->db->execute(
                'DELETE FROM notification_preferences_v2 WHERE user_id = ?',
                [$this->preferenceUserId]
            );
            $this->db->execute(
                'DELETE FROM payment_gateways WHERE name = ?',
                [$this->gatewayName]
            );
        }
        parent::tearDown();
    }

    public function test_notification_preferences_are_persisted_and_reselected_idempotently(): void
    {
        $model = new NotificationPreference($this->db);

        $first = get_object_vars($model->getOrCreate($this->preferenceUserId));
        $second = get_object_vars($model->getOrCreate($this->preferenceUserId));

        $this->assertSame($this->preferenceUserId, int_value($first['user_id'] ?? 0));
        $this->assertSame(1, int_value($first['in_app_enabled'] ?? 0));
        $this->assertSame(int_value($first['user_id'] ?? 0), int_value($second['user_id'] ?? -1));
        $this->assertSame(1, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM notification_preferences_v2 WHERE user_id = ?',
            [$this->preferenceUserId]
        ));
    }

    public function test_payment_gateway_merges_valid_object_config(): void
    {
        $this->db->execute(
            'INSERT INTO payment_gateways (name, slug, config, is_active) VALUES (?, ?, ?, 1)',
            [$this->gatewayName, $this->gatewayName, '{"merchant":"m-42","timeout":15}']
        );

        $gateway = (new PaymentGateway($this->db))->getActiveGateway($this->gatewayName);

        $this->assertNotNull($gateway);
        $this->assertSame('m-42', $gateway->merchant);
        $this->assertSame(15, $gateway->timeout);
    }

    public function test_payment_gateway_rejects_structurally_invalid_scalar_config(): void
    {
        $this->db->execute(
            'INSERT INTO payment_gateways (name, slug, config, is_active) VALUES (?, ?, ?, 1)',
            [$this->gatewayName, $this->gatewayName, '"not-an-object"']
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('must decode to a JSON object');
        (new PaymentGateway($this->db))->getActiveGateway($this->gatewayName);
    }

    public function test_investment_withdrawal_lock_fails_without_caller_owned_transaction(): void
    {
        $model = new InvestmentWithdrawal($this->db);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an active transaction');
        $model->findForUpdate(987654321);
    }

    public function test_investment_withdrawal_lock_keeps_caller_transaction_open(): void
    {
        $model = new InvestmentWithdrawal($this->db);
        $this->db->beginTransaction();

        $this->assertNull($model->findForUpdate(987654321));
        $this->assertTrue($this->db->inTransaction());
    }

    public function test_security_log_rejects_non_string_search_filter_at_boundary(): void
    {
        $model = new SecurityLog($this->db);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('search filter must be a string');
        $model->getPaginated(['search' => ['unexpected']], 1, 20);
    }
}
