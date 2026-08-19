<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class ReconcilePaymentsJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\PaymentLog $log;
    private \App\Jobs\Payment\ProcessPaymentCallbackJob $processPaymentCallbackJob;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\PaymentLog $log,
        \App\Jobs\Payment\ProcessPaymentCallbackJob $processPaymentCallbackJob,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->log = $log;
        $this->processPaymentCallbackJob = $processPaymentCallbackJob;
        $this->outbox = $outbox;
}

    /** @return array<string, mixed> */
public function handle(): array
    {
        $results = ['total' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];

        try {
            $stuckPayments = $this->db->query(
                "SELECT * FROM payment_logs 
                 WHERE status = 'pending' 
                   AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY created_at ASC LIMIT 50"
            )->fetchAll(\PDO::FETCH_OBJ) ?: [];

            foreach ($stuckPayments as $pay) {
                $results['total']++;
                
                $responseData = @(array)(json_decode($pay->response_data ?? '{}', true) ?? []) ?: [];
                $retryCount = int_value($responseData['retry_count'] ?? 0);

                if ($retryCount >= 5) {
                    $responseData['error_message'] = 'Max retry attempts reached (skipped)';
                    $this->log->update((int)$pay->id, [
                        'status' => 'failed',
                        'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                    ]);
                    $results['skipped']++;
                    continue;
                }

                $responseData['retry_count'] = $retryCount + 1;
                $responseData['last_retry_at'] = date('Y-m-d H:i:s');

                // M-05 FIX (root cause): read-modify-write on retry_count with no lock meant two
                // reconciler runs (cron overlap / multiple workers) both read the same counter and
                // both re-processed the SAME pending payment. The counter bump is now an atomic
                // compare-and-swap: the row is only claimed if it is still `pending` AND still at
                // the retry_count we observed, so exactly one worker proceeds per attempt.
                $claimed = $this->db->execute(
                    "UPDATE payment_logs SET response_data = ?, updated_at = NOW()
                      WHERE id = ? AND status = 'pending'
                        AND COALESCE(
                              CAST(JSON_UNQUOTE(JSON_EXTRACT(response_data, '$.retry_count')) AS UNSIGNED),
                              0
                            ) = ?",
                    [json_encode($responseData, JSON_UNESCAPED_UNICODE), (int)$pay->id, $retryCount]
                );

                if ($claimed !== 1) {
                    $results['skipped']++;
                    $this->logger->info('payment.reconciliation.claim_skipped', [
                        'payment_id' => (int)$pay->id,
                    ]);
                    continue;
                }

                try {
                    $storedRequestData = @(array)(json_decode($pay->request_data ?? '', true) ?? []) ?: [];
                    $storedNonce = str_value($storedRequestData['callback_nonce'] ?? '');

                    // M-05 FIX (root cause): reconciliation used to FABRICATE a successful gateway
                    // response ('status' => 'OK'). That is gateway data the system never received,
                    // and it made the callback path treat an unknown payment as user-confirmed.
                    // No status is injected any more: the callback pipeline decides purely from the
                    // authoritative server-to-server verifyPayment() inquiry, which is exactly what
                    // reconciliation is supposed to rely on.
                    $res = $this->processPaymentCallbackJob->handle((string)$pay->gateway, [
                        'authority' => (string)$pay->authority,
                        'nonce' => $storedNonce,
                    ], (int)$pay->user_id);

                    if (!empty($res['success'])) {
                        $results['completed']++;
                    } else {
                        $results['failed']++;
                        
                        // If it has now been retried 5 times, mark as failed strictly and alert admin
                        if ($retryCount >= 4) {
                            $responseData['error_message'] = 'Max retry attempts reached';
                            $this->log->update((int)$pay->id, [
                                'status' => 'failed',
                                'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                            ]);
                            
                            $this->outbox?->record('notification', 0, 'admin_notification.requested', [
                                'type' => 'payment_failed_max_retries',
                                'title' => 'خطای بحرانی پرداخت',
                                'body' => "پرداخت شماره {$pay->id} پس از ۵ بار تلاش ناموفق بود. کاربر: {$pay->user_id}، مبلغ: {$pay->amount}",
                                'data' => ['payment_id' => $pay->id, 'user_id' => $pay->user_id, 'amount' => $pay->amount],
                                'priority' => 'high'
                            ]);
                        }
                    }
                } catch (\Throwable $innerEx) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollback();
                    }
                    $results['failed']++;
                    $this->logger->error('payment.reconciliation.inner_failed', [
                        'payment_id' => $pay->id,
                        'error' => $innerEx->getMessage()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('payment.reconciliation.failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }
}
