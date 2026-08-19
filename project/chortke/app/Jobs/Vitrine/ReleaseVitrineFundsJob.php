<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Services\Settings\AppSettings;
use App\Services\ScoreService;
use Core\Database;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;

class ReleaseVitrineFundsJob
{
    private Database $db;
    private LoggerInterface $logger;
    private ScoreService $scoreService;
    private AppSettings $settings;
    private EventDispatcher $eventDispatcher;
    private \App\Services\SagaOrchestrator $sagaOrchestrator;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        ScoreService $scoreService,
        AppSettings $settings,
        EventDispatcher $eventDispatcher,
        \App\Services\SagaOrchestrator $sagaOrchestrator,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->scoreService = $scoreService;
        $this->settings = $settings;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(\stdClass $listing, string $reason = 'manual'): array
    {
        // PRECISION FIX: محاسبه کمیسیون ویترین با Money/bcmath
        $commissionPercent = str_value($this->settings->get('vitrine_commission_percent', '5'));
        $amount     = str_value($listing->offer_price_usdt ?? $listing->price_usdt ?? '0');
        $priceMoney = \Core\ValueObjects\Money::of($amount, 'USDT');
        $feeMoney   = $priceMoney->percentage($commissionPercent);
        $net        = $priceMoney->subtract($feeMoney)->getAmount();

        $this->db->beginTransaction();
        try {
            $saga = $this->sagaOrchestrator;
            $saga->setSaga('vitrine_release_funds', ['listing_id' => $listing->id]);

            $financialEscrow = app(\App\Domain\Financial\Services\FinancialEscrowService::class);

            $saga->addStep(
                'settle_vitrine_escrow',
                function () use ($listing, $amount, $financialEscrow) {
                    // One financial boundary performs locked spend, seller payout,
                    // commission ledger entry and escrow state transition.
                    $settlement = $financialEscrow->releaseVitrineFunds(
                        (int)$listing->id,
                        (int)$listing->seller_id,
                        (string)$amount,
                        'vitrine_job_release:' . (int)$listing->id
                    );
                    if (empty($settlement['ok'])) {
                        throw new \Core\Exceptions\ApplicationException(str_value($settlement['error'] ?? 'خطا در تسویه وجه ویترین'));
                    }

                    $netAmount = str_value($settlement['net_amount'] ?? '0');
                    if ($this->outbox) {
                        $this->outbox->record('vitrine', (int)$listing->id, 'vitrine.release_funds_requested', [
                            'seller_id'  => (int)$listing->seller_id,
                            'amount'     => $netAmount,
                            'listing_id' => (int)$listing->id,
                        ]);
                    } else {
                        $this->eventDispatcher->dispatch('vitrine.release_funds_requested', [
                            'seller_id'  => (int)$listing->seller_id,
                            'amount'     => $netAmount,
                            'listing_id' => (int)$listing->id,
                        ]);
                    }
                    return true;
                },
                function (\Throwable $e) use ($listing) {
                    $this->logger->warning('saga.compensating.vitrine_release_seller', ['listing_id' => $listing->id]);
                }
            )->addStep(
                'update_listing_status',
                function () use ($listing, $reason) {
                    // ROOT FIX: an unconditional status write let a manual release and the auto-release
                    // cron (or a retried job) both mark the same listing as sold, and it could also
                    // overwrite a terminal state such as cancelled/refunded. The transition is now a
                    // conditional compare-and-swap, so exactly one actor can move the listing from an
                    // escrowed/disputed state to sold; anything else fails the saga step.
                    $autoConfirmed = ($reason === 'auto_cron') ? 1 : 0;
                    $claimed = $this->db->execute(
                        "UPDATE vitrine_listings
                            SET status = ?, auto_confirmed = ?, updated_at = NOW()
                          WHERE id = ? AND status IN (?, ?)",
                        [
                            \App\Models\VitrineListing::STATUS_SOLD,
                            $autoConfirmed,
                            (int) $listing->id,
                            \App\Models\VitrineListing::STATUS_IN_ESCROW,
                            \App\Models\VitrineListing::STATUS_DISPUTED,
                        ]
                    );
                    if ($claimed !== 1) {
                        throw new \Core\Exceptions\ApplicationException('آگهی در وضعیت قابل تسویه نیست یا قبلاً تسویه شده است.');
                    }
                    return true;
                },
                function () {}
            );

            $saga->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('vitrine.escrow.release_failed', ['id' => $listing->id, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        if ($this->outbox) {
            $this->outbox->record('vitrine', (int)$listing->id, 'vitrine.escrow.released', [
                'seller_id'  => (int)$listing->seller_id,
                'listing_id' => (int)$listing->id,
            ]);
        } else {
            $this->eventDispatcher->dispatch('vitrine.escrow.released', [
                'seller_id'  => (int)$listing->seller_id,
                'listing_id' => (int)$listing->id,
            ]);
        }
        
        if (isset($this->scoreService)) {
            // Reward seller for successful trade
            $this->scoreService->applyDelta('user', (int)$listing->seller_id, 'vitrine_rating', 5.0, 'vitrine_sale_success_'.$listing->id);
        }

        return ['success' => true, 'net_amount' => $net];
    }
}
