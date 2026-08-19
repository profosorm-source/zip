<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Event;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Notification\NotificationService;
use App\Services\WebSocketService;
use App\Services\Shared\ReferralService;

/**
 * Vitrine Event Listeners
 */
class VitrineEventListeners
{
    protected LoggerInterface $logger;
    protected WalletServiceInterface $walletService;
    protected NotificationService $notificationService;
    protected WebSocketService $webSocket;
    protected ReferralService $referralService;
    public function __construct(
        LoggerInterface $logger,
        WalletServiceInterface $walletService,
        NotificationService $notificationService,
        WebSocketService $webSocket,
        ReferralService $referralService
    ) {        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->webSocket = $webSocket;
        $this->referralService = $referralService;

    }

    /**
     * پرداخت هزینه اسکرو برای آگهی ویترین
     */
    public function handleEscrowPaymentRequested(Event $event): void
    {
        try {
            $data = $event->getData();
            $buyerId = int_value($data['buyer_id'] ?? 0);
            $amount = str_value($data['amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'usdt');
            $listingId = int_value($data['listing_id'] ?? 0);

            if ($buyerId <= 0 || !is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
                return;
            }

            assert_fraud_allowed($buyerId, 'vitrine.escrow', ['amount' => $amount]);
            $debit = $this->walletService->pay($buyerId, $amount, $currency, [
                'type' => 'vitrine_escrow',
                'description' => "اسکرو ویترین #{$listingId}"
            ]);

            if (empty($debit['success'])) {
                throw new \RuntimeException(str_value($debit['message'] ?? 'موجودی کافی نیست.'));
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.VitrineEventListeners.handleEscrowPaymentRequested']);
            $this->logger->error('vitrine.escrow_payment.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * بازگشت وجه خریدار به دلیل رد آگهی توسط مدیریت
     */
    public function handleRefundRequested(Event $event): void
    {
        try {
            $data = $event->getData();
            $buyerId = int_value($data['buyer_id'] ?? 0);
            $amount = str_value($data['amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'usdt');
            $listingId = int_value($data['listing_id'] ?? 0);

            if ($buyerId <= 0 || !is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
                return;
            }

            $credit = $this->walletService->deposit($buyerId, $amount, $currency, [
                'type' => 'vitrine_refund',
                'description' => "بازگشت وجه ویترین #{$listingId}",
                'idempotency_key' => "vt_refund_{$listingId}_" . time(),
            ]);

            if (empty($credit['success'])) {
                throw new \RuntimeException(str_value($credit['message'] ?? 'خطا در بازگشت وجه خریدار.'));
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.VitrineEventListeners.handleRefundRequested']);
            $this->logger->error('vitrine.refund.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * اطلاع‌رسانی تایید آگهی
     */
    public function handleListingApproved(Event $event): void
    {
        try {
            $data = $event->getData();
            $listingId = int_value($data['listing_id'] ?? 0);
            $sellerId = int_value($data['seller_id'] ?? 0);

            if ($listingId && $sellerId > 0) {
                $this->webSocket->notifyListingApproved(int_value($listingId), $sellerId);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.VitrineEventListeners.handleListingApproved']);
            $this->logger->error('vitrine.listing_approved.failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * واریز وجه به کیف پول فروشنده در زمان تأیید تحویل
     */
    /**
     * @param array<string, mixed>|Event $event
     */
    public function handleReleaseFundsRequested(Event|array $event): void
    {
        try {
            $data = $event instanceof Event ? $event->getData() : $event;
            $sellerId = int_value($data['seller_id'] ?? 0);
            $amount = str_value($data['amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'usdt');
            $listingId = int_value($data['listing_id'] ?? 0);

            if ($sellerId <= 0 || bccomp($amount, '0', 8) <= 0) {
                return;
            }

            $credit = $this->walletService->deposit($sellerId, $amount, $currency, [
                'type' => 'vitrine_payout',
                'description' => "درآمد فروش آگهی ویترین #{$listingId}"
            ]);

            if (empty($credit['success'])) {
                throw new \RuntimeException(str_value($credit['message'] ?? 'خطا در واریز درآمد فروشنده.'));
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.VitrineEventListeners.handleReleaseFundsRequested']);
            $this->logger->error('vitrine.release_funds.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
