<?php

declare(strict_types=1);

namespace App\Services\Investment;

use App\Contracts\LoggerInterface;
use App\Models\Investment;
use App\Services\AuditTrail;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\ValueObjects\Money;

/**
 * InvestmentQueryService — عملیات خواندن سرمایه‌گذاری
 *
 * مسئولیت‌ها:
 *   - گزارش توانگری مالی (Solvency Report)
 *   - جستجوی سرمایه‌گذاری‌ها
 *   - دریافت تنظیمات
 */
class InvestmentQueryService
{
    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private Investment $investmentModel;
    private ?AuditTrail $auditTrail;

    public function __construct(
        LoggerInterface $logger,
        AppSettings $appSettings,
        Investment $investmentModel,
        ?AuditTrail $auditTrail = null
    ) {
        $this->logger          = $logger;
        $this->appSettings     = $appSettings;
        $this->investmentModel = $investmentModel;
        $this->auditTrail      = $auditTrail;
    }

    /**
     * گزارش توانگری مالی سرمایه‌گذاری
     */
    /** @return array<string, mixed> */
    public function getSolvencyReport(): array
    {
        $currency = 'USDT';

        $rawInvestments = $this->investmentModel->getTotalActiveBalance();
        $totalInvestments = Money::of($rawInvestments, $currency);

        if ($totalInvestments->isZero()) {
            return [
                'ratio'             => '1',
                'shortfall'         => '0',
                'total_investments' => '0',
                'real_assets'       => '0',
                'status'            => 'solvent',
            ];
        }

        $rawInitial = $this->investmentModel->getTotalInitialInvested();
        $rawTradingPL = $this->investmentModel->getTotalTradingProfitLoss();

        $totalInitialInvested   = Money::of($rawInitial, $currency);
        $totalTradingProfitLoss = Money::of($rawTradingPL, $currency);

        $realAssets = $totalInitialInvested->add($totalTradingProfitLoss);

        $ratio = bcdiv($realAssets->getAmount(), $totalInvestments->getAmount(), 8);

        $diff = $totalInvestments->subtract($realAssets);
        $shortfall = $diff->isNegative() ? '0' : $diff->getAmount();

        if (bccomp($ratio, '0.9', 8) < 0) {
            $this->logger->critical('Solvency alert! Solvency ratio has dropped below 90% (' . bcmul($ratio, '100', 2) . '%)');
            $this->auditTrail?->record('system.solvency_alert', 0, [
                'ratio'             => $ratio,
                'total_investments' => $totalInvestments->getAmount(),
                'real_assets'       => $realAssets->getAmount(),
                'shortfall'         => $shortfall,
            ]);
        }

        return [
            'ratio'             => $ratio,
            'shortfall'         => $shortfall,
            'total_investments' => $totalInvestments->getAmount(),
            'real_assets'       => $realAssets->getAmount(),
            'status'            => bccomp($ratio, '0.9', 8) >= 0 ? 'solvent' : 'insolvent',
        ];
    }

    /**
     * جستجوی سرمایه‌گذاری‌ها
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->investmentModel->searchNative($q, $filters, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return [
            'min_amount'          => float_value($this->appSettings->get('investment_min_amount', 10)),
            'max_amount'          => float_value($this->appSettings->get('investment_max_amount', 10000)),
            'site_fee_percent'    => float_value($this->appSettings->get('investment_site_fee_percent', 10)),
            'tax_percent'         => float_value($this->appSettings->get('investment_tax_percent', 9)),
            'withdrawal_cooldown' => Investment::WITHDRAWAL_COOLDOWN_DAYS,
            'deposit_lock'        => Investment::DEPOSIT_LOCK_DAYS,
        ];
    }
}
