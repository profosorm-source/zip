<?php

declare(strict_types=1);

namespace App\Services\Influencer;

use App\Contracts\WalletServiceInterface;
use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\ValueObjects\Money;
use App\Services\Settings\AppSettings;
use App\Enums\ModuleContext;

/**
 * سرویس Command اینفلوئنسر — عملیات نوشتن (ثبت، سفارش، تسویه، ...)
 */
class InfluencerCommandService
{
    const SYSTEM_ACTOR_ID = -1;

    private InfluencerModel $profileModel;
    private StoryOrder $orderModel;
    private WalletServiceInterface $walletService;
    private AppSettings $appSettings;
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;
    private ?\App\Domain\Financial\Services\FinancialEscrowService $escrowService = null;
    private Database $db;
    private LoggerInterface $logger;
    private ?\Core\TransactionWrapper $transactionWrapper;
    private ?\App\Services\Interaction\RatingService $ratingService = null;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        InfluencerModel $profileModel,
        StoryOrder $orderModel,
        WalletServiceInterface $walletService,
        AppSettings $appSettings,
        \App\Domain\Financial\Services\FinancialEscrowService $escrowService,
        \Core\TransactionWrapper $transactionWrapper,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->profileModel = $profileModel;
        $this->orderModel = $orderModel;
        $this->walletService = $walletService;
        $this->appSettings = $appSettings;
        $this->outboxService = $outboxService;
        $this->escrowService = $escrowService;
        $this->transactionWrapper = $transactionWrapper;
    }

    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        if ($this->transactionWrapper === null) {
            throw new \RuntimeException('TransactionWrapper must be injected into InfluencerCommandService');
        }
        return $this->transactionWrapper;
    }

    private function getEscrowService(): \App\Domain\Financial\Services\FinancialEscrowService
    {
        if ($this->escrowService === null) {
            throw new \RuntimeException('FinancialEscrowService must be injected into InfluencerCommandService');
        }
        return $this->escrowService;
    }

    /** @param array<string, mixed> $payload */
    private function recordOutbox(string $aggregateType, int|string $aggregateId, string $eventType, array $payload): bool
    {
        // Null-outbox is a supported fallback mode (see the direct-deposit branches in
        // createOrder/refundOrder). Throwing here is dangerous: several callers invoke
        // recordOutbox AFTER a money movement has already been committed (e.g. the
        // post-transaction 'influencer.order_refunded' event and the respondToOrder reject
        // path). A throw at that point would surface a false failure for an operation that
        // actually succeeded, risking retries. Degrade gracefully instead.
        if ($this->outboxService === null) {
            $this->logger->warning('influencer.outbox_unavailable', [
                'aggregate_type' => $aggregateType,
                'aggregate_id'   => $aggregateId,
                'event_type'     => $eventType,
            ]);
            return false;
        }

        return $this->outboxService->record($aggregateType, $aggregateId, $eventType, $payload);
    }

    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     * Guarantees ?object from any DB result (array/object/mixed).
     * Callers: $x = $this->toObject($model->find($id)); if (!$x) { return error; }
        if (!$x) { return null; }
     * Then safe: $x->status , $x->user_id etc.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        /** @var \stdClass $obj */
        $obj = is_object($data) ? $data : (object)(is_array($data) ? $data : (array)$data);
        return $obj;
    }

    private function influencerEnabled(): bool
    {
        try {
            $value = $this->toObject($this->db->fetchColumn("SELECT `value` FROM system_settings WHERE `key` = 'influencer_enabled' LIMIT 1"));
            if ($value !== false && $value !== null) {
                return !in_array(strtolower((string)($value->value ?? $value)), ['0', 'false', 'off', 'no'], true);
            }
        } catch (\Throwable $ignored) {}
        return (bool)$this->appSettings->get('influencer_enabled', 1);
    }

    /** @param array<string, mixed> $data
     *  @return array{success: bool, message: string, profile?: \stdClass, verification_code?: string} */
    public function registerInfluencer(int $userId, array $data): array
    {
        if (!$this->influencerEnabled()) {
            return ['success' => false, 'message' => 'سیستم تبلیغات غیرفعال است.'];
        }

        $existing = $this->profileModel->findByUserId($userId);
        if ($existing) {
            return ['success' => false, 'message' => 'شما قبلاً یک پیج ثبت کرده‌اید.'];
        }

        $minFollowersValue = $this->appSettings->get('influencer_min_followers', 1000);
        $minFollowers = (int)(is_numeric($minFollowersValue) ? $minFollowersValue : 1000);
        if ((int)(is_numeric($data['follower_count'] ?? null) ? $data['follower_count'] : 0)< $minFollowers) {
            return ['success' => false, 'message' => "حداقل فالوور مورد نیاز: {$minFollowers}"];
        }

        $verificationCode = 'CK-' . \strtoupper(\substr(\md5(\random_bytes(16)), 0, 8));

        $profile = $this->profileModel->createProfile(\array_merge($data, [
            'user_id'           => $userId,
            'currency'          => $this->appSettings->get('currency_mode', 'irt'),
            'status'            => 'pending',
            'verification_code' => $verificationCode,
        ]));

        if (!$profile) {
            return ['success' => false, 'message' => 'خطا در ثبت پیج.'];
        }

        $this->recordOutbox('influencer', $userId, 'influencer.profile_registered', [
            'user_id' => $userId,
            'profile_id' => $profile->id,
            'username' => $profile->username,
        ]);

        return [
            'success'           => true,
            'message'           => 'پیج ثبت شد. کد تایید را در پیج خود منتشر کنید.',
            'profile'           => $profile,
            'verification_code' => $verificationCode,
        ];
    }

    /** @return array<string, mixed> */
    public function submitVerificationPost(int $userId, string $postUrl): array
    {
        $profile = $this->toObject($this->profileModel->findByUserId($userId));
        if (!$profile) {
            return ['success' => false, 'message' => 'پروفایل یافت نشد.'];
        }
        if (!\in_array($profile->status, ['pending', 'rejected'])) {
            return ['success' => false, 'message' => 'وضعیت پروفایل اجازه این عملیات را نمی‌دهد.'];
        }

        $this->profileModel->update((int)$profile->id, [
            'verification_post_url' => $postUrl,
            'status'                => 'pending_admin_review',
        ]);

        $verificationId = null;
        try {
            $existing = $this->toObject($this->db->fetch("SELECT id FROM influencer_verifications WHERE profile_id = ? AND status IN ('pending','submitted') ORDER BY id DESC LIMIT 1", [(int)$profile->id]));
            if ($existing) {
                $this->db->query("UPDATE influencer_verifications SET post_url = ?, proof_data = ?, status = 'pending', submitted_at = NOW(), updated_at = NOW() WHERE id = ?", [
                    $postUrl,
                    json_encode(['post_url' => $postUrl], JSON_UNESCAPED_UNICODE),
                    (int)$existing->id,
                ]);
                $verificationId = (int)$existing->id;
            } else {
                $this->db->query("INSERT INTO influencer_verifications (influencer_id, profile_id, verification_type, proof_data, post_url, status, submitted_at, created_at) VALUES (?, ?, 'post', ?, ?, 'pending', NOW(), NOW())", [
                    (int)$profile->id,
                    (int)$profile->id,
                    json_encode(['post_url' => $postUrl], JSON_UNESCAPED_UNICODE),
                    $postUrl,
                ]);
                $verificationId = $this->db->lastInsertId();
            }
        } catch (\Throwable $e) {
            $this->logger->error('influencer.verification_record_failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
        }

        $this->recordOutbox('influencer', $userId, 'influencer.verification_submitted', [
            'user_id' => $userId,
            'profile_id' => $profile->id,
            'post_url' => $postUrl,
        ]);

        return ['success' => true, 'message' => 'لینک پست ثبت شد. منتظر بررسی مدیر باشید.', 'verification_id' => $verificationId];
    }

    // ══════════════════════════════════════════════════════
    //  ثبت سفارش با Escrow
    // ══════════════════════════════════════════════════════


    /** @param array<string, mixed> $data
     *  @return array{success: bool, message: string, order?: \stdClass} */
    public function createOrder(int $customerId, int $influencerId, array $data): array
    {
        // Fraud guard check before financial operations
        assert_fraud_allowed($customerId, 'influencer_order_create', ['influencer_id' => $influencerId]);

        if (!$this->influencerEnabled()) {
            return ['success' => false, 'message' => 'سیستم غیرفعال است.'];
        }

        $recentOrders = $this->countRecentOrders($customerId, 1);
        $maxPerHour   = (int)(is_numeric($this->appSettings->get('influencer_order_rate_limit_per_hour', 5)) ? $this->appSettings->get('influencer_order_rate_limit_per_hour', 5) : 5);
        if ($recentOrders >= $maxPerHour) {
            return ['success' => false, 'message' => 'تعداد سفارش در ساعت به حداکثر رسیده است.'];
        }

        $profile = $this->toObject($this->profileModel->find($influencerId));
        if (!$profile || !isset($profile->id) || $profile->status !== 'verified' || !(int)$profile->is_active) {
            return ['success' => false, 'message' => 'اینفلوئنسر فعال نیست.'];
        }
        if ((int)$profile->user_id === $customerId) {
            return ['success' => false, 'message' => 'نمی‌توانید برای پیج خودتان سفارش دهید.'];
        }

        $orderType = (string)(is_scalar($data['order_type'] ?? null) ? $data['order_type'] : 'story');
        $duration  = (int)(is_numeric($data['duration_hours'] ?? null) ? $data['duration_hours'] : 24);
        $price     = $this->calculatePrice($profile, $orderType, $duration);

        if (bccomp($price, '0', 8) <= 0) {
            return ['success' => false, 'message' => 'قیمت نامعتبر است.'];
        }

        // float→decimal: کارمزد و درآمد اینفلوئنسر با Money/BCMath به‌جای float
        $currency          = is_string($profile->currency ?? null) ? $profile->currency : 'irt';
        $feeValue          = $this->appSettings->get('influencer_fee_percent', 15);
        $feePercent        = is_numeric($feeValue) ? (string)$feeValue : '15';
        $priceMoney        = Money::fromString($price, $currency);
        $feeAmount         = $priceMoney->percentage($feePercent)->getAmount();
        $influencerEarning = $priceMoney->subtract(Money::fromString($feeAmount, $currency))->getAmount();
        $idempotencyData   = $data;
        ksort($idempotencyData);
        // اصلاح کلیدی معماری چندسکویی (Cross-Platform Idempotency): مرتب‌سازی کلیدها و استفاده از JSON به جای serialize جهت جلوگیری از شکست مکانیزم ایدمپوتنس در کلاینت‌های موبایل
        $idempotencyKey    = "influencer_order_{$customerId}_{$influencerId}_" . \md5((string)\json_encode($idempotencyData, JSON_UNESCAPED_UNICODE));

        try {
            $order = null;
            $this->getTransactionWrapper()->runWithRetry(function() use ($customerId, $influencerId, $profile, $orderType, $duration, $price, $feeAmount, $feePercent, $influencerEarning, $data, $idempotencyKey, &$order) {
                
                $order = $this->orderModel->createStoryOrder([
                    'customer_id'            => $customerId,
                    'influencer_id'          => $influencerId,
                    'influencer_user_id'     => (int)$profile->user_id,
                    'order_type'             => $orderType,
                    'duration_hours'         => $duration,
                    'media_path'             => $data['media_path'] ?? null,
                    'caption'                => $data['caption'] ?? null,
                    'link'                   => $data['link'] ?? null,
                    'preferred_publish_time' => !empty($data['preferred_publish_time']) ? $data['preferred_publish_time'] : null,
                    'verification_code'      => $this->orderModel->generateVerificationCode(),
                    'price'                  => $price,
                    'currency'               => $profile->currency,
                    'site_fee_percent'       => $feePercent,
                    'site_fee_amount'        => $feeAmount,
                    'influencer_earning'     => $influencerEarning,
                    'status'                 => 'pending_acceptance',
                    'payment_transaction_id' => null,
                    'idempotency_key'        => $idempotencyKey,
                ]);

                if (!$order) {
                    throw new \Core\Exceptions\ApplicationException('خطا در ثبت سفارش.');
                }

                $escrow = $this->getEscrowService();
                $holdResult = $escrow->holdInfluencerOrderFunds(
                    (int)$order->id,
                    $customerId,
                    (int)$profile->user_id,
                    (string)$price,
                    $idempotencyKey . ':hold'
                );
                if (empty($holdResult['ok'])) {
                    $holdError = $holdResult['error'] ?? null;
                    $message = is_string($holdError) ? $holdError : 'خطا در نگهداری امن مبلغ سفارش';
                    if (str_contains($message, 'Insufficient') || str_contains($message, 'موجودی')) {
                        throw new \Core\Exceptions\InsufficientBalanceException('موجودی کافی نیست.');
                    }
                    throw new \Core\Exceptions\ApplicationException($message);
                }
                $confirmResult = $escrow->confirmInfluencerOrderFunds(
                    (int)$order->id,
                    (int)$profile->user_id,
                    $idempotencyKey . ':confirm'
                );
                if (empty($confirmResult['ok'])) {
                    $confirmError = $confirmResult['error'] ?? null;
                    throw new \Core\Exceptions\ApplicationException(
                        is_string($confirmError) ? $confirmError : 'خطا در تأیید امانت سفارش'
                    );
                }
                $escrowId = $holdResult['escrow_id'] ?? $confirmResult['escrow_id'] ?? null;
                if (!is_int($escrowId) || $escrowId <= 0) {
                    throw new \Core\Exceptions\ApplicationException('شناسه صندوق امانات سفارش معتبر نیست');
                }
                $this->orderModel->update((int)$order->id, [
                    'payment_transaction_id' => 'escrow:' . $escrowId,
                    'metadata' => json_encode(['escrow_id' => $escrowId], JSON_UNESCAPED_UNICODE),
                ]);

                // 🚀 Side effects moved to central listener
                $this->recordOutbox('influencer_order', $order->id, 'influencer.order_created', [
                    'order_id'           => $order->id,
                    'customer_id'        => $customerId,
                    'influencer_user_id' => (int)$profile->user_id,
                    'price'              => $price,
                    'currency'           => $profile->currency,
                    'order_type'         => $orderType
                ]);
                $this->profileModel->update($influencerId, [
                    'total_orders' => (int)$profile->total_orders + 1,
                ]);
            });

            if ($order === null) {
                return ['success' => false, 'message' => 'خطا در ثبت سفارش.'];
            }
            return ['success' => true, 'message' => 'سفارش ثبت و مبلغ در صندوق امانی قفل شد.', 'order' => $order];

        } catch (\Exception $e) {
            $this->logger->error('story.order_create_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $customerId, [
                'operation'     => 'influencer.createOrder',
                'influencer_id' => $influencerId,
            ]);
            return ['success' => false, 'message' => in_array($e->getMessage(), ['موجودی کافی نیست.', 'خطا در ثبت سفارش.']) ? $e->getMessage() : 'خطای سیستمی در ثبت سفارش.'];
        }
    }

    // ══════════════════════════════════════════════════════
    //  پذیرش / رد سفارش توسط اینفلوئنسر
    // ══════════════════════════════════════════════════════


    /** @return array{success: bool, message: string} */
    public function respondToOrder(int $orderId, int $influencerUserId, string $decision, ?string $reason = null): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order || !isset($order->id) || (int)$order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!\in_array($order->status, ['pending', 'paid', 'pending_acceptance'], true)) {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }

        if ($decision === 'accept') {
            $this->orderModel->update($orderId, ['status' => 'accepted']);
            $this->recordOutbox('influencer_order', $orderId, 'influencer.order_accepted', [
                'order_id'           => $orderId,
                'customer_id'        => (int)$order->customer_id,
                'influencer_user_id' => $influencerUserId
            ]);
            return ['success' => true, 'message' => 'سفارش پذیرفته شد.'];
        }

        $this->orderModel->update($orderId, [
            'status'           => 'rejected_by_influencer',
            'rejection_reason' => $reason ?? 'رد توسط اینفلوئنسر',
        ]);

        $this->refundCustomer($order, 'rejected_by_influencer');
        
        $this->recordOutbox('influencer_order', $orderId, 'influencer.order_rejected', [
            'order_id'           => $orderId,
            'customer_id'        => (int)$order->customer_id,
            'influencer_user_id' => $influencerUserId,
            'points'             => (int)(is_numeric($this->appSettings->get('influencer_rep_reject_points', -3)) ? $this->appSettings->get('influencer_rep_reject_points', -3) : -3)
        ]);

        return ['success' => true, 'message' => 'سفارش رد شد و مبلغ به تبلیغ‌دهنده بازگشت.'];
    }

    // ══════════════════════════════════════════════════════
    //  ارسال مدرک → نوتیف فوری به buyer
    // ══════════════════════════════════════════════════════


    /** @param array<string, mixed> $proofData
     *  @return array{success: bool, message: string} */
    public function submitProof(int $orderId, int $influencerUserId, array $proofData): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order || !isset($order->id) || (int)$order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!in_array($order->status, ['accepted', 'published'])) {
            return ['success' => false, 'message' => 'وضعیت سفارش مناسب نیست.'];
        }

        $buyerCheckHours    = (int)(is_numeric($this->appSettings->get('influencer_buyer_check_hours', 24)) ? $this->appSettings->get('influencer_buyer_check_hours', 24) : 24);
        $buyerCheckDeadline = date('Y-m-d H:i:s', (strtotime("+{$buyerCheckHours} hours") ?: time()));
        $now                = date('Y-m-d H:i:s');

        $updateData = [
            'status'                  => 'awaiting_buyer_check',
            'proof_submitted_at'      => $now,
            'buyer_check_notified_at' => $now,
            'buyer_check_deadline'    => $buyerCheckDeadline,
        ];
        if (!empty($proofData['proof_screenshot'])) $updateData['proof_screenshot'] = $proofData['proof_screenshot'];
        if (!empty($proofData['proof_link']))        $updateData['proof_link']        = $proofData['proof_link'];
        if (!empty($proofData['proof_notes']))       $updateData['proof_notes']       = $proofData['proof_notes'];

        $this->orderModel->update($orderId, $updateData);

        $this->outboxService?->record('influencer_order', $orderId, 'influencer.proof_submitted', [
            'order_id'           => $orderId,
            'customer_id'        => (int)$order->customer_id,
            'influencer_user_id' => $influencerUserId,
            'deadline'           => $buyerCheckDeadline
        ]);

        return ['success' => true, 'message' => 'مدرک ثبت شد و به تبلیغ‌دهنده اطلاع‌رسانی شد.'];
    }

    /** @return array{success: bool, message: string, data?: array<string, mixed>} */
    public function buyerConfirm(int $orderId, int $customerId): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order || !isset($order->id) || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ((string)$order->status === 'completed') {
            return ['success' => true, 'message' => 'این سفارش قبلاً تأیید و تسویه شده است.', 'data' => ['already_completed' => true]];
        }
        if (!in_array($order->status, ['awaiting_buyer_check', 'proof_submitted'], true)) {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }
        return $this->completeOrder((int)$order->id, $customerId, 'buyer_confirmed');
    }

    /** @return array{success: bool, message: string, order_id?: int} */
    public function buyerDispute(int $orderId, int $customerId, string $reason): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order || !isset($order->id) || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!in_array($order->status, ['awaiting_buyer_check', 'proof_submitted'], true)) {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }
        if ($this->countRecentDisputes($customerId, 24) >= (int)(is_numeric($this->appSettings->get('influencer_dispute_rate_limit', 3)) ? $this->appSettings->get('influencer_dispute_rate_limit', 3) : 3)) {
            return ['success' => false, 'message' => 'تعداد اعتراض در روز به حداکثر رسیده است.'];
        }

        $this->orderModel->update($orderId, [
            'status'                     => 'dispute',
            'peer_resolution_started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $escrowResult = $this->getEscrowService()->markEscrowDisputed($orderId, 'influencer_order', $reason, 'influencer_dispute_' . $orderId);
            if (empty($escrowResult['ok'])) {
                $this->logger->warning('influencer.dispute_escrow_mark_failed', ['order_id' => $orderId, 'error' => $escrowResult['error'] ?? null]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('influencer.dispute_escrow_mark_exception', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        try {
            $existingDispute = $this->toObject($this->db->fetch("SELECT id FROM disputes WHERE ref_type = 'influencer_order' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$orderId]));
            if (!$existingDispute) {
                $this->db->query("INSERT INTO disputes (ref_type, ref_id, user_id, target_user_id, status, reason, created_at, updated_at) VALUES ('influencer_order', ?, ?, ?, 'open_peer', ?, NOW(), NOW())", [
                    $orderId,
                    $customerId,
                    (int)$order->influencer_user_id,
                    $reason,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('influencer.dispute_record_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        $this->outboxService?->record('influencer_order', $orderId, 'influencer.dispute_opened', [
            'order_id'           => $orderId,
            'customer_id'        => $customerId,
            'influencer_user_id' => (int)$order->influencer_user_id,
            'reason'             => $reason
        ]);

        return ['success' => true, 'message' => 'اعتراض ثبت شد.', 'order_id' => $orderId];
    }
    /** @return array{success: bool, message: string, data?: array<string, mixed>} */
    public function completeOrder(int $orderId, int $actorId, string $reason = 'completed'): array
{
    $order = $this->toObject($this->orderModel->find($orderId));
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }
    if ((string)$order->status === 'completed') {
        return ['success' => true, 'message' => 'این سفارش قبلاً تکمیل و تسویه شده است.', 'data' => ['already_completed' => true]];
    }
    if (in_array((string)$order->status, ['refunded', 'rejected_by_influencer', 'cancelled', 'expired'], true)) {
        return ['success' => false, 'message' => 'این سفارش قابل تسویه نیست.'];
    }

    // ✅ FIX: تشخیص اینکه عملیات توسط سیستم انجام شده یا ادمین
    $isSystemAction = ($actorId === self::SYSTEM_ACTOR_ID || $actorId === 0);
    $actorType = $isSystemAction ? 'system' : 'admin';

    try {
        $this->getTransactionWrapper()->runWithRetry(function() use ($order, $orderId) {
            $payoutResult = $this->getEscrowService()->releaseInfluencerOrderFunds(
                (int)$orderId,
                (int)$order->influencer_user_id,
                (string)$order->influencer_earning,
                "story_release_{$orderId}"
            );
            if (empty($payoutResult['ok'])) {
                throw new \Core\Exceptions\ApplicationException('خطا در پرداخت به اینفلوئنسر.');
            }
            $this->orderModel->update((int)$orderId, [
                'status'                => 'completed',
                'buyer_confirmed_at'    => date('Y-m-d H:i:s'),
                'payout_transaction_id' => $payoutResult['transaction_id'] ?? null,
            ]);
        });

        $this->recordOutbox('influencer_order', $orderId, 'influencer.order_completed', [
            'order_id'           => $orderId,
            'influencer_user_id' => (int)$order->influencer_user_id,
            'influencer_id'      => (int)$order->influencer_id,
            'amount'             => $order->influencer_earning,
            'actor_id'           => $actorId,
            'actor_type'         => $actorType,
            'points'             => (int)(is_numeric($this->appSettings->get('influencer_rep_complete_points', 10)) ? $this->appSettings->get('influencer_rep_complete_points', 10) : 10)
        ]);

        return ['success' => true, 'message' => 'سفارش تکمیل و درآمد واریز شد.'];
    } catch (\Exception $e) {
        $this->logger->error('story.complete_order_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, $actorId, [
            'operation' => 'influencer.completeOrder',
            'order_id'  => $orderId,
        ]);
        return ['success' => false, 'message' => $e->getMessage() === 'خطا در پرداخت به اینفلوئنسر.' ? $e->getMessage() : 'خطای سیستمی در تسویه.'];
    }
}

    // ══════════════════════════════════════════════════════
    //  بازگشت وجه (کامل یا جزئی)
    // ══════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    public function refundOrder(int $orderId, int $actorId, float $refundPercent = 100.0, string $reason = ''): array
{
    $order = $this->toObject($this->orderModel->find($orderId));
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }

    // float→decimal: مبلغ بازگشت با Money/BCMath به‌جای float
    $currency     = is_string($order->currency ?? null) ? $order->currency : 'irt';
    $orderPrice   = is_numeric($order->price ?? null) ? (string)$order->price : '0';
    $refundAmount = Money::fromString($orderPrice, $currency)->percentage((string)$refundPercent)->getAmount();

    try {
        $this->getTransactionWrapper()->runWithRetry(function() use ($order, $orderId, $refundAmount, $refundPercent, $reason, $actorId) {
            // Refund customer
            $refundPayload = [
                'user_id' => (int)$order->customer_id,
                'amount' => $refundAmount,
                'currency' => $order->currency,
                'metadata' => [
                    'type' => 'refund',
                    'description' => "بازگشت سفارش #{$orderId}",
                    'idempotency_key' => "story_refund_{$orderId}",
                    'order_id' => $orderId,
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->recordOutbox('influencer_order', $orderId, \App\Events\Registry\EventRegistry::INFLUENCER_ORDER_REFUNDED, $refundPayload);
                if (!$ok) {
                    throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد خروجی بازگشت وجه.');
                }
            } else {
                $refundResult = $this->walletService->deposit(
                    (int)$order->customer_id,
                    (string)$refundAmount,
                    $order->currency,
                    ['type' => 'refund', 'description' => "بازگشت سفارش #{$orderId}", 'idempotency_key' => "story_refund_{$orderId}"]
                );
                if (!($refundResult['success'] ?? false)) {
                    throw new \Core\Exceptions\ApplicationException('خطا در بازگشت وجه.');
                }
            }

            if ($refundPercent < 100) {
                // float→decimal: باقی‌مانده و سهم اینفلوئنسر با Money/BCMath به‌جای float
                $feeValue = $this->appSettings->get('influencer_fee_percent', 15);
                $feePercent = is_numeric($feeValue) ? (string)$feeValue : '15';
                $orderCurrency = is_string($order->currency ?? null) ? $order->currency : 'irt';
                $orderPrice = is_numeric($order->price ?? null) ? (string)$order->price : '0';
                $remainingAmount = Money::fromString($orderPrice, $orderCurrency)
                    ->subtract(Money::fromString($refundAmount, $orderCurrency))
                    ->getAmount();
                $remainingMoney = Money::fromString($remainingAmount, $orderCurrency);
                $influencerShare = $remainingMoney
                    ->subtract($remainingMoney->percentage($feePercent))
                    ->getAmount();

                if (bccomp($influencerShare, '0', 8) > 0) {
                    $partialPayload = [
                        'user_id' => (int)$order->influencer_user_id,
                        'amount' => $influencerShare,
                        'currency' => $order->currency,
                        'metadata' => [
                            'type' => 'partial_earning',
                            'description' => "درآمد جزئی سفارش #{$orderId}",
                            'idempotency_key' => "story_partial_{$orderId}",
                            'order_id' => $orderId,
                        ],
                    ];

                    if ($this->outboxService) {
                        $ok2 = $this->recordOutbox('influencer_order', $orderId, \App\Events\Registry\EventRegistry::INFLUENCER_ORDER_PARTIAL_REFUNDED, $partialPayload);
                        if (!$ok2) {
                            throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد خروجی پرداخت جزئی.');
                        }
                    } else {
                        $partialResult = $this->walletService->deposit(
                            (int)$order->influencer_user_id,
                            (string)$influencerShare,
                            $order->currency,
                            ['type' => 'partial_earning', 'description' => "درآمد جزئی سفارش #{$orderId}", 'idempotency_key' => "story_partial_{$orderId}"]
                        );
                        if (!($partialResult['success'] ?? false)) {
                            throw new \Core\Exceptions\ApplicationException('خطا در پرداخت جزئی به اینفلوئنسر.');
                        }
                    }
                }
            }

            $this->orderModel->update($orderId, [
                'status'      => $refundPercent >= 100 ? 'refunded' : 'partially_refunded',
                'admin_note'  => $reason,
                'reviewed_by' => $actorId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
        });

        // Replace direct cache/audit calls with domain event
        $this->recordOutbox('influencer_order', $orderId, 'influencer.order_refunded', [
            'order_id' => $orderId,
            'actor_id' => $actorId,
            'refund_percent' => $refundPercent,
            'amount' => $refundAmount,
            'reason' => $reason,
            'customer_id' => (int)$order->customer_id,
            'influencer_user_id' => (int)$order->influencer_user_id
        ]);

        return ['success' => true, 'message' => "بازگشت {$refundPercent}٪ وجه انجام شد."];

    } catch (\Exception $e) {
        $this->logger->error('story.refund_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, $actorId, [
            'operation'      => 'influencer.refundOrder',
            'order_id'       => $orderId,
            'refund_percent' => $refundPercent,
        ]);
        return ['success' => false, 'message' => in_array($e->getMessage(), ['خطا در بازگشت وجه.', 'خطا در پرداخت جزئی به اینفلوئنسر.']) ? $e->getMessage() : 'خطای سیستمی در بازگشت وجه.'];
    }
}


    // ══════════════════════════════════════════════════════
    //  CronJobs
    // ══════════════════════════════════════════════════════

    public function processExpiredBuyerChecks(): int
    {
        $expired = $this->orderModel->getExpiredBuyerChecks();
        $count = 0;
        foreach ($expired as $o) {
            $result = $this->completeOrder((int)$o->id, 0, 'auto_approved_buyer_timeout');
            if ($result['success']) {
                $count++;
                $this->logger->info('story.auto_approved', ['order_id' => $o->id]);
            }
        }
        return $count;
    }

    public function processExpiredPendingAcceptance(): int
    {
        $expired = $this->orderModel->getExpiredPendingAcceptance();
        $count   = 0;

        if (empty($expired)) {
            return 0;
        }

        // ✅ N+1 FIX: batch load همه influencer profiles در یک query
        // به جای findByUserId() داخل foreach
        $influencerUserIds = array_unique(array_filter(
            array_map(fn($o) => (int)($o->influencer_user_id ?? 0), $expired),
            fn($id) => $id > 0
        ));
        $profilesMap = \App\Services\Shared\BatchLoader::byIds(
            $this->db,
            'influencer_profiles',
            $influencerUserIds,
            'user_id'
        );
        // ────────────────────────────────────────────────────────────────

        $pts = (int)(is_numeric($this->appSettings->get('influencer_rep_reject_points', -3)) ? $this->appSettings->get('influencer_rep_reject_points', -3) : -3);

        foreach ($expired as $o) {
            $stmt = $this->db->prepare("UPDATE story_orders SET status = 'rejected_by_influencer', rejection_reason = 'عدم پاسخ در مهلت مقرر' WHERE id = ? AND status IN ('pending', 'paid', 'pending_acceptance')");
            $stmt->execute([(int)$o->id]);
            $affected = $stmt->rowCount() > 0;

            if (!$affected) {
                continue;
            }

            // استفاده از $o مستقیم — داده کافی دارد و نیازی به find() جداگانه نیست
            /** @var \stdClass $order */
            $order = $o;
            if (!$order) continue;

            $this->refundCustomer($order, 'influencer_no_response');

            // استفاده از map — بدون findByUserId() جداگانه
            $profile = $profilesMap[(int)($order->influencer_user_id ?? 0)] ?? null;
            if ($profile && $this->outboxService) {
                $this->recordOutbox('influencer_order', (int)$o->id, 'influencer.order_rejected', [
                    'order_id'           => (int)$o->id,
                    'influencer_id'      => (int)$profile->id,
                    'influencer_user_id' => (int)$order->influencer_user_id,
                    'customer_id'        => (int)($order->customer_id ?? 0),
                    'refund_amount'      => floatval($order->price ?? 0),
                    'currency'           => $order->currency ?? 'irt',
                    'reason'             => 'عدم پاسخ در مهلت مقرر',
                    'reputation_penalty' => abs($pts),
                ]);
            }

            $count++;
        }

        if ($count > 0) {
            $this->logger->info('influencer.auto_rejected_no_response', ['count' => $count]);
        }

        return $count;
    }


    public function cleanupOldFiles(int $days = 3): int
    {
        $stmt = $this->db->prepare("
            SELECT id, proof_screenshot, media_path FROM story_orders
            WHERE status IN ('completed','refunded','cancelled')
            AND updated_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (proof_screenshot IS NOT NULL OR media_path IS NOT NULL)
        ");
        $stmt->execute([$days]);
        $orders = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $count = 0;
        foreach ($orders as $o) {
            $this->cleanupProofFiles($o);
            $this->orderModel->update($o->id, ['proof_screenshot' => null, 'media_path' => null]);
            $count++;
        }
        return $count;
    }

    // ══════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════

    private function refundCustomer(\stdClass $order, string $reason = ''): void
{
    try {
        $result = $this->getEscrowService()->refundInfluencerOrderFunds(
            (int)$order->id,
            (int)$order->customer_id,
            $reason ?: 'influencer_order_refund',
            'story_refund_' . (int)$order->id
        );

        if (empty($result['ok'])) {
            throw new \Core\Exceptions\ApplicationException((is_string($result['error'] ?? null) ? $result['error'] : 'خطا در بازگشت وجه امانی رد شد'));
        }

        $this->orderModel->update((int)$order->id, [
            'status'     => 'refunded',
            'admin_note' => $reason,
        ]);

        $this->outboxService?->record('influencer_order', (int)$order->id, 'influencer.order_refunded_to_customer', [
            'order_id' => (int)$order->id,
            'customer_id' => (int)$order->customer_id,
            'refund_amount' => $result['refund_amount'] ?? $order->price,
        ]);
    } catch (\Exception $e) {
        $this->logger->error('story.refund_customer_failed', [
            'order_id' => $order->id,
            'error'    => $e->getMessage(),
        ]);
        throw $e;
    }
}


    private function calculatePrice(\stdClass $profile, string $orderType, int $duration): string
    {
        // float→decimal: قیمت به‌صورت رشتهٔ decimal دقیق (مطابق ستون DECIMAL) نگه داشته می‌شود
        $raw = $orderType === 'story'
            ? ($profile->story_price_24h ?? null)
            : match ($duration) {
                48      => $profile->post_price_48h ?? null,
                72      => $profile->post_price_72h ?? null,
                default => $profile->post_price_24h ?? null,
            };
        return is_numeric($raw) ? (string) $raw : '0';
    }

    private function cleanupProofFiles(object $order): void
    {
        $base = __DIR__ . '/../../';
        foreach (['proof_screenshot', 'media_path'] as $f) {
            if (!empty($order->$f) && \file_exists($base . $order->$f)) {
                \unlink($base . $order->$f);
            }
        }
    }

    private function countRecentOrders(int $customerId, int $hours): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM story_orders
            WHERE customer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$customerId, $hours]);
        return (int) $stmt->fetchColumn();
    }

    private function countRecentDisputes(int $customerId, int $hours): int
{
    // ✅ FIX: به جای جدول influencer_disputes که رکوردی توش ثبت نمیشه،
    // از خود جدول سفارش‌ها با شرط status استفاده می‌کنیم
    $stmt = $this->db->prepare("
        SELECT COUNT(*) FROM story_orders
        WHERE customer_id = ?
          AND status IN ('peer_resolution', 'dispute', 'refunded', 'partially_refunded')
          AND peer_resolution_started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$customerId, $hours]);
    return (int) $stmt->fetchColumn();
}

    /**
     * ثبت گزارش تخلف برای سفارش تبلیغ/پست یوتیوب
     */
    /** @return array<string, mixed> */
    public function reportOrder(int $reporterId, int $orderId, string $reason, string $description = ''): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد'];
        }

        try {
            $this->db->execute(
                "INSERT INTO task_reports (reporter_id, task_type, task_id, reason, description, status, created_at)
                 VALUES (:reporter_id, 'influencer_order', :task_id, :reason, :description, 'pending', NOW())",
                [
                    'reporter_id' => $reporterId,
                    'task_id' => $orderId,
                    'reason' => $reason,
                    'description' => $description
                ]
            );

            return ['success' => true, 'message' => 'گزارش تخلف با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $reporterId, [
                'operation' => 'influencer.reportOrder',
                'order_id'  => $orderId,
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    /**
     * امتیازدهی به اینفلوئنسر/سفارش استوری
     */
    /** @return array<string, mixed> */
    public function rateInfluencer(int $raterId, int $orderId, int $stars, string $comment = ''): array
    {
        $order = $this->toObject($this->orderModel->find($orderId));
        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            if (!$this->ratingService) {
                $this->ratingService = \Core\Container::getInstance()->make(\App\Services\Interaction\RatingService::class);
            }
            $ok = $this->ratingService->rate(
                $raterId,
                'story_order',
                $orderId,
                ModuleContext::CONTENT,
                $stars
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $raterId, [
                'operation' => 'influencer.rateInfluencer',
                'order_id'  => $orderId,
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

}
