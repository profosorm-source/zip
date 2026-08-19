<?php

declare(strict_types=1);

namespace App\Services\AdminDashboard;

use Core\Database;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Constants\SystemConstants;
use App\Constants\TimeConstants;
use App\Services\DatabaseAnalyzerService;

class SystemMonitoringService
{
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\Queue $queue;
    private ?DatabaseAnalyzerService $dbAnalyzer = null;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \Core\Queue $queue,
        ?DatabaseAnalyzerService $dbAnalyzer = null
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->dbAnalyzer = $dbAnalyzer;

        
        }

    /**
     * دریافت وضعیت زنده سیستم (Uptime, Memory, Disk, CPU) با متدهای ایمن و فیل‌سیف
     */
    /**
     * @return array<string, mixed>
     */
    public function getSystemStatus(): array
    {
        // MED-26: Encapsulate high-load scans in lightweight TTL caching pools (15s) to protect against high disk I/Opolling storms
        $status = $this->cache->remember('admin_dashboard_system_status', 15, function() {
            // ۱. وضعیت دیسک
            $diskTotal = @disk_total_space('.') ?: SystemConstants::DEFAULT_DISK_TOTAL;
            $diskFree = @disk_free_space('.') ?: SystemConstants::DEFAULT_DISK_FREE;
            $diskUsed = $diskTotal - $diskFree;
            $diskPercentage = round(($diskUsed / $diskTotal) * 100, 2);

            // ۲. وضعیت حافظه رم
            $memTotal = SystemConstants::DEFAULT_MEMORY_TOTAL;
            $memFree = SystemConstants::DEFAULT_MEMORY_FREE;
            
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: در کانتینر، خواندن از cgroup به جای افشای اطلاعات هاست فیزیکی سرور
                    $cTotal = $this->readCgroupValue(['/sys/fs/cgroup/memory/memory.limit_in_bytes', '/sys/fs/cgroup/memory.max']);
                    $cUsage = $this->readCgroupValue(['/sys/fs/cgroup/memory/memory.usage_in_bytes', '/sys/fs/cgroup/memory.current']);
                    
                    // بررسی سقف عددی max که در لینوکس برگردانده می‌شود
                    if ($cTotal > 0 && $cTotal < 9223372036854770000 && $cUsage > 0) {
                        $memTotal = $cTotal;
                        $memFree = max(0, $cTotal - $cUsage);
                    }
                } else {
                    $memInfo = @file_get_contents('/proc/meminfo');
                    if ($memInfo) {
                        preg_match('/MemTotal:\s+(\d+)/', $memInfo, $matchesTotal);
                        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $matchesAvailable);
                        if (isset($matchesTotal[1])) {
                            $memTotal = (int)$matchesTotal[1] * 1024; // ✅ تبدیل کیلوبایت به بایت
                        }
                        if (isset($matchesAvailable[1])) {
                            $memFree = (int)$matchesAvailable[1] * 1024;
                        }
                    }
                }
            }
            
            $memUsed = $memTotal - $memFree;
            $memPercentage = round(($memUsed / $memTotal) * 100, 2);

            // ۳. لود پردازنده (CPU)
            $cpuLoad = 0.0;
            if (function_exists('sys_getloadavg')) {
                $load = @sys_getloadavg();
                if (is_array($load) && isset($load[0])) {
                    $cores = max(1, $this->getCpuCoreCount());
                    // MED-25: Scientifically determine resource load by dividing process load averages by active logical core caps
                    $cpuLoad = \min(100.0, \max(0.0, ((float)$load[0] / $cores) * 100.0));
                }
            }
            if ($cpuLoad <= 0.0) {
                $cpuLoad = 0.0; 
            }

            // ۴. مدت زمان روشن بودن (Uptime)
            // M37 Fix: جایگزینی مقدار هاردکد نادرست با مقدار خنثی و واقعی نامشخص در محیط‌های فاقد دسترسی
            $uptimeStr = 'نامشخص';
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: عدم خواندن Uptime هاست در محفظه کانتینر جهت حفظ محرمانگی ساختار سرور
                    $uptimeStr = 'فعال (ایمن)';
                } else {
                    $uptimeSec = @file_get_contents('/proc/uptime');
                    if ($uptimeSec) {
                        $parts = explode(' ', $uptimeSec);
                        $seconds = (int)$parts[0];
                        $days = (int)($seconds / TimeConstants::SECONDS_PER_DAY);
                        $hours = (int)(($seconds % TimeConstants::SECONDS_PER_DAY) / TimeConstants::SECONDS_PER_HOUR);
                        // LOW-14: Deliver rich localized outputs utilizing Persian string and numeral mappings
                        $uptimeStr = $this->toPersianNumbers("{$days}") . ' روز و ' . $this->toPersianNumbers("{$hours}") . ' ساعت';
                    }
                }
            }

            // ۵. وضعیت دیتابیس — deep analysis via DatabaseAnalyzerService
            $dbStatus = 'فعال';
            $dbWarnings = [];
            $dbSlowQueryCount = 0;
            $dbTableCount = 0;
            $dbDeadlockCount = 0;

            try {
                $this->db->fetch("SELECT 1");
            } catch (\Throwable) {
                $dbStatus = 'محدودشده';
            }

            if ($this->dbAnalyzer !== null && $dbStatus === 'فعال') {
                try {
                    $slowQueries = $this->dbAnalyzer->getSlowQueries(10);
                    $dbSlowQueryCount = count($slowQueries);
                    if ($dbSlowQueryCount > 20) {
                        $dbWarnings[] = "{$dbSlowQueryCount} کوئری کند شناسایی شد";
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('system_monitoring.slow_queries_failed', ['error' => $e->getMessage()]);
                }

                try {
                    $tables = $this->dbAnalyzer->getTableStats();
                    $dbTableCount = count($tables);
                } catch (\Throwable $e) {
                    $this->logger->warning('system_monitoring.table_stats_failed', ['error' => $e->getMessage()]);
                }

                try {
                    $deadlocks = $this->dbAnalyzer->getDeadlockInfo();
                    $dbDeadlockCount = count($deadlocks);
                    if ($dbDeadlockCount > 0) {
                        $dbWarnings[] = "{$dbDeadlockCount} بن‌بست (Deadlock) اخیر";
                        $dbStatus = 'هشدار';
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('system_monitoring.deadlocks_failed', ['error' => $e->getMessage()]);
                }

                // Health check for overall DB health
                try {
                    $dbHealth = $this->dbAnalyzer->healthCheck();
                    $failedChecks = array_filter($dbHealth, fn($v) => $v === false);
                    if (count($failedChecks) > 0) {
                        $dbWarnings[] = count($failedChecks) . ' مشکل در سلامت دیتابیس';
                        if ($dbStatus === 'فعال') $dbStatus = 'هشدار';
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('system_monitoring.health_check_failed', ['error' => $e->getMessage()]);
                }
            }

            // ۶. وضعیت صف‌ها (Queue Status Dashboard)
            $queueStats = [];
            try {
                $queueStats = $this->queue->getQueueStatusReport();
            } catch (\Throwable) {
                // fail-safe fallback
            }

            return [
                // ══ Frontend-compatible format (dashboard.js expects cpu/ram/disk objects) ══
                'cpu' => [
                    'pct'   => round($cpuLoad, 1),
                    'cores' => $this->getCpuCoreCount(),
                ],
                'ram' => [
                    'pct'       => $memPercentage,
                    'used_gb'   => (int) round($memUsed / (1024*1024*1024), 1),
                    'total_gb'  => (int) round($memTotal / (1024*1024*1024), 1),
                ],
                'disk' => [
                    'pct'       => $diskPercentage,
                    'used_gb'   => (int) round($diskUsed / (1024*1024*1024), 1),
                    'total_gb'  => (int) round($diskTotal / (1024*1024*1024), 1),
                    'type'      => 'SSD',
                ],
                'gpu' => ['available' => false],
                'uptime' => $uptimeStr,
                'database' => [
                    'status' => $dbStatus,
                    'slow_queries' => $dbSlowQueryCount,
                    'tables_analyzed' => $dbTableCount,
                    'deadlocks_recent' => $dbDeadlockCount,
                    'warnings' => $dbWarnings,
                ],
                'queues' => $queueStats,
            ];
        });
        if (!is_array($status)) throw new \UnexpectedValueException('System status cache must contain an array.');
        return $status;
    }

    /**
     * بررسی وضعیت سیستم و ارسال هشدار در صورت عبور از آستانه مجاز
     */
    public function checkAndAlert(): void
    {
        try {
            $status = $this->getSystemStatus();
            $disk = is_array($status['disk'] ?? null) ? $status['disk'] : [];
            $ram = is_array($status['ram'] ?? null) ? $status['ram'] : [];
            $diskPercentage = float_value($disk['pct'] ?? 0);
            $ramPercentage = float_value($ram['pct'] ?? 0);

            if ($diskPercentage >= 90) {
                if (class_exists(\App\Services\Sentry\SentryExceptionHandler::class)) {
                    \App\Services\Sentry\SentryExceptionHandler::captureMessage(
                        'CRITICAL: Disk usage exceeded 90%', 
                        'critical', 
                        null, 
                        ['usage' => $diskPercentage]
                    );
                }
            }
            
            if ($ramPercentage >= 95) {
                if (class_exists(\App\Services\Sentry\SentryExceptionHandler::class)) {
                    \App\Services\Sentry\SentryExceptionHandler::captureMessage(
                        'WARNING: Memory usage exceeded 95%', 
                        'warning', 
                        null, 
                        ['usage' => $ramPercentage]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('monitoring.alert_check_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * محاسبه زمان گذشته
     */
    public function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return 'نامشخص';
        }
        
        $diff = time() - $timestamp;
        if ($diff < 0) {
            return 'هم‌اکنون';
        }
        if ($diff < 60) {
            return 'لحظاتی پیش';
        }
        
        $output = '';
        if ($diff < 3600) {
            $output = (int)($diff / 60) . ' دقیقه پیش';
        } elseif ($diff < 86400) {
            $output = (int)($diff / 3600) . ' ساعت پیش';
        } elseif ($diff < 604800) {
            $output = (int)($diff / 86400) . ' روز پیش';
        } elseif ($diff < 2592000) {
            $output = (int)($diff / 604800) . ' هفته پیش';
        } else {
            $output = (int)($diff / 2592000) . ' ماه پیش';
        }

        // LOW-14: Format final numeric time offsets using native Persian digits
        return $this->toPersianNumbers($output);
    }

    /**
     * شمارش تعداد هسته‌های فعال پردازنده جهت کالیبراسیون لود واقعی سیستم
     */
    private function getCpuCoreCount(): int
    {
        $cores = 1;
        try {
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: جلوگیری از نشت اطلاعات تعداد هسته‌های هاست
                    $cores = 1;
                } else if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    if ($cpuinfo) {
                        preg_match_all('/^processor/m', $cpuinfo, $matches);
                        $cores = count($matches[0]) ?: 1;
                    }
                }
            } else {
                $processCount = getenv('NUMBER_OF_PROCESSORS');
                if ($processCount) {
                    $cores = (int)$processCount ?: 1;
                }
            }
        } catch (\Throwable) {
            // Fail-safe fallback to single-core divisor
            $cores = 1;
        }
        return $cores;
    }

    /**
     * تبدیل اعداد لاتین به معادل‌های بومی فارسی
     */
    private function toPersianNumbers(string $str): string
    {
        $eng = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str_replace($eng, $fa, $str);
    }

    /**
     * H18 Support: تشخیص محیط Container
     */
    private function isContainerEnvironment(): bool
    {
        return @file_exists('/.dockerenv') || @file_exists('/run/.containerenv');
    }

    /**
     * H18 Support: خواندن امن مقادیر cgroup
     */
    /**
     * @param list<string> $paths
     */
    private function readCgroupValue(array $paths): int
    {
        foreach ($paths as $path) {
            if (@is_readable($path)) {
                $val = trim((string)@file_get_contents($path));
                if (ctype_digit($val)) {
                    return (int)$val;
                }
            }
        }
        return 0;
    }
}
