<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use App\Services\InvestmentService;
use App\Services\Settings\AppSettings;
use Core\Database;

/**
 * InvestmentProfitDistributionJob
 *
 * توزیع سود/ضرر سرمایه‌گذاری‌ها به صورت دوره‌ای.
 * - لیست سرمایه‌گذاری‌های فعال را فراخوانی می‌کند.
 * - در صورت عدم ارسال داده، از آخرین ترید بسته‌شده و تنظیمات پیش‌فرض استفاده می‌کند.
 */
class InvestmentProfitDistributionJob
{
    private const SYSTEM_ADMIN_ID = 0;

    private InvestmentService $investmentService;
    private Database $db;
    private AppSettings $appSettings;
    private LoggerInterface $logger;
    public function __construct(
        InvestmentService $investmentService,
        Database $db,
        AppSettings $appSettings,
        LoggerInterface $logger
    ) {        $this->investmentService = $investmentService;
        $this->db = $db;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
}

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        $tradingRecordId = is_scalar($data['trading_record_id'] ?? null) && is_numeric((string)$data['trading_record_id']) ? (int)$data['trading_record_id'] : 0;
        $profitLossPercent = array_key_exists('profit_loss_percent', $data) && is_scalar($data['profit_loss_percent']) ? (string)$data['profit_loss_percent'] : null;
        $period = is_string($data['period'] ?? null) ? $data['period'] : 'weekly';
        $adminId = is_scalar($data['admin_id'] ?? null) && is_numeric((string)$data['admin_id']) ? (int)$data['admin_id'] : self::SYSTEM_ADMIN_ID;

        if ($tradingRecordId <= 0) {
            $tradingRecordId = $this->getLatestClosedTradingRecordId();
        }

        if ($profitLossPercent === null) {
            $configuredPercent = $this->appSettings->get('investment_default_profit_loss_percent', '0');
            $profitLossPercent = is_scalar($configuredPercent) ? (string)$configuredPercent : '0';
        }

        if ($tradingRecordId <= 0 || !is_numeric($profitLossPercent) || bccomp($profitLossPercent, '0', 8) === 0) {
            $this->logger->warning('investment.profit_distribution_skipped', [
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
            ]);
            return;
        }

        try {
            // cursor: idهای active را به‌جای یک‌جا، batch‌به‌batch با cursorِ id می‌خوانیم و هر chunk را
            // جداگانه اعمال می‌کنیم تا مصرف حافظه مهار شود. چون پردازش status را عوض نمی‌کند
            // (همچنان active می‌ماند)، حتماً باید cursorِ id باشد تا هر سرمایه‌گذاری دقیقاً یک‌بار
            // و بدون تکرار پردازش شود. رفتار نهایی مثل قبل است، فقط تکه‌تکه.
            $batchSize   = 500;
            $lastId      = 0;
            $guard       = 0;
            $totalCount  = 0;
            $results     = [];

            do {
                if (++$guard > 100000) {
                    $this->logger->warning('investment.profit_distribution_guard_tripped', ['last_id' => $lastId]);
                    break;
                }

                $rows = $this->db->fetchAll(
                    "SELECT id FROM investments
                     WHERE status = 'active'
                       AND id > ?
                     ORDER BY id ASC
                     LIMIT {$batchSize}",
                    [$lastId]
                ) ?: [];
                $fetched = count($rows);

                if ($fetched === 0) {
                    break;
                }

                $investmentIds = array_map(static fn($row): int => (int) $row->id, $rows);
                $lastId = (int) end($investmentIds);
                $totalCount += $fetched;

                $results[] = $this->investmentService->applyProfitLossToBatch(
                    $investmentIds,
                    $tradingRecordId,
                    $profitLossPercent,
                    $period,
                    $adminId
                );
            } while ($fetched === $batchSize);

            if ($totalCount === 0) {
                $this->logger->info('investment.profit_distribution_no_active_investments', [
                    'period' => $period,
                ]);
                return;
            }

            $this->logger->info('investment.profit_distribution_job_completed', [
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
                'admin_id'            => $adminId,
                'processed_count'     => $totalCount,
                'batches'             => count($results),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('investment.profit_distribution_job_failed', [
                'error'               => $e->getMessage(),
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
            ]);
        }
    }

    private function getLatestClosedTradingRecordId(): int
    {
        try {
            $row = $this->db->fetch(
                "SELECT id FROM trading_records
                 WHERE status IN (?, ?) AND is_deleted = 0
                 ORDER BY close_time DESC
                 LIMIT 1",
                ['closed', 'stopped']
            );

            return $row ? (int) $row->id : 0;
        } catch (\Throwable $e) {
            $this->logger->warning('investment.profit_distribution_no_trading_record', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
