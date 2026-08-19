<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PredictionService;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * PredictionGameSettlementJob
 *
 * COMPATIBILITY_JOB / SCHEDULED_GUARD:
 * مسیر اصلی تسویه از پنل ادمین و PredictionService انجام می‌شود. این Job حذف نشده، چون در
 * Kernel هر ۱۵ دقیقه صف می‌شود و برای سازگاری با سناریوهای قدیمی/داخلی که بازی را
 * به status='closed' همراه result اما بدون winners_paid می‌گذارند، همان مسیر اصلی
 * PredictionService::settleGame را صدا می‌زند.
 *
 * نکته مهم: Job خودش هیچ پرداخت مستقیمی انجام نمی‌دهد و مسیر مالی را دور نمی‌زند.
 */
class PredictionGameSettlementJob
{
    private const SYSTEM_ADMIN_ID = 0;

    public function __construct(
        private PredictionService $predictionService,
        private Database $db,
        private LoggerInterface $logger
    ) {}

    /**
     * @return array{success:bool,settled:int,failed:int,skipped:int}
     */
/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(array $data = []): array
    {
        $settled = 0;
        $failed = 0;
        $skipped = 0;

        try {
            $games = $this->db->fetchAll(
                "SELECT id, result
                 FROM prediction_games
                 WHERE status = 'closed'
                   AND result IS NOT NULL
                   AND result != ''
                   AND winners_paid = 0
                   AND finished_at IS NULL
                   AND deleted_at IS NULL
                 ORDER BY bet_deadline ASC
                 LIMIT 50"
            );

            foreach ($games as $game) {
                try {
                    $result = $this->predictionService->settleGame(
                        (int)$game->id,
                        (string)$game->result,
                        self::SYSTEM_ADMIN_ID
                    );

                    if (!empty($result['success'])) {
                        $settled++;
                        $this->logger->info('prediction.auto_settled', [
                            'game_id' => (int)$game->id,
                            'result'  => (string)$game->result,
                            'summary' => $result['summary'] ?? [],
                        ]);
                    } else {
                        $failed++;
                        $this->logger->warning('prediction.auto_settle_rejected', [
                            'game_id' => (int)$game->id,
                            'result'  => (string)$game->result,
                            'message' => $result['message'] ?? 'تسویه خودکار انجام نشد.',
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->error('prediction.auto_settle_failed', [
                        'game_id' => (int)$game->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            if (empty($games)) {
                $skipped = 0;
            }

            $payload = [
                'success' => $failed === 0,
                'settled' => $settled,
                'failed'  => $failed,
                'skipped' => $skipped,
            ];
            $this->logger->info('prediction.settlement_job_completed', $payload);
            return $payload;
        } catch (\Throwable $e) {
            $this->logger->error('prediction.settlement_job_fatal', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'settled' => $settled,
                'failed'  => $failed + 1,
                'skipped' => $skipped,
            ];
        }
    }
}
