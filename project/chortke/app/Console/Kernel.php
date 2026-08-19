<?php
namespace App\Console;
use Core\Scheduler;
use Core\Container;
use App\Services\EmailService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\User\UserLevelService;
use App\Services\Lottery\LotteryService;
use App\Services\BannerService;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\Notification\NotificationService;
use App\Services\AdNotificationDispatcher;
use App\Services\Ads\AdsBudgetSettlementService;
use App\Models\Notification as NotificationModel;
use App\Models\Advertisement;
use Core\Cache;
use Core\Database;
use Core\Queue;
use App\Services\Gamification\TrustService;

class Kernel {
    public static function schedule(Scheduler $scheduler): void {
        $container = app();
/**
 * ─────────────────────────────────────────
 * هر دقیقه
 * ─────────────────────────────────────────
 */

// پردازش صف ایمیل‌ها
$scheduler->everyMinute(function () use ($container) {
    $service = $container->make(EmailService::class);
    $batchSize = int_value(feature_config('cron_email_batch_size', 'rollout_percentage', 20));
    $result  = $service->processQueue($batchSize);
    return [
        'sent'   => $result['sent']   ?? 0,
        'failed' => $result['failed'] ?? 0,
    ];
}, 'email_queue');

// Finding #10 & #11 Fix: Automatic recovery of email file fallbacks
$scheduler->everyMinutes(15, function () use ($container) {
    $emailStore = $container->make(\App\Services\EmailDeliveryStore::class);
    $recovered = $emailStore->recoverFileFallbacks();
    return ['recovered_file_fallbacks' => $recovered];
}, 'email_fallback_recovery');

// پردازش صف عمومی سیستم
$scheduler->everyMinute(function () use ($container) {
    $maxJobsToProcess = max(1, min(100, int_value(feature_config('cron_queue_jobs_limit', 'rollout_percentage', 10))));
    
    $command = $container->make(\App\Commands\QueueWorkCommand::class);
    $result = $command->run(['cli.php', 'queue:work', "--limit={$maxJobsToProcess}"]);
    
    return ['processed_jobs' => $result['processed_jobs'] ?? 0];
}, 'system_queue_processor');

// Poison Message Handler: بازپخش هوشمند خطاهای موقت
$scheduler->everyMinutes(5, function () use ($container) {
    $queue = $container->make(Queue::class);
    $limit = max(1, min(100, int_value(feature_config('cron_dlq_retry_limit', 'rollout_percentage', 50))));
    
    $stats = $queue->retryEligibleFailedJobs(null, $limit, false);
    
    return [
        'requeued_poison_messages' => $stats['requeued'] ?? 0,
        'failed_retries' => $stats['errors'] ?? 0
    ];
}, 'poison_message_smart_retry');

// 🛡️ H-3 Fix: دیگر نیازی به flush نیست — همه امتیازات مستقیم به DB نوشته می‌شوند
// (تبدیل به no-op شده و فقط برای backward compatibility نگه داشته شده)
$scheduler->everyMinute(function () {
    return [
        'flushed_scores' => 0
    ];
}, 'flush_score_events_buffer');

// تأیید خودکار واریزهای کریپتو در انتظار
$scheduler->everyMinute(function () use ($container) {
    $db      = Database::getInstance();
    $job = $container->make(\App\Jobs\VerifyCryptoDepositJob::class);

    // واریزهای pending که هنوز تأیید نشده‌اند
    // مشکل #10: cast + validate — هرگز مستقیم در SQL interpolate نمی‌شوند
    $hours = max(1, min(720, int_value(feature_config('cron_verification_hours', 'rollout_percentage', 12))));
    $limit = max(1, min(500, int_value(feature_config('cron_verification_limit', 'rollout_percentage', 10))));

    $pending = $db->fetchAll(
        "SELECT id FROM crypto_deposits
         WHERE verification_status = 'pending'
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY created_at ASC
         LIMIT ?",
        [$hours, $limit]
    );

    $verified = 0;
    foreach ($pending as $row) {
        $id     = is_array($row) ? (int)$row['id'] : (int)$row->id;
        $result = $job->handle($id);
        if (($result['auto'] ?? false) === true) {
            $verified++;
        }
    }

    return ['pending_checked' => count($pending), 'verified' => $verified];
}, 'crypto_verify');

// تایید خودکار پرداخت‌های معلق درگاه‌های آنلاین
$scheduler->everyMinutes(10, function () use ($container) {
    $paymentService = $container->make(\App\Services\Payment\PaymentService::class);
    $pending = $paymentService->getPendingVerificationPayments();
    
    $completed = 0;
    $failed = 0;
    
    foreach ($pending as $payment) {
        $createdAt = strtotime($payment->created_at);
        $age = time() - $createdAt;
        
        // فقط برای تراکنش‌های کمتر از ۲۴ ساعت و بیشتر از ۵ دقیقه (جهت فرصت دادن به پردازش‌های آنی و عادی درگاه)
        if ($age > 300 && $age < 86400) {
            try {
                // تایید خودکار با شناسه سیستم (0)
                $result = $paymentService->manuallyVerifyPayment((int)$payment->id, 0);
                if (!empty($result['success'])) {
                    $completed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                logger()->error('payment.auto_retry_verification_failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }
    }
    
    return [
        'total_pending' => count($pending),
        'auto_completed' => $completed,
        'auto_failed' => $failed
    ];
}, 'payment_pending_verification_retry');

// پردازش خودکار قوانین هشدار (Alert Engine)
$scheduler->everyMinute(function () use ($container) {
    $dispatcher = $container->make(\App\Services\Sentry\Alerting\AlertDispatcher::class);
    $triggered = $dispatcher->processRules();
    return ['triggered_rules' => $triggered];
}, 'alert_rule_engine');

// بررسی وضعیت دیسک و حافظه (مانیتورینگ پیشگیرانه)
$scheduler->everyMinutes(5, function () use ($container) {
    $monitoring = $container->make(\App\Services\AdminDashboard\SystemMonitoringService::class);
    $monitoring->checkAndAlert();
    return ['checked' => true];
}, 'system_monitoring_alert');


// پاک‌سازی کش منقضی‌شده
$scheduler->everyMinutes(int_value(feature_config('cron_scheduler_interval', 'rollout_percentage', 5)), function () {
    $cleaned = Cache::getInstance()->cleanup();
    return ['cleaned_files' => $cleaned];
}, 'cache_cleanup');

// 📢 ارسال اعلان‌های پس‌زمینه کمپین‌های فعال (Ad Notifications)
// اجرا در فواصل کوتاه برای ارسال موازی و کم‌بار بدون سنگین کردن سرور
$scheduler->everyMinutes(int_value(feature_config('cron_ad_push_interval', 'rollout_percentage', 3)), function () use ($container) {
    $dispatcher = $container->make(AdNotificationDispatcher::class);
    $result = $dispatcher->processAdNotifications();
    $adsProcessed = int_value($result['ads_processed'] ?? 0);
    $totalSent = int_value($result['total_sent'] ?? 0);
    if ($totalSent > 0) {
        echo "[AdPush] Processed {$adsProcessed} ads, sent {$totalSent} push packets\n";
    }
    return $result;
}, 'ad_notification_push');

// H-06 Fix: تخلیه بافر بازدید بنرها از Redis به دیتابیس هر ۵ دقیقه
$scheduler->everyMinutes(5, function () use ($container) {
    $service = $container->make(\App\Services\BannerService::class);
    $count   = $service->flushImpressionsBuffer();
    return ['flushed_banners' => $count];
}, 'flush_banner_impressions');

/**
 * ─────────────────────────────────────────
 * هر ساعت (دقیقه ۰)
 * ─────────────────────────────────────────
 */

// reconcile چرخه عمر تبلیغات unified ads؛ جایگزین query قدیمی روی جدول advertisements
$scheduler->hourly(function () use ($container) {
    $service = $container->make(AdsBudgetSettlementService::class);
    return $service->reconcileLifecycle(200);
}, 'ads_lifecycle_reconcile');

// غیرفعال کردن بنرهای منقضی‌شده
$scheduler->hourly(function () use ($container) {
    $service = $container->make(BannerService::class);
    $count   = $service->deactivateExpiredBanners();
    return ['deactivated_banners' => $count];
}, 'expire_banners');

// پاک‌سازی ساعتی فایل‌های جلسه‌ی فایلی در صورت fallback از Redis
$scheduler->hourly(function () {
    try {
        $handler = new \Core\RedisSessionHandler();
        $maxLifetime = max(60, min(86400, int_value(config('session.lifetime', 7200))));
        $deleted = $handler->gc($maxLifetime);
        return [
            'session_gc_deleted' => $deleted === false ? 0 : $deleted,
            'session_driver' => $handler->driver(),
        ];
    } catch (\Throwable $e) {
        logger()->error('cron.session_file_gc.failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'session_file_gc');

// غیرفعال کردن فیچر فلگ‌های منقضی شده و cleanup metrics
$scheduler->hourly(function () use ($container) {
    try {
        $db = Database::getInstance();
        $model = $container->make(\App\Models\FeatureFlag::class);

        $affected = (int) $db->execute(
            "UPDATE feature_flags
             SET enabled = 0, updated_at = NOW()
             WHERE enabled = 1
               AND enabled_until IS NOT NULL
               AND enabled_until < NOW()"
        );

        $metricDays = max(7, min(365, int_value(feature_config('feature_flag_metrics_retention_days', 'rollout_percentage', 30))));
        $model->cleanupMetrics($metricDays);

        return [
            'expired_feature_flags_disabled' => $affected,
            'feature_flag_metrics_retention_days' => $metricDays,
        ];
    } catch (\Throwable $e) {
        logger()->error('cron.expired_feature_flag_cleanup.failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'expired_feature_flag_cleanup');

// پاکسازی کش جستجو/تگ‌های سالمند: حذف index فایل خراب در File mode و orphan members در Redis
$scheduler->hourly(function () {
    $cache = Cache::getInstance();
    $driver = $cache->driver();
    $cleaned = 0;

    if ($driver === 'redis') {
        $redis = $cache->redis();
        if ($redis instanceof \Redis) {
            $iterator = null;
            $pattern = $cache->redisKey('tag:*');

            while (false !== ($setKeys = $redis->scan($iterator, $pattern, 100))) {
                foreach ($setKeys as $setKey) {
                    $membersResult = $redis->sMembers($setKey);
                    $members = is_array($membersResult) ? $membersResult : [];
                    if (empty($members)) {
                        $redis->del($setKey);
                        continue;
                    }

                    $validMembers = [];
                    foreach ($members as $member) {
                        if ($redis->exists($member)) {
                            $validMembers[] = $member;
                        }
                    }

                    if (count($validMembers) !== count($members)) {
                        $redis->del($setKey);
                        if (!empty($validMembers)) {
                            $redis->sAdd($setKey, ...$validMembers);
                        }
                        $cleaned += count($members) - count($validMembers);
                    }
                }
            }
        }
    } else {
        $tagsDir = base_path('storage/cache/tags/');
        foreach (glob($tagsDir . '*.json') ?: [] as $indexFile) {
            $content = @file_get_contents($indexFile);
            $decodedContent = $content !== false ? json_decode($content, true) : null;
            $keys = is_array($decodedContent) ? $decodedContent : [];
            $valid = [];

            foreach ($keys as $key) {
                if (is_string($key) && $cache->has($key)) {
                    $valid[] = $key;
                }
            }

            if (empty($valid)) {
                @unlink($indexFile);
                $cleaned += count($keys);
            } elseif (count($valid) !== count($keys)) {
                file_put_contents($indexFile, json_encode(array_values($valid)));
                $cleaned += count($keys) - count($valid);
            }
        }
    }

    return [
        'stale_search_cache_cleaned' => $cleaned,
        'cache_driver' => $driver,
    ];
}, 'stale_search_cache_cleanup');

// ✅ **ممیزی ساعتی تراکنش‌ها و دفاتر کل** 🔄
// بررسی و مانیتورینگ سلامت سیستم مالی (فقط گزارش مغایرت)
$scheduler->hourly(function () {
    try {
        $db = Database::getInstance();
        
        // ⚠️ حذف منطق ناامن تایید خودکار تراکنش‌های یتیم که پیش‌تر اینجا بود و یک حفره مالی محسوب می‌شد.
        // ممیزی از این پس فقط به صورت پسیو (تحلیلی) ناسازگاری‌ها را به لاگر اطلاع می‌دهد.
        
        // بررسی ناسازگاری‌های ledger (debit vs credit)
        // 🔐 RECON FIX (out-of-batch): group by (user_id, currency). Summing amount across
        // currencies in one bucket mixed IRT and USDT into a meaningless total that could
        // both hide a real per-currency mismatch and fabricate a false one. Reconcile each
        // currency independently.
        $mismatches = $db->fetchAll(
            "SELECT user_id, currency, SUM(amount) as balance
             FROM ledger_entries
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
             GROUP BY user_id, currency
             HAVING balance IS NOT NULL"
        );
        
        $warnings = 0;
        foreach ($mismatches as $mismatch) {
            // 🔐 float→decimal FIX: compare the ledger balance with exact BCMath decimal math.
            // The DECIMAL SUM(amount) arrives as a string; casting to float and testing
            // !== 0.0 could both miss a real mismatch and raise a false alarm from binary
            // rounding. bccomp yields an exact zero / non-zero verdict at 8-dp precision.
            $balanceStr = (string)($mismatch->balance ?? '0');
            if (is_numeric($balanceStr) && bccomp($balanceStr, '0', 8) !== 0) {
                $warnings++;
                logger()->warning('reconciliation.ledger_mismatch', [
                    'user_id' => $mismatch->user_id,
                    'currency' => $mismatch->currency ?? 'irt',
                    'balance_mismatch' => $mismatch->balance,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        return [
            'ledger_mismatches_detected' => $warnings,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } catch (\Throwable $e) {
        logger()->error('reconciliation.hourly_audit.failed', [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'hourly_reconciliation_audit');

// انقضای نشست‌های قدیمی کاربران (بیش از ۳۰ روز)
$scheduler->hourly(function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM user_sessions
         WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_sessions' => $affected];
}, 'cleanup_sessions');

// MED-04 Fix: پاک‌سازی توکن‌های بازیابی رمز عبور منقضی شده (بیش از یک ساعت)
$scheduler->hourly(function () use ($container) {
    $model = $container->make(\App\Models\SecurityModel::class);
    $count = $model->deleteExpiredPasswordResets(3600);
    return ['deleted_tokens' => $count];
}, 'cleanup_password_resets');

// چرخش لاگ‌های اپلیکیشن (جلوگیری از حجیم شدن فایل‌ها)
$scheduler->hourly(function () use ($container) {
    $logService = $container->make(\App\Services\LogService::class);
    
    // استفاده از متد بازتابی یا فراخوانی مستقیم در صورتی که عمومی شود
    // اما در اینجا می‌توانیم cleanup را با 0 روز صدا بزنیم که هم لاگ‌های دیتابیس را پاک نکند، هم فایل‌ها را بچرخاند.
    // یا اینکه از یک Job یا تابع عمومی برای Rotate استفاده کنیم.
    $logService->cleanup(90); // این هم rotate می‌کند و هم قدیمی‌ها را پاک می‌کند
    return ['status' => 'rotated'];
}, 'rotate_logs');

// پاک‌سازی پیام‌های realtime منقضی‌شده
$scheduler->hourly(function () use ($container) {
    $service = $container->make(\App\Services\WebSocketService::class);
    $deleted = $service->cleanupExpiredMessages();
    $processed = $service->processAllDelayedMessages();
    return ['deleted_messages' => $deleted, 'processed_delayed' => $processed];
}, 'websocket_cleanup');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۲:۰۰
 * ─────────────────────────────────────────
 */

// بررسی سطح کاربران (downgrade/upgrade/expire)
$scheduler->daily('02:00', function () use ($container) {
    $service = $container->make(UserLevelService::class);

    $downgrades = $service->checkDowngrades();
    $expired    = $service->checkExpiredPurchases();

    return [
        'downgraded' => count($downgrades),
        'expired'    => $expired,
    ];
}, 'user_levels');

// ==============================
// Retention: Activity/System/Security Logs (Weekly - Sunday)
// ==============================
$scheduler->daily('02:30', function () use ($container) {
    if ((int) date('w') !== 0) {
        return ['skipped' => 'cleanup_logs weekly (sunday only)'];
    }

    $logService = $container->make(\App\Services\LogService::class);
    $days = int_value(feature_config('cron_cleanup_days', 'rollout_percentage', 30));

    return [
        'log_cleanup' => $logService->cleanup($days),
    ];
}, 'cleanup_logs');


// ==============================
// Financial Export Backup (Daily)
// ==============================
$scheduler->daily('04:00', function () {
    $db = Database::getInstance();
    $exportDir = base_path('storage/exports/financial/');
    if (!is_dir($exportDir)) {
        @mkdir($exportDir, 0755, true);
    }

    $windowStart = date('Y-m-d 00:00:00', (strtotime('yesterday') ?: time()));
    $windowEnd = date('Y-m-d 23:59:59', (strtotime('yesterday') ?: time()));
    $timeTag = date('Ymd_His');

    $files = [];
    $written = 0;

    $writeCsv = function (\PDOStatement $stmt, string $filePath) use (&$written) {
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            return 0;
        }

        $first = true;
        $count = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($first) {
                fputcsv($handle, array_keys((array)$row));
                $first = false;
            }
            $csvValues = [];
            foreach (array_values((array)$row) as $value) {
                $csvValues[] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            fputcsv($handle, $csvValues);
            $count++;
        }

        fclose($handle);
        $written += $count;
        return $count;
    };

    $transactionsFile = $exportDir . 'transactions_' . $timeTag . '.csv';
    $ledgerFile = $exportDir . 'ledger_entries_' . $timeTag . '.csv';

    // ✅ LIMIT اضافه شد — streaming با while+fetch ایمن است
    // اما اگر یک روز بیش از 100K تراکنش داشته باشیم باید pagination اضافه شود
    $txnStmt = $db->query(
        "SELECT * FROM transactions
         WHERE created_at BETWEEN ? AND ?
         ORDER BY created_at ASC
         LIMIT 100000",
        [$windowStart, $windowEnd]
    );
    $ledgerStmt = $db->query(
        "SELECT * FROM ledger_entries
         WHERE created_at BETWEEN ? AND ?
         ORDER BY created_at ASC
         LIMIT 100000",
        [$windowStart, $windowEnd]
    );

    $txCount = $writeCsv($txnStmt, $transactionsFile);
    $ledgerCount = $writeCsv($ledgerStmt, $ledgerFile);

    if ($txCount > 0) {
        $files[] = $transactionsFile;
    }
    if ($ledgerCount > 0) {
        $files[] = $ledgerFile;
    }

    return [
        'transactions_exported' => $txCount,
        'ledger_entries_exported' => $ledgerCount,
        'files' => $files,
        'export_window' => date('Y-m-d', (strtotime('yesterday') ?: time())),
    ];
}, 'financial_export_backup');


// ==============================
// Retention: Audit Trail Archive (Check daily, run every 30 days)
// ==============================
$scheduler->daily('02:40', function () use ($container) {
    $archiveDir = base_path('storage/audit-archives');
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0755, true);
    }

    $stateFile = $archiveDir . '/.last_archive_at';
    $now = time();

    if (file_exists($stateFile)) {
        $last = (int) trim((string) file_get_contents($stateFile));
        if ($last > 0 && ($now - $last) < (30 * 86400)) {
            return ['skipped' => 'archive_audit_trail every 30 days'];
        }
    }

    $audit = $container->make(\App\Services\AuditTrail::class);
    $result = $audit->archiveOlderThan(30, 2000);

    if (!empty($result['file'])) {
        file_put_contents($stateFile, (string) $now);
    }

    return $result;
}, 'archive_audit_trail');


// ==============================
// Retention: Sentry-like Tables (Weekly - Sunday, chunked)
// ==============================
$scheduler->daily('02:50', function () {
    if ((int) date('w') !== 0) {
        return ['skipped' => 'cleanup_sentry weekly (sunday only)'];
    }

    $db = \Core\Database::getInstance();
    $result = [
        'deleted_sentry_issues' => 0,
        'deleted_system_alerts' => 0,
    ];

    // sentry_issues
    $stmt = $db->query("SHOW TABLES LIKE ?", ['sentry_issues']);
    if ($stmt->fetchColumn()) {
        do {
            $deleted = (int) $db->execute(
                "DELETE FROM sentry_issues
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT 5000"
            );
            $result['deleted_sentry_issues'] += $deleted;
        } while ($deleted === 5000);
    }

    // system_alerts
    $stmt = $db->query("SHOW TABLES LIKE ?", ['system_alerts']);
    if ($stmt->fetchColumn()) {
        do {
            $deleted = (int) $db->execute(
                "DELETE FROM system_alerts
                 WHERE is_active = 0
                   AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT 5000"
            );
            $result['deleted_system_alerts'] += $deleted;
        } while ($deleted === 5000);
    }

    return $result;
}, 'cleanup_sentry');

// ==========================================
// Log Growth Guard (Daily 03:10)
// اگر حجم لاگ در یک ساعت اخیر غیرعادی شد، هشدار ثبت می‌کند
// ==========================================
$scheduler->daily('03:10', function () {
    $db = \Core\Database::getInstance();

    $threshold = 2000; // می‌تونی بعدا از env بخونی
    $result = [
        'activity_logs_last_hour' => 0,
        'system_logs_last_hour' => 0,
        'security_logs_last_hour' => 0,
        'performance_logs_last_hour' => 0,
        'alerts' => [],
    ];

    $queries = [
        'activity_logs_last_hour' => "SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'system_logs_last_hour' => "SELECT COUNT(*) FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'security_logs_last_hour' => "SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'performance_logs_last_hour' => "SELECT COUNT(*) FROM performance_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    ];

    foreach ((array)$queries as $key => $sql) {
        try {
            $stmt = $db->query($sql);
            $count = (int)$stmt->fetchColumn();
            $result[$key] = $count;

            if ($count >= $threshold) {
                logger()->warning('logs.growth.spike.detected', [
                    'channel' => 'monitoring',
                    'metric' => $key,
                    'count' => $count,
                    'threshold' => $threshold,
                ]);
                $result['alerts'][] = ['metric' => $key, 'count' => $count];
            }
        } catch (\Throwable $e) {
            logger()->error('logs.growth.guard.failed', [
                'channel' => 'monitoring',
                'metric' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return $result;
}, 'log_growth_guard');

// پاک‌سازی ایمیل‌های ارسال‌شده قدیمی (بیش از ۳۰ روز)
$scheduler->daily('03:00', function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM email_queue
         WHERE status = 'sent'
           AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_emails' => $affected];
}, 'cleanup_email_queue');

// پاک‌سازی خطاهای قدیمی نهایی شده در Poison Messages (بیش از ۳۰ روز)
$scheduler->daily('03:05', function () use ($container) {
    $queue = $container->make(Queue::class);
    $days = max(1, min(365, int_value(feature_config('cron_dlq_retention_days', 'rollout_percentage', 30))));
    
    $deleted = $queue->cleanDeadLetters($days);
    
    return ['deleted_dead_letters' => $deleted];
}, 'cleanup_dead_letters');

// پردازش پرداخت‌های زمانبندی‌شده
$scheduler->daily('03:15', function () use ($container) {
    $service = $container->make(\App\Services\ScheduledPaymentService::class);
    $limit   = int_value(feature_config('cron_scheduled_payment_batch_size', 'rollout_percentage', 50));
    if ($limit < 1) { $limit = 50; }

    // cursor/drain: هر batch حداکثر $limit پرداختِ سررسیده را پردازش می‌کند؛ چون پرداختِ
    // پردازش‌شده از پنجرهٔ due خارج می‌شود (next_run_at جلو می‌رود یا وضعیت failed/paused/
    // completed می‌گیرد)، تا خالی‌شدنِ کاملِ صف تکرار می‌کنیم تا backlog نماند. گاردِ اطمینان.
    $totals = ['processed' => 0, 'failed' => 0, 'batches' => 0];
    $guard  = 0;
    do {
        if (++$guard > 100000) { break; }
        $result = $service->processDuePayments($limit);
        $p = int_value($result['processed'] ?? 0);
        $f = int_value($result['failed'] ?? 0);
        $totals['processed'] += $p;
        $totals['failed']    += $f;
        $totals['batches']++;
    } while (($p + $f) >= $limit);

    return $totals;
}, 'scheduled_payments');

// پاک‌سازی تصاویر KYC رد شده قدیمی (۶۰ روز)
$scheduler->daily('03:30', function () {
    $db   = Database::getInstance();
    $cleaned   = 0;
    $batchSize = 1000;
    $lastId    = 0;
    $guard     = 0;
    // cursor: به‌جای بارگذاری همهٔ ردیف‌ها یک‌جا، batch‌به‌batch با cursorِ id تا خالی‌شدنِ
    // کامل پیش می‌رویم تا مصرف حافظه مهار شود. ردیفِ پاک‌شده documents_deleted=1 می‌گیرد
    // و از فیلتر خارج می‌شود؛ cursorِ id هم جلوی لوپ روی ردیفی که UPDATEاش شکست بخورد را می‌گیرد.
    do {
        if (++$guard > 100000) { break; }

        $rows = $db->fetchAll(
            "SELECT id, document_front, document_back, selfie
             FROM kyc_verifications
             WHERE status = 'rejected'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
               AND documents_deleted = 0
               AND id > ?
             ORDER BY id ASC
             LIMIT {$batchSize}",
            [$lastId]
        ) ?: [];
        $fetched = count($rows);

    foreach ($rows as $row) {
        $row = (array)$row;
        if ((int)$row['id'] > $lastId) {
            $lastId = (int)$row['id'];
        }
        foreach (['document_front', 'document_back', 'selfie'] as $field) {
            if (!empty($row[$field])) {
                $baseDir = realpath(storage_path('uploads/kyc'));
$file = basename((string) $row[$field]);
$path = realpath($baseDir . DIRECTORY_SEPARATOR . $file);

if (
    $path !== false &&
    $baseDir !== false &&
    str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR)
) {
    unlink($path);
}
            }
        }
        $db->execute(
            "UPDATE kyc_verifications SET documents_deleted = 1 WHERE id = ?",
            [$row['id']]
        );
        $cleaned++;
    }
    } while ($fetched === $batchSize);

    return ['cleaned_kyc_files' => $cleaned];
}, 'cleanup_kyc_files');

// ✅ **Idempotency Key Cleanup - مهم برای جلوگیری از رشد نامحدود DB**
// حذف کلیدهای منقضی‌شده (۹۰ روز برای عملیات مالی)
$scheduler->daily('03:45', function () use ($container) {
    try {
        $idempotencyKey = $container->make(\App\Services\Shared\IdempotencyService::class);
        $deleted = $idempotencyKey->cleanup(false); // Live delete
        
        if ($deleted > 0) {
            logger()->info('idempotency.cleanup.completed', [
                'channel' => 'maintenance',
                'deleted_keys' => $deleted,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return [
            'deleted_idempotency_keys' => $deleted,
            'retention_days' => 90,
        ];
    } catch (\Throwable $e) {
        logger()->error('idempotency.cleanup.failed', [
            'channel' => 'maintenance',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'idempotency_cleanup');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۴:۰۰ - ریست ماهانه
 * ─────────────────────────────────────────
 */

// ریست آمار ماهانه سطح کاربران (اول هر ماه)
$scheduler->daily('04:00', function () use ($container) {
    if ((int)date('j') !== 1) {
        return ['skipped' => 'not first day of month'];
    }
    $service = $container->make(UserLevelService::class);
    $reset   = $service->monthlyReset();
    return ['reset_users' => $reset];
}, 'monthly_level_reset');

/**
 * ─────────────────────────────────────────
 * هفتگی - یکشنبه ساعت ۰۵:۰۰
 * ─────────────────────────────────────────
 */

// گزارش هفتگی KPI به ادمین
$scheduler->weekly('Sunday', '05:00', function () {
    $db = Database::getInstance();

    // تعداد ثبت‌نام‌های هفته گذشته
    $newUsers = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM users
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    // مجموع تراکنش‌های هفته گذشته
    $txVolume = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND status = 'completed'"
    );

    // ذخیره در cache برای داشبورد ادمین
    Cache::getInstance()->put('kpi_weekly_report', [
        'new_users'    => $newUsers,
        'tx_volume'    => $txVolume,
        'generated_at' => date('Y-m-d H:i:s'),
    ], 10080); // یک هفته

    return ['new_users' => $newUsers, 'tx_volume' => $txVolume];
}, 'weekly_kpi_report');

// توزیع سود/ضرر سرمایه‌گذاری هفتگی به صورت Asynchronous (جلوگیری از قفل شدن Cron)
$scheduler->weekly('Sunday', '05:10', function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\InvestmentProfitDistributionJob::class);
    return ['status' => 'queued'];
}, 'investment_profit_distribution');

// ==========================================
//  SocialTask Jobs
// ==========================================


// ── هر شب ساعت ۱:۳۰ — Trust Score هفتگی (بهبود + جریمه soft_excess)
$scheduler->daily('01:30', function () use ($container) {
    $svc    = $container->make(TrustService::class);
    $result = $svc->recoverWeekly(\App\Enums\ModuleContext::SOCIAL_TASKS);
    return $result;
}, 'social_task_trust_recovery');

// ── هر روز صبح — ارسال یادآوری برای بررسی تسک‌های انجام‌شده (Task Management)
$scheduler->daily('09:00', function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\SocialTaskApprovalReminderJob::class);
    return ['status' => 'queued'];
}, 'social_task_approval_reminder');

// ── هر ساعت — انقضای execution های زمان‌گذشته (بیش از ۲۴ ساعت pending)
// مشکل #13: از execute() برای DML استفاده می‌کنیم که مستقیماً تعداد affected rows برمی‌گرداند
$scheduler->hourly(function () {
    $db    = Database::getInstance();
    $count = (int) $db->execute(
        "UPDATE social_task_executions
         SET status = 'expired', updated_at = NOW()
         WHERE status = 'pending'
           AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if ($count > 0) {
        // بازگرداندن slot به آگهی
        $db->execute(
            "UPDATE social_ads sa
             JOIN (
                 SELECT ad_id, COUNT(*) AS cnt
                 FROM social_task_executions
                 WHERE status = 'expired'
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 GROUP BY ad_id
             ) ex ON ex.ad_id = sa.id
             SET sa.remaining_slots = sa.remaining_slots + ex.cnt
             WHERE sa.status = 'active'"
        );
    }
    return ['expired' => $count];
}, 'social_task_expire_pending');

// ==========================================
//  اجرا
// ==========================================

echo '[' . date('Y-m-d H:i:s') . '] شروع اجرای cron jobs' . PHP_EOL;

// ─────────────────────────────────────────────────────────────────
//  اینفلوئنسر مارکت‌پلیس
// ─────────────────────────────────────────────────────────────────

/**
 * هر ساعت: تایید خودکار buyer check هایی که مهلتشان گذشته
 * وقتی buyer در ۲۴ ساعت پاسخ ندهد → auto-approve → پرداخت به اینفلوئنسر
 */
// مشکل #12: نام job برای مانیتورینگ/دیباگ اضافه شد
$scheduler->hourly(function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->processExpiredBuyerChecks();
    if ($count > 0) {
        echo "[Influencer] Auto-approved {$count} buyer-check timeout orders\n";
    }
    return ['approved' => $count];
}, 'influencer_buyer_check_timeout');

/**
 * هر ساعت: رد خودکار سفارش‌هایی که اینفلوئنسر در مهلت پاسخ نداده
 */
$scheduler->hourly(function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->processExpiredPendingAcceptance();
    if ($count > 0) {
        echo "[Influencer] Auto-rejected {$count} orders with no influencer response\n";
    }
    return ['rejected' => $count];
}, 'influencer_expire_pending_acceptance');

/**
 * هر ساعت: escalate اختلاف‌هایی که peer resolution timeout شده
 */
$scheduler->hourly(function () use ($container) {
    $service = $container->make(DisputeService::class);
    $count   = $service->processExpiredPeerResolutions();
    if ($count > 0) {
        echo "[Influencer] Escalated {$count} peer-resolution timeouts to admin\n";
    }
    return ['escalated' => $count];
}, 'influencer_escalate_peer_resolution');

/**
 * روزانه: پاکسازی فایل‌های مدرک قدیمی
 */
$scheduler->daily('05:00', function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->cleanupOldFiles(3);
    if ($count > 0) {
        echo "[Influencer] Cleaned up proof files for {$count} orders\n";
    }
    return ['cleaned_orders' => $count];
}, 'influencer_cleanup_proof_files');

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5e — Advanced Settings & Management
// ─────────────────────────────────────────────────────────────────────────────

/**
 * روزانه: حذف خودکار حساب‌های منقضی و پاک‌سازی فایل‌های صادر شده
 */
$scheduler->daily('04:00', function () use ($container) {
    try {
        $accountDeletionService = $container->make(\App\Services\User\AccountDeletionService::class);
        $dataExportService = $container->make(\App\Services\DataExportService::class);

        // حذف حساب‌های منقضی
        $deletedCount = $accountDeletionService->processExpiredDeletionRequests();
        echo "[Phase5e] Processed {$deletedCount} expired account deletion requests\n";

        // پاک‌سازی فایل‌های منقضی
        $deletedFiles = $dataExportService->deleteExpiredExports();
        echo "[Phase5e] Cleaned up {$deletedFiles} expired export files\n";

    } catch (\Exception $e) {
        echo "[Phase5e ERROR] " . $e->getMessage() . "\n";
    }
}, 'process_scheduled_tasks');

/**
 * هر ساعت: آزادسازی خودکار escrow های منقضی شده
 */
$scheduler->hourly(function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\EscrowTimeoutJob::class);
    return ['status' => 'queued'];
}, 'escrow_timeout_release');

/**
 * هر ۱۰ دقیقه: اسکن خودکار برداشت‌های گیر کرده (stuck withdrawal)
 * 🛡️ Bug Fix: قبلاً فقط به صورت CLI دستی قابل اجرا بود
 */
$scheduler->everyMinutes(10, function () use ($container) {
    $job = $container->make(\App\Jobs\WithdrawalTimeoutJob::class);
    $result = $job->handle();
    return [
        'scanned' => $result['scanned'] ?? 0,
        'flagged' => $result['flagged'] ?? 0,
    ];
}, 'withdrawal_stuck_timeout');

/**
 * هر ساعت: رد خودکار KYCهای منقضی شده
 * 🛡️ Bug Fix: قبلاً هیچ cron ای برای timeout KYC وجود نداشت
 */
$scheduler->hourly(function () use ($container) {
    $job = $container->make(\App\Jobs\KycTimeoutJob::class);
    $result = $job->handle();
    return [
        'scanned'       => $result['scanned'] ?? 0,
        'auto_rejected' => $result['auto_rejected'] ?? 0,
    ];
}, 'kyc_timeout_reject');

/**
 * روزانه: پاکسازی اعلان‌های قدیمی
 */
$scheduler->daily('04:10', function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\NotificationCleanupJob::class);
    return ['status' => 'queued'];
}, 'notification_cleanup');

/**
 * هر ساعت: انقضای لیست ویترین و آزادسازی Holdهای منقضی شده
 */
$scheduler->hourly(function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\VitrineListingExpiryJob::class);
    return ['status' => 'queued'];
}, 'vitrine_listing_expiry');

/**
 * روزانه: ریزش امتیاز کاربران غایب
 */
$scheduler->daily('02:20', function () use ($container) {
    $cronService = $container->make(\App\Services\Cron\CronService::class);
    return $cronService->applyInactivityScoreDecay();
}, 'inactivity_score_decay');

/**
 * هر ۱۵ دقیقه: تسویه خودکار بازی‌های پیش‌بینی
 */
$scheduler->everyMinutes(15, function () use ($container) {
    $container->make(Queue::class)->push(\App\Jobs\PredictionGameSettlementJob::class);
    return ['status' => 'queued'];
}, 'prediction_game_settlement');

/**
 * روزانه: به‌روزرسانی لیست Tor Exit Nodes
 */
$scheduler->daily('04:20', function () use ($container) {
    $command = $container->make(\App\Commands\UpdateTorExitNodesCommand::class);
    $command->run([]);
    return ['status' => 'ok'];
}, 'update_tor_exit_nodes');


// ─────────────────────────────────────────────────────────────────────────────
// نوتیفیکیشن — Scheduling & Analytics
// ─────────────────────────────────────────────────────────────────────────────

/**
 * هر دقیقه: ارسال نوتیفیکیشن‌های زمان‌بندی‌شده
 */
$scheduler->everyMinute(function () use ($container) {
    $notifModel = $container->make(NotificationModel::class);
    $pending    = $notifModel->getPendingScheduled(50);

    if (empty($pending)) {
        return ['processed' => 0];
    }

    $notifService = $container->make(NotificationService::class);
    $processed    = 0;

    foreach ($pending as $notif) {
        // علامت ارسال‌شده — جلوگیری از ارسال دوباره
        $notifModel->markAsSent($notif->id);

        // Push برای نوتیف‌های زمان‌بندی‌شده (در صورت نیاز)
        $notifService->invalidateUnreadCache((int)$notif->user_id);
        $processed++;
    }

    echo "[Notification] Processed {$processed} scheduled notifications\n";

    return ['processed' => $processed];
}, 'notification_scheduled');


/**
 * هر ساعت: آرشیو نوتیفیکیشن‌های منقضی‌شده
 */
$scheduler->hourly(function () use ($container) {
    $notifModel = $container->make(NotificationModel::class);
    $count      = $notifModel->archiveExpired();

    // مشکل #11: رشته echo صحیح
    if ($count > 0) {
        echo "[Notification] Archived {$count} expired notifications\n";
    }

    return ['archived' => $count];
}, 'notification_expire');

/**
 * هر ساعت: batch aggregation آمار نوتیفیکیشن
 */
$scheduler->hourly(function () use ($container) {
    $notifService = $container->make(NotificationService::class);
    $result       = $notifService->runBatchAggregation();
    if (($result['updated'] ?? 0) > 0) {
        echo "[Notification] Aggregated {$result['sent']} notifications across {$result['updated']} groups\n";
    }
    return $result;
}, 'notification_analytics');

// اجرای OutboxPublisher هر X ثانیه (قابل تنظیم توسط ادمین)
$scheduler->everySeconds(int_value(setting('outbox_publish_interval', 60)), function () {
    // توضیح: OutboxPublisher مسئول ارسال رویدادهای ذخیره‌شده در جدول Outbox به سیستم پیام‌رسان است.
    // کاهش این مقدار باعث ارسال سریع‌تر پیام‌ها می‌شود اما بار سرور را افزایش می‌دهد.
    $command = 'php ' . BASE_PATH . '/cli.php outbox:publish --limit=100';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        logger()->error('cron.outbox_publish.failed', ['output' => $output, 'exit_code' => $exitCode]);
    }
    return ['output' => $output, 'exit_code' => $exitCode];
}, 'outbox_publisher');

$scheduler->daily('04:30', function () use ($container) {
    echo "Running Daily Maintenance (Retention, Archival, Backup, System Cleanup)... \n";
    try {
        $maintenanceService = $container->make(\App\Services\MaintenanceService::class);
        $results = $maintenanceService->runDailyMaintenance();
        
        echo "Maintenance Completed. Results: " . json_encode($results) . "\n";
    } catch (\Throwable $e) {
        logger()->error('cron.database_maintenance.failed', ['error' => $e->getMessage()]);
        echo "Maintenance Failed: " . $e->getMessage() . "\n";
    }
}, 'database_maintenance');

// هر ۵ دقیقه: تازه‌سازی داده‌های داشبورد (Materialized View)
$scheduler->everyMinutes(5, function () use ($container) {
    echo "Refreshing Dashboard Materialized Views...\n";
    try {
        $transactionQuery = $container->make(\App\Models\TransactionQuery::class);
        $transactionQuery->refreshMaterializedView();
        echo "Dashboard Materialized Views refreshed successfully.\n";
    } catch (\Throwable $e) {
        logger()->error('cron.mv_refresh.failed', ['error' => $e->getMessage()]);
        echo "MV Refresh Failed: " . $e->getMessage() . "\n";
    }
}, 'dashboard_mv_refresh');

// هر یک دقیقه: مانیتورینگ بلادرنگ عمق صف‌ها (Queue Depth Monitoring)
$scheduler->everyMinutes(1, function () use ($container) {
    try {
        $queue = $container->make(Queue::class);
        $queues = ['high_priority', 'default', 'analytics', 'notifications', 'maintenance'];
        
        $stats = [];
        $hasBacklog = false;
        
        foreach ($queues as $qName) {
            $size = $queue->size($qName);
            $stats[$qName] = $size;
            
            // آستانه هشدار برای هر صف
            $threshold = match($qName) {
                'high_priority' => 1000,
                'default' => 5000,
                default => 10000,
            };
            
            if ($size >= $threshold) {
                $hasBacklog = true;
                logger()->critical('queue_monitoring.backlog_alert', [
                    'queue' => $qName,
                    'current_depth' => $size,
                    'threshold' => $threshold,
                    'action_required' => 'Auto-scale consumers or investigate blockages'
                ]);
            }
        }
        
        // ارسال متریک‌ها به سرویس مانیتورینگ/گرافانا
        logger()->info('queue_monitoring.depth_metrics', $stats);
        
        if ($hasBacklog) {
            echo "⚠️ [ALERT] Queue backlog detected! Check logs.\n";
        }
        
        return ['status' => 'ok', 'depth' => $stats];
    } catch (\Throwable $e) {
        logger()->error('queue_monitoring.failed', ['error' => $e->getMessage()]);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}, 'queue_depth_monitor');
    }
}
