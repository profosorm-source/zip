<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use Core\Application;
use Core\Database;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\RuntimeRaceModel;

final class ModelFirstOrCreateConcurrencyRuntimeTest extends TestCase
{
    private Database $db;
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Application::getInstance()->container->make(Database::class);
        $this->db->execute('DROP TABLE IF EXISTS phase20_model_race');
        $this->db->execute(
            'CREATE TABLE phase20_model_race ('
            . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'email VARCHAR(190) NOT NULL,'
            . 'slug VARCHAR(190) NULL,'
            . 'name VARCHAR(190) NULL,'
            . 'updated_at DATETIME NULL,'
            . 'UNIQUE KEY uq_phase20_model_race_email (email)'
            . ') ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->db->execute('DROP TABLE IF EXISTS phase20_model_race');
        }
        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_concurrent_first_or_create_returns_one_committed_winner_to_all_processes(): void
    {
        $workers = 6;
        $token = bin2hex(random_bytes(8));
        $readyDirectory = sys_get_temp_dir() . '/phase20-model-ready-' . $token;
        $releaseFile = sys_get_temp_dir() . '/phase20-model-release-' . $token;
        $resultFile = sys_get_temp_dir() . '/phase20-model-results-' . $token . '.jsonl';
        $workerLog = sys_get_temp_dir() . '/phase20-model-worker-' . $token . '.log';
        mkdir($readyDirectory, 0700, true);
        $this->temporaryPaths = [$readyDirectory, $releaseFile, $resultFile, $workerLog];
        $email = 'phase20-race-' . $token . '@example.test';

        $processes = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $process = proc_open(
                [
                    PHP_BINARY,
                    'tests/Support/model_first_or_create_worker.php',
                    (string)$worker,
                    $readyDirectory,
                    $releaseFile,
                    $resultFile,
                    $email,
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $workerLog, 'a'],
                    2 => ['file', $workerLog, 'a'],
                ],
                $pipes,
                dirname(__DIR__, 3)
            );
            $this->assertIsResource($process);
            $processes[] = $process;
        }

        $deadline = microtime(true) + 20.0;
        while (count(glob($readyDirectory . '/ready-*') ?: []) !== $workers) {
            if (microtime(true) >= $deadline) {
                $this->fail('Workers did not reach the concurrency barrier.');
            }
            usleep(2_000);
        }
        touch($releaseFile);

        foreach ($processes as $process) {
            $this->assertSame(0, proc_close($process));
        }

        $lines = file($resultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount($workers, $lines);
        $results = array_map(
            fn(string $line): array => $this->decodeArray($line),
            $lines
        );
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, (json_encode($result) ?: ''));
        }

        $ids = array_map(static fn(array $result): int => (int)$result['id'], $results);
        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM phase20_model_race WHERE email = ?',
            [$email]
        ));
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_non_unique_constraint_failure_is_not_misclassified_as_race(): void
    {
        $this->expectOutputRegex('/.*/');
        $model = new RuntimeRaceModel($this->db);

        $this->expectException(PDOException::class);
        $model->firstOrCreate(['email' => null], ['name' => 'invalid']);
    }

    public function test_unique_violation_on_unrelated_column_reports_missing_lookup_invariant(): void
    {
        $this->expectOutputRegex('/.*/');
        $this->db->execute(
            'INSERT INTO phase20_model_race (id,email,slug,name) VALUES (999,?,?,?)',
            ['preseed@example.test', 'existing', 'preseed']
        );
        $model = new RuntimeRaceModel($this->db);

        try {
            $model->firstOrCreate(
                ['slug' => 'never-matches'],
                ['id' => 999, 'email' => 'new@example.test', 'name' => 'collision']
            );
            $this->fail('An unrelated UNIQUE violation must not be treated as a race winner.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('UNIQUE violation', $exception->getMessage());
            $this->assertStringContainsString('slug', $exception->getMessage());
        }

        $this->assertFalse($this->db->inTransaction());
    }
    /** @return array<int|string,mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

}
