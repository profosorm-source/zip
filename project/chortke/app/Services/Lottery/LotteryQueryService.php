<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use Core\Cache;

class LotteryQueryService
{
    private LotteryRound $roundModel;
    private LotteryParticipation $participationModel;
    private LotteryDailyNumber $dailyModel;
    private Cache $cache;

    private const ANALYTICS_CACHE_TTL = 15;

    public function __construct(
        LotteryRound $roundModel,
        LotteryParticipation $participationModel,
        LotteryDailyNumber $dailyModel,
        Cache $cache
    ) {
        $this->roundModel = $roundModel;
        $this->participationModel = $participationModel;
        $this->dailyModel = $dailyModel;
        $this->cache = $cache;
    }

    /**
     * ROOT FIX (principled): Centralized toObject helper.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function listRounds(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $items = $this->roundModel->getAll($filters, $limit, $offset);
        $total = $this->roundModel->countAll($filters);
        return ['items' => $items, 'total' => $total];
    }

    public function getStats(): object
    {
        return $this->roundModel->getStats();
    }

    /**
     * @param list<int> $roundIds
     * @return array<int, int>
     */
    public function getParticipationCounts(array $roundIds): array
    {
        return $this->participationModel->getCountsByRounds($roundIds);
    }

    /** @return array<string, mixed> */
    public function getRoundStatistics(int $roundId): array
    {
        $cacheKey = "round_stats_{$roundId}";
        $cached = $this->cache->get($cacheKey);
        
        if ($cached !== null) {
            if (!is_array($cached)) throw new \UnexpectedValueException('Lottery statistics cache must contain an array.');
            return $cached;
        }

        $round = $this->toObject($this->roundModel->find($roundId));
        if (!$round) { 
        return ['success' => false, 'message' => 'دوره یافت نشد.'];
        }

        $participants = $this->participationModel->getAllActiveByRound($roundId);
        $totalScore = $this->participationModel->getTotalChanceScore($roundId);
        $distribution = $this->participationModel->getChanceDistribution($roundId);
        $dailyNumbers = $this->dailyModel->getByRound($roundId);

        $stats = [
            'success' => true,
            'round' => $round,
            'total_participants' => count($participants),
            'total_chance_score' => $totalScore,
            'average_score' => count($participants) > 0 ? round($totalScore / count($participants), 2) : 0,
            'chance_distribution' => $distribution,
            'daily_numbers' => $dailyNumbers,
            'ticket_sales_volume' => number_format(count($participants) * (float)$round->ticket_price, 2, '.', '')
        ];

        $this->cache->put($cacheKey, $stats, self::ANALYTICS_CACHE_TTL);
        return $stats;
    }

    public function getTransparencyText(): string
    {
        return "🎯 شفافیت و اعتمادسازی سیستم قرعه‌کشی چرتکه\r\n\r\n"
             . "✅ ویژگی‌ها:\r\n"
             . "• وزن‌دهی خودکار روزانه\r\n"
             . "• عدم حذف کاربران - فقط تغییر شانس\r\n"
             . "• کف شانس تضمینی: 5.0\r\n"
             . "• انتخاب وزن‌دار - شانس بالا ≠ تضمین برد\r\n"
             . "• شفافیت کامل - Seed ها و لاگ‌ها قابل بررسی\r\n\r\n"
             . "🔒 امنیت:\r\n"
             . "• الگوریتم‌های تصادفی امن\r\n"
             . "• جلوگیری از الگوهای قابل پیش‌بینی\r\n"
             . "• ثبت کامل تغییرات\r\n\r\n"
             . "💡 نتیجه: رأی کاربران + وزن‌دهی سیستم = عادلانه‌ترین روش";
    }
}
