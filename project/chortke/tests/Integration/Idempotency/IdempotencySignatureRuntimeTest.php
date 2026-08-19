<?php

declare(strict_types=1);

namespace Tests\Integration\Idempotency;

use Core\Application;
use Core\Cache;
use Core\Database;
use Core\IdempotencyKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdempotencySignatureRuntimeTest extends TestCase
{
    private Database $db;
    private Cache $cache;
    private IdempotencyKey $idempotency;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Application::getInstance()->container;
        $this->db = $container->make(Database::class);
        $this->cache = $container->make(Cache::class);
        $this->idempotency = new IdempotencyKey($this->db, $this->cache);
        $this->key = 'phase20-signature-' . bin2hex(random_bytes(10));
        $_SERVER['REQUEST_URI'] = '/phase20/idempotency';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->expectOutputRegex('/.*/');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->db->execute('DELETE FROM idempotency_keys WHERE `key` = ?', [$this->key]);
        }
        if (isset($this->cache)) {
            $this->cache->forget('idempotency_lock:1:' . hash('sha256', $this->key));
        }
        parent::tearDown();
    }

    public function test_corrupted_stored_signature_fails_closed_and_rolls_back(): void
    {
        $first = $this->idempotency->check($this->key, 1, 'phase20', ['amount' => '10.00']);
        $this->assertFalse($first['is_duplicate']);
        $this->db->execute(
            'UPDATE idempotency_keys SET request_data = ? WHERE `key` = ?',
            ['{"uri":[],"method":"POST","data":[]}', $this->key]
        );

        try {
            $this->idempotency->check($this->key, 1, 'phase20', ['amount' => '10.00']);
            $this->fail('Malformed persisted signature schema must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid schema', $exception->getMessage());
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(1, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM idempotency_keys WHERE `key` = ?',
            [$this->key]
        ));
    }

    public function test_unencodable_request_signature_is_never_persisted(): void
    {
        $resource = fopen('php://memory', 'rb');
        $this->assertIsResource($resource);

        try {
            $this->idempotency->check($this->key, 1, 'phase20', ['stream' => $resource]);
            $this->fail('Unencodable request data must fail before persistence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Idempotency check failed', $exception->getMessage());
        } finally {
            fclose($resource);
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM idempotency_keys WHERE `key` = ?',
            [$this->key]
        ));
    }
}
