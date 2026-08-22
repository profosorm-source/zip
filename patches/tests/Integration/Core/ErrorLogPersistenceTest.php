<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use PHPUnit\Framework\TestCase;

/**
 * BUG-CATCHER (observability): ExceptionHandler خطاها را در جدول error_logs
 * ثبت می‌کند، اما ستون‌های `level` و `context` در اسکیمای واقعی این جدول
 * وجود ندارند (مهاجرت‌های 2026_07_16_0016 و 0017 نسخه‌ی exception_class را
 * توسعه داده‌اند، نه نسخه‌ی level/context را).
 *
 * چون هر دو نقطه‌ی درج داخل try/catch خاموش هستند، PDOException بلعیده
 * می‌شد و هیچ ردی از خطاها در دیتابیس باقی نمی‌ماند — یعنی داشبورد ادمین
 * همیشه خالی بود بدون آنکه کسی متوجه شود.
 */
class ErrorLogPersistenceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = \Core\Database::getInstance()->getPdo();
    }

    private function columns(): array
    {
        return $this->pdo->query('SHOW COLUMNS FROM error_logs')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function test_exception_handler_actually_persists_a_row(): void
    {
        $marker = 'probe_' . bin2hex(random_bytes(8));

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();

        $method = new \ReflectionMethod(\Core\ExceptionHandler::class, 'logToAdvancedSystem');
        $method->setAccessible(true);

        ob_start();
        try {
            $method->invoke(null, new \RuntimeException($marker), 'TEST_CODE', 'trace-abc');
        } finally {
            ob_end_clean();
        }

        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();

        $this->assertSame(
            $before + 1,
            $after,
            'ExceptionHandler باید دقیقاً یک ردیف در error_logs درج کند؛ درج خاموش شکست خورده است.'
        );

        $row = $this->pdo->prepare('SELECT * FROM error_logs WHERE message = ? LIMIT 1');
        $row->execute([$marker]);
        $found = $row->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($found, 'ردیف درج‌شده باید با همان پیام قابل بازیابی باشد.');
        $this->assertSame(\RuntimeException::class, $found['exception_class']);

        $this->pdo->prepare('DELETE FROM error_logs WHERE message = ?')->execute([$marker]);
    }

    public function test_handler_only_writes_columns_that_exist(): void
    {
        $columns = $this->columns();

        $this->assertContains('exception_class', $columns);
        $this->assertNotContains(
            'level',
            $columns,
            'اسکیمای واقعی ستون level ندارد — درج باید به ستون‌های موجود نگاشت شود.'
        );
        $this->assertNotContains('context', $columns);
    }

    public function test_fatal_error_logging_also_persists(): void
    {
        $marker = 'fatal_probe_' . bin2hex(random_bytes(8));

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();

        $method = new \ReflectionMethod(\Core\ExceptionHandler::class, 'logFatalError');
        $method->setAccessible(true);

        ob_start();
        try {
            $method->invoke(null, [
                'type'    => E_ERROR,
                'message' => $marker,
                'file'    => '/tmp/fake.php',
                'line'    => 42,
            ]);
        } finally {
            ob_end_clean();
        }

        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();

        $this->assertSame(
            $before + 1,
            $after,
            'خطای fatal نیز باید در error_logs ثبت شود.'
        );

        $this->pdo->prepare('DELETE FROM error_logs WHERE message = ?')->execute([$marker]);
    }
}
