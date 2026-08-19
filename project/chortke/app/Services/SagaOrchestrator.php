<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\SagaExecution;
use Core\Container;

/**
 * Saga Orchestrator - نسخه نهایی و فوق‌بهینه (با الگوی Savepoint و مهار بن‌بست تراکنش‌های تو در تو)
 * مدیریت تراکنش‌های توزیع‌شده با قابلیت Recovery خودکار و سازگاری کامل با تراکنش‌های بیرونی.
 */
class SagaOrchestrator
{


    /** @var array<int, array{name: string, execute: callable, compensate: callable|null}> */
    private array $steps = [];
    /** @var array<int, array{name: string, compensate: callable|null, result: mixed}> */
    private array $executedSteps = [];
    private ?string $sagaName = null;
    /** @var array<string, mixed> */
    private array $payload = [];
    private ?string $executionId = null;

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {}

    /** @param array<string, mixed> $payload */
    public function setSaga(string $name, array $payload = []): self
    {
        $this->sagaName = $name;
        $this->payload = $payload;
        $this->executionId = bin2hex(random_bytes(16));
        $this->steps = [];
        
        $this->logger->info("saga.init", ['name' => $name, 'execution_id' => $this->executionId]);

        $this->db->prepare(
            "INSERT INTO saga_executions (id, saga_name, status, payload, executed_steps, created_at, updated_at) 
             VALUES (?, ?, 'started', ?, '[]', NOW(), NOW())"
        )->execute([$this->executionId, $name, json_encode($payload)]);

        return $this;
    }

    public function addStep(string $name, callable $execute, ?callable $compensate = null): self
    {
        $this->steps[] = ['name' => $name, 'execute' => $execute, 'compensate' => $compensate];
        return $this;
    }

    public function execute(): mixed
    {
        $context = $this->payload;
        $context['saga_execution_id'] = $this->executionId;
        $this->executedSteps = [];

        try {
            foreach ($this->steps as $step) {
                $this->logger->info("saga.step.run", ['saga' => $this->sagaName, 'step' => $step['name']]);
                
                $result = call_user_func($step['execute'], $context);
                
                if (is_array($result)) {
                    $context = array_merge($context, $result);
                }

                $this->executedSteps[] = [
                    'name' => $step['name'],
                    'compensate' => $step['compensate'],
                    'result' => $result
                ];

                $this->saveState();
            }

            $this->updateStatus('completed');
            return $context;

        } catch (\Throwable $e) {
            $this->logger->error("saga.error", ['saga' => $this->sagaName, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'    => 'saga.execute',
                'saga_name'    => $this->sagaName,
                'execution_id' => $this->executionId,
                'failed_step'  => end($this->executedSteps)['name'] ?? 'unknown',
            ]);
            $this->compensate($e, $context);
            $this->updateStatus('compensated');

            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException(
                'Saga transaction failed and compensated: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * ─── رفع باگ بن‌بست تراکنش‌های تو در تو (Nested Transaction Deadlock Fix) ────
     * بررسی هوشمندانه وضعیت تراکنش بیرونی (inTransaction):
     * اگر کنترلر بیرونی قبلاً تراکنش باز کرده باشد، به‌جای شلیک مجدد beginTransaction (که ارور می‌دهد)،
     * از مکانیزم SAVEPOINT استفاده می‌شود تا زیرتراکنش جبرانی بدون فروپاشی تراکنش اصلی مهار شود.
     */
    /** @param array<string, mixed> $context */
    private function compensate(\Throwable $error, array $context): void
    {
        foreach (array_reverse($this->executedSteps) as $step) {
            if ($step['compensate']) {
                $stepCleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', $step['name']);
                $savepointName = "saga_comp_" . substr($stepCleanName ?? 'step', 0, 20);
                $hasOuterTransaction = $this->db->inTransaction();

                try {
                    $this->logger->warning("saga.compensate", ['step' => $step['name']]);
                    
                    if (!$hasOuterTransaction) {
                        $this->db->beginTransaction();
                    } else {
                        $this->db->prepare("SAVEPOINT {$savepointName}")->execute();
                    }

                    $result = call_user_func($step['compensate'], $error, $step['result'] ?? $context);
                    
                    if (!$hasOuterTransaction) {
                        $this->db->commit();
                    }
                    
                } catch (\Throwable $ce) {
                    if (!$hasOuterTransaction && $this->db->inTransaction()) {
                        $this->db->rollback();
                    } elseif ($hasOuterTransaction) {
                        $this->db->prepare("ROLLBACK TO SAVEPOINT {$savepointName}")->execute();
                    }
                    $this->logger->critical("saga.compensate.failed", ['step' => $step['name'], 'error' => $ce->getMessage()]);
                    \App\Services\Sentry\SentryExceptionHandler::captureException($ce, null, [
                        'operation'    => 'saga.compensate.failed',
                        'saga_name'    => $this->sagaName,
                        'execution_id' => $this->executionId,
                        'step'         => $step['name'],
                    ]);
                }
            }
        }
    }

    private function saveState(): void
    {
        // 🛡️ ARCHITECTURE FIX (Saga Recovery Guard): استخراج کامل متادیتای مراحل
        // جلوگیری از مفقود شدن متادیتا جهت عملکرد صحیح SagaRecoveryWorker در جبران‌سازی بازیابی
        $state = array_map(function($s) {
            $comp = $s['compensate'] ?? null;
            $type = 'unknown';
            $class = null;

            if (is_array($comp) && isset($comp[0])) {
                $type = 'class';
                $class = is_object($comp[0]) ? get_class($comp[0]) : $comp[0];
            } elseif (is_string($comp) && str_contains($comp, '::')) {
                $type = 'class';
                $class = explode('::', $comp)[0];
            } elseif ($comp instanceof \Closure) {
                $type = 'closure';
            }

            return [
                'name' => $s['name'],
                'type' => $type,
                'class' => $class,
                'result' => $s['result'] ?? null
            ];
        }, $this->executedSteps);

        $this->db->prepare("UPDATE saga_executions SET executed_steps = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([json_encode($state), $this->executionId]);
    }

    private function updateStatus(string $status): void
    {
        $this->db->prepare("UPDATE saga_executions SET status = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([$status, $this->executionId]);
    }
}
