<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;

/**
 * SearchProjectionListener — همگام‌سازی Read-Model جستجو با رویدادهای دامنه (CQRS Write→Read).
 *
 * این Listener جایگزین فایل خالی پیشین `Search\DomainActivityListener` است و
 * تنها مسئولیت آن، به‌روزرسانی جدول `search_projections` در پاسخ به رویدادهای
 * دامنه است. هیچ side-effect دیگری (مالی/نوتیفیکیشن/...) اینجا انجام نمی‌شود تا
 * مرز مسئولیت با `App\Listeners\DomainActivityListener` حفظ شود.
 *
 * طراحی fail-safe: هر خطا فقط لاگ می‌شود و هرگز جریان اصلی دامنه را نمی‌شکند.
 */
final class SearchProjectionListener
{
    private SearchIndexer $indexer;
    private LoggerInterface $logger;
    private \Core\Database $db;
    public function __construct(
        SearchIndexer $indexer,
        LoggerInterface $logger,
        \Core\Database $db
    ) {        $this->indexer = $indexer;
        $this->db = $db;
        $this->logger = $logger;

    }

    /** @param array<string, mixed> $payload */
    private function payloadId(array $payload, string ...$keys): int|string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_string($value)) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * نقطه‌ی ورود واحد؛ نام رویداد را به handler مناسب map می‌کند.
     */
    /** @param array<string, mixed> $data */
    public function handle(string|object $event, array $data = []): void
    {
        [$eventName, $payload] = $this->normalize($event, $data);

        try {
            match ($eventName) {
                // ── معاملات / مالی ──
                'wallet.deposit.completed',
                'wallet.withdraw.completed',
                'wallet.pay.completed'        => $this->indexTransaction($payload),
                'withdrawal.created',
                'withdrawal.approved'         => $this->indexWithdrawal($payload),
                'deposit.manual_created',
                'deposit.manual_approved'     => $this->indexManualDeposit($payload),

                // ── محتوا و تسک ──
                'content.created',
                'content.approved'            => $this->indexContent($payload),

                // ── ویترین ──
                'vitrine.listing_created',
                'vitrine.listing_updated'     => $this->indexVitrine($payload),
                'vitrine.listing_removed',
                'vitrine.listing_expired'     => $this->deactivate('vitrine', $this->payloadId($payload, 'id', 'listing_id')),

                // ── تیکت ──
                'ticket.created',
                'ticket.updated'              => $this->indexTicket($payload),

                // ── پیام مستقیم ──
                'direct_message.sent'         => $this->indexDirectMessage($payload),

                // ── اینفلوئنسر ──
                'influencer.profile_updated'  => $this->indexInfluencer($payload),

                // ── Prediction ──
                'prediction.created',
                'prediction.updated'          => $this->indexPrediction($payload),
                'prediction.deleted'          => $this->deactivate('prediction', $this->payloadId($payload, 'id')),

                // ── Lottery ──
                'lottery.created',
                'lottery.updated'             => $this->indexLottery($payload),
                'lottery.deleted'             => $this->deactivate('lottery', $this->payloadId($payload, 'id')),

                // ── Investment ──
                'investment.created',
                'investment.updated'          => $this->indexInvestment($payload),
                'investment.deleted'          => $this->deactivate('investment', $this->payloadId($payload, 'id')),

                // ── Bank Card ──
                'bank_card.created',
                'bank_card.updated'           => $this->indexBankCard($payload),
                'bank_card.deleted'           => $this->deactivate('bank_card', $this->payloadId($payload, 'id')),

                // ── KYC ──
                'kyc.created',
                'kyc.updated',
                'kyc.approved',
                'kycapproved',
                'k.y.c.approved'              => $this->indexKyc($payload),

                // ── Escrow ──
                'escrow.created',
                'escrow.updated',
                'escrow.released'              => $this->indexEscrow($payload),

                // ── Social Task ──
                'social_task.created',
                'social_task.updated',
                'social_task_execution.created',
                'social_task_execution.updated',
                'task.completed'                => $this->indexSocialTask($payload),
                'social_task.deleted'           => $this->deactivate('social_task', $this->payloadId($payload, 'id')),

                // ── Crypto Deposit ──
                'crypto_deposit.created',
                'crypto_deposit.updated',
                'crypto_deposit.completed'      => $this->indexCryptoDeposit($payload),

                // ── Message Moderation (Ticket Messages) ──
                'ticket_message.created',
                'ticket_message.moderated'      => $this->indexTicketMessage($payload),

                // ── Coupon ──
                'coupon.created',
                'coupon.updated'              => $this->indexCoupon($payload),
                'coupon.deleted'              => $this->deactivate('coupon', $this->payloadId($payload, 'id')),

                // ── حذف عمومی ──
                'account.deleted'             => $this->deactivateOwner(
                    isset($payload['user_id']) && (is_int($payload['user_id']) || (is_string($payload['user_id']) && is_numeric($payload['user_id'])))
                        ? (int)$payload['user_id']
                        : 0
                ),

                default                       => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.sync_failed', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Handlers
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $d */
    private function indexTransaction(array $d): void
    {
        $result = is_array($d['result'] ?? null) ? $d['result'] : $d;
        $id = int_value($result['transaction_db_id'] ?? $d['transaction_db_id'] ?? 0);
        $txId = str_value($result['transaction_id'] ?? $d['transaction_id'] ?? '');
        $userId = int_value($d['user_id'] ?? 0);
        if ($id <= 0 && $txId === '') {
            return;
        }

        $title = trim(($result['type'] ?? 'transaction') . ' ' . ($result['amount'] ?? ''));
        $content = trim(($result['description'] ?? '') . ' ' . $txId);

        $this->dualScope('transaction', $id > 0 ? $id : crc32($txId), $userId, 'transactions', $txId, $title, $content, [
            'transaction_id' => $txId,
            'amount'   => $result['amount'] ?? null,
            'currency' => $result['currency'] ?? null,
            'status'   => $result['status'] ?? null,
            'type'     => $result['type'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $d */
    private function indexWithdrawal(array $d): void
    {
        $id = int_value($d['id'] ?? $d['withdrawal_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = int_value($d['user_id'] ?? 0);
        $ref = str_value($d['tracking_code'] ?? $d['transaction_id'] ?? '');
        $this->dualScope('withdrawal', $id, $userId, 'withdrawals', $ref,
            'withdrawal ' . ($d['amount'] ?? ''),
            trim($ref . ' ' . ($d['status'] ?? '')),
            [
                'tracking_code' => $d['tracking_code'] ?? null,
                'amount' => $d['amount'] ?? null,
                'status' => $d['status'] ?? null,
            ]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexManualDeposit(array $d): void
    {
        $id = int_value($d['deposit_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = int_value($d['user_id'] ?? 0);
        $ref = str_value($d['tracking_code'] ?? $d['transaction_id'] ?? '');
        $this->dualScope('manual_deposit', $id, $userId, 'manual_deposits', $ref,
            'manual deposit ' . ($d['amount'] ?? ''),
            trim($ref . ' ' . ($d['status'] ?? '')),
            ['tracking_code' => $d['tracking_code'] ?? null, 'amount' => $d['amount'] ?? null, 'status' => $d['status'] ?? null]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexContent(array $d): void
    {
        $id = int_value($d['content_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('content', $id, $userId, 'content', str_value($d['title'] ?? ''),
            str_value($d['title'] ?? ''),
            trim(str_value($d['description'] ?? ''). ' ' . str_value($d['video_url'] ?? '')),
            ['title' => $d['title'] ?? null, 'status' => $d['status'] ?? null, 'platform' => $d['platform'] ?? null]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexVitrine(array $d): void
    {
        $id = int_value($d['id'] ?? $d['listing_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        // ویترین عمومی است: scope=module + قابل‌مشاهده برای مالک (seller)
        $this->indexer->index(
            'vitrine', $id,
            str_value($d['title'] ?? ''),
            trim(str_value($d['description'] ?? ''). ' ' . str_value($d['username'] ?? '')),
            [
                'title' => $d['title'] ?? null,
                'price_usdt' => $d['price_usdt'] ?? null,
                'status' => $d['status'] ?? null,
                'username' => $d['username'] ?? null,
            ],
            ($d['status'] ?? 'active') !== 'deleted',
            int_value($d['seller_id'] ?? 0)?: null,
            'module',
            'vitrines',
            str_value($d['username'] ?? ''));
    }

    /** @param array<string, mixed> $d */
    private function indexTicket(array $d): void
    {
        $id = int_value($d['ticket_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('ticket', $id, $userId, 'tickets', str_value($d['ticket_code'] ?? ''),
            str_value($d['subject'] ?? ''),
            trim(str_value($d['subject'] ?? ''). ' ' . str_value($d['status'] ?? '')),
            ['subject' => $d['subject'] ?? null, 'status' => $d['status'] ?? null, 'priority' => $d['priority'] ?? null]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexDirectMessage(array $d): void
    {
        $id = int_value($d['id'] ?? $d['message_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        // پیام مستقیم بین دو طرف؛ هر دو طرف باید بتوانند جستجو کنند → دو رکورد
        $senderId = int_value($d['sender_id'] ?? 0);
        $recipientId = int_value($d['recipient_id'] ?? 0);
        $message = str_value($d['message'] ?? '');

        foreach (array_filter([$senderId, $recipientId]) as $ownerId) {
            $this->indexer->index(
                'direct_message',
                // entity_id منحصربه‌فرد per owner تا UNIQUE(entity_type, entity_id) نشکند
                (int)($id * 10 + ($ownerId === $senderId ? 1 : 2)),
                'message',
                $message,
                ['message_id' => $id, 'sender_id' => $senderId, 'recipient_id' => $recipientId],
                true,
                $ownerId,
                'user',
                'direct_messages',
                null
            );
        }
    }

    /** @param array<string, mixed> $d */
    private function indexInfluencer(array $d): void
    {
        $id = int_value($d['profile_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $this->indexer->index(
            'influencer', $id,
            str_value($d['username'] ?? ''),
            trim(str_value($d['bio'] ?? ''). ' ' . str_value($d['platform'] ?? ''). ' ' . str_value($d['page_url'] ?? '')),
            ['username' => $d['username'] ?? null, 'platform' => $d['platform'] ?? null, 'status' => $d['status'] ?? null],
            ($d['status'] ?? 'active') !== 'deleted',
            int_value($d['user_id'] ?? 0)?: null,
            'module',
            'influencers',
            str_value($d['username'] ?? ''));
    }

    /** @param array<string, mixed> $d */
    private function indexSocialTask(array $d): void
    {
        $id = int_value($d['execution_id'] ?? $d['task_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = int_value($d['user_id'] ?? 0);
        $title = str_value($d['title'] ?? 'Social Task');
        $platform = str_value($d['platform'] ?? '');
        $status = str_value($d['status'] ?? '');
        
        $this->dualScope(
            'social_task', $id, $userId, 'social_tasks', $platform, 
            $title, 
            trim($title . ' ' . $platform . ' ' . $status),
            ['platform' => $platform, 'status' => $status, 'title' => $title]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexCryptoDeposit(array $d): void
    {
        $id = int_value($d['deposit_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = int_value($d['user_id'] ?? 0);
        $txHash = str_value($d['tx_hash'] ?? $d['transaction_hash'] ?? '');
        $wallet = str_value($d['wallet_address'] ?? $d['address'] ?? '');
        $network = str_value($d['network'] ?? '');
        $amount = str_value($d['amount'] ?? '');
        
        $this->dualScope(
            'crypto_deposit', $id, $userId, 'crypto_deposits', $txHash, 
            'Crypto Deposit ' . $amount, 
            trim($txHash . ' ' . $wallet . ' ' . $network),
            ['tx_hash' => $txHash, 'wallet_address' => $wallet, 'network' => $network, 'amount' => $amount]
        );
    }

    /** @param array<string, mixed> $d */
    private function indexTicketMessage(array $d): void
    {
        $id = int_value($d['message_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = int_value($d['user_id'] ?? 0);
        $ticketId = int_value($d['ticket_id'] ?? 0);
        $content = str_value($d['content'] ?? $d['message'] ?? '');
        
        $this->dualScope(
            'ticket_message', $id, $userId, 'ticket_messages', (string)$ticketId, 
            'Ticket Message #' . $ticketId, 
            $content,
            ['ticket_id' => $ticketId]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * نمایه‌گذاری در scope=user (مالک) — رکورد متعلق به یک کاربر.
     */
    /** @param array<string, mixed> $metadata */
    private function dualScope(
        string $entityType,
        int $entityId,
        int $ownerId,
        string $module,
        ?string $ref,
        string $title,
        string $content,
        array $metadata
    ): void {
        if ($entityId <= 0) {
            return;
        }
        $this->indexer->index(
            $entityType,
            $entityId,
            $title !== '' ? $title : $entityType,
            $content,
            $metadata,
            true,
            $ownerId > 0 ? $ownerId : null,
            'user',
            $module,
            $ref
        );
    }

    private function deactivate(string $entityType, int|string $entityId): void
    {
        $id = (int)$entityId;
        if ($id > 0) {
            $this->indexer->deactivate($entityType, $id);
        }
    }

    private function deactivateOwner(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        // غیرفعال‌سازی نرم تمام projectionهای کاربر حذف‌شده
        try {
            $database = $this->db;
            $database->execute(
                "UPDATE search_projections SET is_active = 0, updated_at = NOW() WHERE owner_id = ?",
                [$userId]
            );
            $this->logger->info('search.projection.owner_deactivated', ['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.owner_deactivation_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function normalize(string|object $event, array $data): array
    {
        if (is_object($event)) {
            $eventData = method_exists($event, 'getData') ? (array)$event->getData() : [];
            if (method_exists($event, 'getName')) {
                return [(string)$event->getName(), $eventData];
            }
            $ref = new \ReflectionClass($event);
            $short = preg_replace('/Event$/', '', $ref->getShortName());
            $name = strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1.$2', (string)$short));
            return [$name, $eventData];
        }

        return [$event, $data];
    }

    /** @param array<string, mixed> $d */
    private function indexPrediction(array $d): void
    {
        $id = int_value($d['id'] ?? $d['game_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('prediction', $id, $userId, 'predictions', null, str_value($d['title'] ?? 'Prediction Game'), str_value($d['description'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexLottery(array $d): void
    {
        $id = int_value($d['id'] ?? $d['round_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('lottery', $id, $userId, 'lotteries', null, str_value($d['title'] ?? 'Lottery Round'), str_value($d['description'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexInvestment(array $d): void
    {
        $id = int_value($d['id'] ?? $d['investment_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('investment', $id, $userId, 'investments', null, 'Investment ' . ($d['amount'] ?? ''), str_value($d['status'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexBankCard(array $d): void
    {
        $id = int_value($d['id'] ?? $d['card_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('bank_card', $id, $userId, 'bank_cards', null, 'Bank Card', str_value($d['bank_name'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexKyc(array $d): void
    {
        $id = int_value($d['id'] ?? $d['kyc_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('kyc', $id, $userId, 'kyc', null, 'KYC Submission', str_value($d['status'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexEscrow(array $d): void
    {
        $id = int_value($d['id'] ?? $d['escrow_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('escrow', $id, $userId, 'escrows', null, 'Escrow #' . $id, str_value($d['status'] ?? ''), $d);
    }

    /** @param array<string, mixed> $d */
    private function indexCoupon(array $d): void
    {
        $id = int_value($d['id'] ?? $d['coupon_id'] ?? 0);
        if ($id <= 0) return;
        $userId = int_value($d['user_id'] ?? 0);
        $this->dualScope('coupon', $id, $userId, 'coupons', str_value($d['code'] ?? ''), 'Coupon ' . ($d['code'] ?? ''), str_value($d['description'] ?? ''), $d);
    }
}
