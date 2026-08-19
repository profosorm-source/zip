<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use Core\Database;
use App\Models\Withdrawal;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

class WithdrawalQueryService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }


    private Withdrawal $model;

    private \Core\Database $db;
    private AppSettings $appSettings;

    public function __construct(
        \Core\Database $db,
        Withdrawal $model,
        AppSettings $appSettings
    ) {
        $this->db = $db;
        $this->model = $model;
        $this->appSettings = $appSettings;
    }

    /**
     * بررسی وجود درخواست برداشت معلق
     */
    public function hasPendingWithdrawal(int $userId, bool $forUpdate = false): bool
    {
        return $this->model->hasPendingWithdrawal($userId, $forUpdate);
    }

    /**
     * دریافت لیست درخواست‌های برداشت کاربر
     */
    /** @return list<\stdClass> */
    public function getUserWithdrawals(int $userId): array
    {
        return $this->model->getUserWithdrawals($userId);
    }

    /**
     * دریافت اطلاعات سقف‌های مالی برداشت کاربر
     */
    /** @return array<string, mixed> */
    public function getLimitsForUser(int $userId, string $currency): array
    {
        $currency = strtoupper((string)$currency);
        $currencyKey = strtolower((string)$currency);

        $defaultDailyLimit = $currency === 'USDT' ? '5000.00000000' : '50000000.0000';
$defaultWeeklyLimit = $currency === 'USDT' ? '25000.00000000' : '250000000.0000';
        $defaultMonthlyLimit = $currency === 'USDT' ? '100000.00000000' : '1000000000.0000';

        // Limits are intentionally read from system_settings/AppSettings so admins can
        // change them from the Settings panel instead of changing source code.
        $dailyLimit = $this->normalizePositiveMoney(
            $this->appSettings->get("withdrawal_daily_limit_{$currencyKey}", $defaultDailyLimit),
            $defaultDailyLimit
        );
        $weeklyLimit = $this->normalizePositiveMoney(
            $this->appSettings->get("withdrawal_weekly_limit_{$currencyKey}", $defaultWeeklyLimit),
            $defaultWeeklyLimit
        );
        $monthlyLimit = $this->normalizePositiveMoney(
            $this->appSettings->get("withdrawal_monthly_limit_{$currencyKey}", $defaultMonthlyLimit),
            $defaultMonthlyLimit
        );

        $userLimit = $this->toObject($this->db->selectOne(
            "SELECT daily_limit, monthly_limit FROM withdrawal_limits WHERE user_id = ? LIMIT 1",
            [$userId]
        ));
        if ($userLimit) {
            $dailyLimit = $this->normalizePositiveMoney($userLimit->daily_limit ?? null, $dailyLimit);
            $monthlyLimit = $this->normalizePositiveMoney($userLimit->monthly_limit ?? null, $monthlyLimit);
        }

        $dailyRow = $this->toObject($this->db->selectOne(
            "SELECT COALESCE(SUM(amount), 0) AS used_today
             FROM withdrawals
             WHERE user_id = ? AND LOWER(currency) = ?
               AND status IN ('pending', 'processing', 'completed')
               AND created_at >= DATE(NOW())",
            [$userId, $currencyKey]
        ));
        $usedToday = (string)($dailyRow->used_today ?? '0');

        $weeklyRow = $this->toObject($this->db->selectOne(
            "SELECT COALESCE(SUM(amount), 0) AS used_week
             FROM withdrawals
             WHERE user_id = ? AND LOWER(currency) = ?
               AND status IN ('pending', 'processing', 'completed')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId, $currencyKey]
        ));
        $usedWeek = (string)($weeklyRow->used_week ?? '0');

        $monthlyRow = $this->toObject($this->db->selectOne(
            "SELECT COALESCE(SUM(amount), 0) AS used_month
             FROM withdrawals
             WHERE user_id = ? AND LOWER(currency) = ?
               AND status IN ('pending', 'processing', 'completed')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$userId, $currencyKey]
        ));
        $usedMonth = (string)($monthlyRow->used_month ?? '0');

        $remainingDaily = $this->remainingLimit($dailyLimit, $usedToday);
        $remainingWeekly = $this->remainingLimit($weeklyLimit, $usedWeek);
        $remainingMonthly = $this->remainingLimit($monthlyLimit, $usedMonth);

        return [
            'daily_limit'             => $dailyLimit,
            'weekly_limit'            => $weeklyLimit,
            'monthly_limit'           => $monthlyLimit,
            'used_today'              => $usedToday,
            'used_week'               => $usedWeek,
            'used_month'              => $usedMonth,
            'remaining_limit'         => $remainingDaily, // Backward-compatible alias for daily remaining.
            'remaining_daily_limit'   => $remainingDaily,
            'remaining_weekly_limit'  => $remainingWeekly,
            'remaining_monthly_limit' => $remainingMonthly,
        ];
    }

    private function normalizePositiveMoney(mixed $value, string $fallback): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        if ($value === '' || !is_numeric($value) || bccomp($value, '0', 8) <= 0) {
            return $fallback;
        }

        return $value;
    }

    private function remainingLimit(string $limit, string $used): string
    {
        $remaining = \Core\ValueObjects\Money::fromString($limit)
            ->subtract(\Core\ValueObjects\Money::fromString($used))
            ->getAmount();

        if (\Core\ValueObjects\Money::fromString('0')->isGreaterThan(\Core\ValueObjects\Money::fromString($remaining))) {
            return '0';
        }

        return $remaining;
    }

    public function findById(int $withdrawalId): ?\stdClass
    {
        $w = $this->toObject($this->model->find($withdrawalId));
        return $w;
    }

    /** @return list<\stdClass> */
    public function getPendingWithdrawals(int $limit = 50, int $offset = 0): array
    {
        return $this->model->getPendingWithdrawals($limit, $offset);
    }

    public function countPendingWithdrawals(): int
    {
        return $this->model->countPendingWithdrawals();
    }

    /** @return list<\stdClass> */
    public function getAll(?string $status = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        return $this->model->getAll($status, $currency, $limit, $offset);
    }

    public function countAll(?string $status = null, ?string $currency = null): int
    {
        return $this->model->countAll($status, $currency);
    }

    /** @return array<string, mixed> */
    public function getSummaryStats(): array
    {
        return $this->model->getSummaryStats();
    }
}