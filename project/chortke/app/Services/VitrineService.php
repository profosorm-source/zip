<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Financial\Services\FinancialEscrowService;
use App\Contracts\NotificationServiceInterface;
use App\Services\User\UserService;
use App\Services\Settings\AppSettings;
use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Models\Notification;
use App\Services\AuditTrail;
use App\Services\Shared\ReferralService;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * @phpstan-type VitrinePayload array<string, mixed>
 * @phpstan-type VitrineFilters array<string, mixed>
 * @phpstan-type VitrineResult array{success: bool, message?: string, ...}
 * @phpstan-type VitrineRows list<\stdClass>
 * @phpstan-type ListingRow object{id: int|string, seller_id: int|string, status: string, price_usdt: int|float|string, category: string, platform: string, title: string, buyer_id?: int|string|null, offer_price_usdt?: int|float|string|null}
 * @phpstan-type RequestRow object{id: int|string, listing_id: int|string, requester_id: int|string, seller_id: int|string, status: string}
 */
class VitrineService
{
    private \Core\EventDispatcher $eventDispatcher;
    private VitrineListing $listing;
    private VitrineRequest $request;
    private FeatureFlagService $flags;
    private AppSettings $settings;
    private UserService $userService;
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private \App\Services\EscrowService $escrowService;
    private FinancialEscrowService $financialEscrow;
    private Database $db;
    private \Core\Container $container;
    private LoggerInterface $logger;

    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        VitrineListing $listing,
        VitrineRequest $request,
        FeatureFlagService $flags,
        AppSettings $settings,
        UserService $userService,
        \App\Services\EscrowService $escrowService,
        FinancialEscrowService $financialEscrow,
        Database $db,
        \Core\Container $container,
        LoggerInterface $logger,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->request = $request;
        $this->flags = $flags;
        $this->settings = $settings;
        $this->userService = $userService;
        $this->escrowService = $escrowService;
        $this->financialEscrow = $financialEscrow;
        $this->db = $db;
        $this->container = $container;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceClass
     * @return T
     */
    private function getService(string $serviceClass): object
    {
        $service = $this->container->make($serviceClass);
        if (!$service instanceof $serviceClass) {
            throw new \RuntimeException("Vitrine service binding {$serviceClass} is invalid");
        }
        return $service;
    }

    /** @return VitrineResult */
    private function normalizeVitrineResult(mixed $result, string $fallback = 'عملیات ویترین ناموفق بود'): array
    {
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Vitrine command must return an associative result');
        }
        foreach (array_keys($result) as $key) {
            if (!is_string($key)) throw new \UnexpectedValueException('Vitrine result must use string keys');
        }
        $result['success'] = (bool)($result['success'] ?? false);
        if (!isset($result['message']) || !is_string($result['message'])) $result['message'] = $fallback;
        /** @var VitrineResult $result */
        return $result;
    }

    /** @return \stdClass */
    private function requireListingRow(object $listing): \stdClass
    {
        $values = get_object_vars($listing);
        foreach (['id', 'seller_id', 'status', 'price_usdt', 'category', 'platform', 'title'] as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
                throw new \UnexpectedValueException("Invalid Vitrine listing row: {$key}");
            }
        }
        if (array_key_exists('buyer_id', $values) && $values['buyer_id'] !== null && !is_scalar($values['buyer_id'])) {
            throw new \UnexpectedValueException('Invalid Vitrine listing buyer_id');
        }
        if (array_key_exists('offer_price_usdt', $values) && $values['offer_price_usdt'] !== null && !is_scalar($values['offer_price_usdt'])) {
            throw new \UnexpectedValueException('Invalid Vitrine listing offer price');
        }
        /** @var \stdClass $listing */
        return $listing;
    }

    /** @return RequestRow */
    private function requireRequestRow(object $request): object
    {
        $values = get_object_vars($request);
        foreach (['id', 'listing_id', 'requester_id', 'seller_id', 'status'] as $key) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
                throw new \UnexpectedValueException("Invalid Vitrine request row: {$key}");
            }
        }
        /** @var RequestRow $request */
        return $request;
    }


    public function isEnabled(): bool
    {
        return $this->flags->isEnabled('vitrine_enabled');
    }

    /** @return array{ok: true}|array{ok: false, message: string} */
    public function canTrade(int $userId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'سرویس ویترین در حال حاضر غیرفعال است.'];
        }

        $kycSetting = $this->settings->get('vitrine_kyc_required', '1');
        $kycRequired = is_scalar($kycSetting) && (int)$kycSetting === 1;
        if ($kycRequired && !$this->userService->isKycVerified($userId)) {
            return ['ok' => false, 'message' => 'برای استفاده از ویترین ابتدا باید احراز هویت (KYC) را تکمیل کنید.'];
        }

        if ($this->userService->isBlacklisted($userId)) {
            return ['ok' => false, 'message' => 'حساب شما محدود شده است. با پشتیبانی تماس بگیرید.'];
        }

        return ['ok' => true];
    }

    /**
         * @param VitrineFilters $filters
         * @return VitrinePayload
         */
    public function getListings(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->getActive($filters, $perPage, $offset),
            'total'      => $this->listing->countActive($filters),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    /**
         * @param VitrineFilters $filters
         * @return VitrinePayload
         */
    public function getWantedListings(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->getWantedListings($filters, $perPage, $offset),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    /**
     * دریافت امن یک آگهی بدون فیلتر وضعیت — مورد استفاده‌ی پنل ادمین
     * (admin باید بتواند آگهی در هر وضعیتی را ببیند، برخلاف getListingDetails).
     */
    public function getSafe(int $id): ?\stdClass
    {
        $l = $this->listing->find($id);
        if (!$l || !isset($l->id)) { return null; }
        return $l;
    }

    /** @return VitrinePayload|null */
    public function getListingDetails(int $id, int $userId): ?array
    {
        $listing = $this->listing->find($id);
        if (!$listing) return null;
        $listing = $this->requireListingRow($listing);

        if ($listing->status !== 'active' &&
            (int)$listing->seller_id !== $userId &&
            !is_admin()) {
            return null;
        }

        $isSeller = (int) $listing->seller_id === $userId;
        
        return [
            'listing'    => $listing,
            'isSeller'   => $isSeller,
            'isBuyer'    => (int) ($listing->buyer_id ?? 0) === $userId,
            'isWatched'  => $this->listing->isWatched($userId, $id),
            'watchCount' => $this->listing->watchCount($id),
            'requests'   => $isSeller ? $this->request->getAllByListing($id) : [],
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    /** @return VitrinePayload */
    public function getUserDashboard(int $userId): array
    {
        return [
            'listings'   => $this->listing->getBySeller($userId),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }

    /** @return VitrinePayload */
    public function getUserPurchases(int $userId): array
    {
        return [
            'listings'   => $this->listing->getByBuyer($userId),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }

    /** @return VitrineResult */
    public function adminApproveListing(int $listingId, int $adminId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\AdminApproveVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($listingId, $adminId));
    }

    /** @return VitrineResult */
    public function adminRejectListing(int $listingId, string $reason, int $adminId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\AdminRejectVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($listingId, $reason, $adminId));
    }

    /** @return VitrineResult */
    public function adminRefundListing(int $listingId, int $adminId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\AdminRefundVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($listingId, $adminId));
    }

    /**
         * @param VitrinePayload $data
         * @return VitrineResult
         */
    public function createListing(int $userId, array $data): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\CreateVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($userId, $data));
    }

    /**
         * @param VitrinePayload $data
         * @return VitrineResult
         */
    public function sendRequest(int $requesterId, int $listingId, array $data): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\SendVitrineRequestJob::class);
        return $this->normalizeVitrineResult($job->handle($requesterId, $listingId, $data));
    }

    /** @return VitrineResult */
    public function acceptRequest(int $sellerId, int $requestId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\AcceptVitrineRequestJob::class);
        return $this->normalizeVitrineResult($job->handle($sellerId, $requestId));
    }

    /** @return VitrineResult */
    public function rejectRequest(int $sellerId, int $requestId): array
    {
        $req = $this->request->findById($requestId);
        if (!$req) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        $req = $this->requireRequestRow($req);
        if ((int)$req->seller_id !== $sellerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        $this->request->updateStatus($requestId, VitrineRequest::STATUS_REJECTED);

        $this->eventDispatcher->dispatch('vitrine.request_rejected', [
            'requester_id' => (int) $req->requester_id,
            'listing_id' => $req->listing_id
        ]);

        return ['success' => true];
    }

    /**
     * 🛡️ Fix: لغو آگهی توسط فروشنده
     *
     * فروشنده می‌تواند آگهی خود را لغو کند اگر:
     *   - آگهی در وضعیت active باشد → فقط تغییر وضعیت به cancelled
     *   - آگهی در وضعیت in_escrow باشد → بازگشت وجه به خریدار + تغییر وضعیت
     *
     * State Machine: STATUS_ACTIVE → STATUS_CANCELLED ✅
     *               STATUS_IN_ESCROW → STATUS_CANCELLED ✅
     */
    /** @return VitrineResult */
    public function cancelListing(int $sellerId, int $listingId): array
    {
        try {
            $result = $this->db->transactional(function () use ($sellerId, $listingId): array {
                $listing = $this->listing->find($listingId);
                if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد'];
                $listing = $this->requireListingRow($listing);
                if ((int)$listing->seller_id !== $sellerId) return ['success' => false, 'message' => 'شما مالک این آگهی نیستید'];
                if (!in_array($listing->status, ['active', 'in_escrow'], true)) return ['success' => false, 'message' => 'این آگهی قابل لغو نیست'];

                if ($listing->status === 'in_escrow') {
                    $escrow = $this->escrowService->getByOrder($listingId, 'vitrine_listing');
                    if ($escrow === null || !isset($escrow->buyer_id) || !is_scalar($escrow->buyer_id)) {
                        throw new \Core\Exceptions\NotFoundException('اطلاعات صندوق امانات یافت نشد');
                    }
                    $refund = $this->financialEscrow->refundVitrineFunds(
                        $listingId,
                        (int)$escrow->buyer_id,
                        'لغو آگهی توسط فروشنده',
                        'vitrine_cancel_refund:' . $listingId
                    );
                    if (empty($refund['ok'])) {
                        throw new \Core\Exceptions\ApplicationException(is_string($refund['error'] ?? null) ? $refund['error'] : 'خطا در بازگشت وجه');
                    }
                }
                if (!$this->listing->updateStatus($listingId, 'cancelled', ['buyer_id' => null, 'admin_note' => 'لغو توسط فروشنده'])) {
                    throw new \Core\Exceptions\ApplicationException('تغییر وضعیت آگهی ناموفق بود');
                }
                $this->outbox?->record('vitrine', $listingId, 'vitrine.listing_removed', [
                    'id' => $listingId, 'user_id' => $sellerId, 'reason' => 'seller_cancelled',
                ]);
                return ['success' => true, 'message' => 'آگهی با موفقیت لغو شد'];
            });
            return $this->normalizeVitrineResult($result);
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.cancel.failed', ['listing_id' => $listingId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در لغو آگهی: ' . $e->getMessage()];
        }
    }

    /** @return VitrineResult */
    public function lockEscrow(int $buyerId, int $listingId): array
    {
        try {
            $result = $this->db->transactional(function () use ($buyerId, $listingId): array {
                $listing = $this->listing->find($listingId);
                if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد'];
                $listing = $this->requireListingRow($listing);
                if ($listing->status !== 'active') return ['success' => false, 'message' => 'این آگهی دیگر فعال نیست'];
                if ((int)$listing->seller_id === $buyerId) return ['success' => false, 'message' => 'خرید آگهی خودتان مجاز نیست'];
                $listingValues = get_object_vars($listing);
                $offer = $listingValues['offer_price_usdt'] ?? null;
                $amount = is_scalar($offer) && bccomp((string)$offer, '0', 8) > 0
                    ? (string)$offer
                    : (string)$listing->price_usdt;
                if (bccomp($amount, '0', 8) <= 0) return ['success' => false, 'message' => 'قیمت آگهی معتبر نیست'];

                assert_fraud_allowed($buyerId, 'vitrine.escrow', ['amount' => $amount]);
                $hold = $this->financialEscrow->holdVitrineFunds(
                    $listingId, $buyerId, (int)$listing->seller_id, $amount,
                    'vitrine_lock:' . $listingId . ':' . $buyerId
                );
                if (empty($hold['ok']) || !isset($hold['escrow_id'])) {
                    throw new \Core\Exceptions\ApplicationException(is_string($hold['error'] ?? null) ? $hold['error'] : 'خطا در ایجاد صندوق امانی');
                }
                $confirmed = $this->escrowService->confirmHold($listingId, 'vitrine_listing', (int)$listing->seller_id);
                if (empty($confirmed['ok'])) {
                    throw new \Core\Exceptions\ApplicationException(is_string($confirmed['error'] ?? null) ? $confirmed['error'] : 'تأیید صندوق امانی ناموفق بود');
                }
                if (!$this->listing->updateStatus($listingId, 'in_escrow', ['buyer_id' => $buyerId, 'escrow_locked_at' => date('Y-m-d H:i:s')])) {
                    throw new \Core\Exceptions\ApplicationException('ثبت وضعیت صندوق امانی آگهی ناموفق بود');
                }
                $escrowIdValue = $hold['escrow_id'];
                if (!is_int($escrowIdValue) && !(is_string($escrowIdValue) && ctype_digit($escrowIdValue))) {
                    throw new \UnexpectedValueException('شناسه صندوق امانی معتبر نیست');
                }
                $escrowId = (int)$escrowIdValue;
                return ['success' => true, 'message' => 'مبلغ در صندوق امانی قفل شد.', 'escrow_id' => $escrowId];
            });
            return $this->normalizeVitrineResult($result);
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.lock_escrow.failed', ['listing_id' => $listingId, 'buyer_id' => $buyerId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در قفل‌کردن وجه: ' . $e->getMessage()];
        }
    }

    /** @return VitrineResult */
    public function confirmDelivery(int $buyerId, int $listingId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\ConfirmVitrineDeliveryJob::class);
        return $this->normalizeVitrineResult($job->handle($buyerId, $listingId));
    }

    /**
     * @param \stdClass $listing
     * @return VitrineResult
     */
    public function releaseFundsToSeller(\stdClass $listing, string $reason = 'manual'): array
    {
        $listing = $this->requireListingRow($listing);
        $job = $this->getService(\App\Jobs\Vitrine\ReleaseVitrineFundsJob::class);
        return $this->normalizeVitrineResult($job->handle($listing, $reason));
    }

    /** @return VitrineResult */
    public function openDispute(int $userId, int $listingId, string $reason): array
    {
        try {
            $result = $this->db->transactional(function () use ($userId, $listingId, $reason): array {
                $listing = $this->listing->find($listingId);
                if (!$listing) return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
                $listing = $this->requireListingRow($listing);
                $listingValues = get_object_vars($listing);
                $buyerId = $listingValues['buyer_id'] ?? null;
                if ((!is_scalar($buyerId) || (int)$buyerId !== $userId) && (int)$listing->seller_id !== $userId) {
                    return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
                }
                if ($listing->status !== 'in_escrow') {
                    return ['success' => false, 'message' => 'وضعیت آگهی مناسب نیست.'];
                }
                $escrow = $this->escrowService->getByOrder($listingId, 'vitrine_listing');
                if ($escrow === null) throw new \Core\Exceptions\NotFoundException('صندوق امانی ویترین یافت نشد');
                $marked = $this->escrowService->markAsDisputed((int)$escrow->id, $reason, 'vitrine_dispute:' . $listingId);
                if (empty($marked['ok'])) {
                    throw new \Core\Exceptions\ApplicationException(is_string($marked['error'] ?? null) ? $marked['error'] : 'ثبت اختلاف escrow ناموفق بود');
                }
                if (!$this->listing->updateStatus($listingId, VitrineListing::STATUS_DISPUTED, ['rejection_reason' => $reason])) {
                    throw new \Core\Exceptions\ApplicationException('ثبت وضعیت اختلاف آگهی ناموفق بود');
                }

                // ثبت یکپارچه در جدول unified disputes (ref_type = vitrine_listing)
                $sellerId = (int)$listing->seller_id;
                $buyerIdInt = is_scalar($buyerId) ? (int)$buyerId : 0;
                $openerId = $userId;
                $targetId = ($openerId === $buyerIdInt) ? $sellerId : $buyerIdInt;
                $disputeService = $this->getService(\App\Services\Shared\DisputeService::class);
                $disputeService->openCase([
                    'ref_type' => 'vitrine_listing',
                    'ref_id'   => $listingId,
                    'user_id'  => $openerId,
                    'target_user_id' => $targetId > 0 ? $targetId : null,
                    'reason'   => $reason,
                ]);

                $this->outbox?->record('vitrine', $listingId, 'vitrine.dispute_opened', [
                    'listing_id' => $listingId, 'user_id' => $userId, 'reason' => $reason,
                ]);
                return ['success' => true, 'message' => 'اختلاف ثبت گردید و وجه در صندوق امانی باقی ماند.'];
            });
            return $this->normalizeVitrineResult($result);
        } catch (\Throwable $e) {
            $this->logger->warning('vitrine.dispute.failed', ['listing_id' => $listingId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت اختلاف: ' . $e->getMessage()];
        }
    }

    /** @return VitrineResult */
    public function resolveDispute(int $listingId, string $winner, int $adminId): array
    {
        $job = $this->getService(\App\Jobs\Vitrine\ResolveVitrineDisputeJob::class);
        $result = $this->normalizeVitrineResult($job->handle($listingId, $winner, $adminId));

        // بستن پرونده‌ی یکپارچه‌ی dispute پس از تسویه‌ی ویترین
        if (!empty($result['success'])) {
            $verdict = ($winner === 'seller') ? 'favor_seller' : 'favor_buyer';
            $winnerUserId = ($winner === 'seller') ? (int)$this->listing->find($listingId)?->seller_id : null;
            $this->getService(\App\Models\Dispute::class)->resolveByRef(
                'vitrine_listing',
                $listingId,
                $adminId,
                $verdict,
                $winnerUserId
            );
        }

        return $result;
    }

    /**
     * پرونده‌ی یکپارچه‌ی dispute ویترین را بر اساس listing پیدا می‌کند.
     */
    public function findListingDispute(int $listingId): ?\stdClass
    {
        return $this->getService(\App\Models\Dispute::class)->findByRef('vitrine_listing', $listingId);
    }

    /**
     * ارسال پیام در پرونده‌ی dispute ویترین (از جدول یکپارچه‌ی dispute_messages).
     * @return array<string, mixed>
     */
    public function sendDisputeMessage(int $listingId, int $userId, string $message, ?string $attachment = null): array
    {
        $dispute = $this->findListingDispute($listingId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده‌ی اختلاف ویترین یافت نشد.'];
        }
        $disputeService = $this->getService(\App\Services\Shared\DisputeService::class);
        $result = $disputeService->addMessageWithContext((int)$dispute->id, $userId, $message, $attachment);
        $result['success'] = !empty($result['ok']) || !empty($result['success']);
        return $result;
    }

    /**
     * دریافت پیام‌های پرونده‌ی dispute ویترین.
     * @return list<\stdClass>
     */
    public function getDisputeMessages(int $listingId, ?int $userId = null): array
    {
        $dispute = $this->findListingDispute($listingId);
        if (!$dispute) {
            return [];
        }
        if ($userId !== null
            && (int)$dispute->user_id !== $userId
            && (int)($dispute->target_user_id ?? 0) !== $userId
            && !is_admin()) {
            return [];
        }
        return $this->getService(\App\Services\Shared\DisputeService::class)->getMessages((int)$dispute->id);
    }

    /** @param ListingRow $newListing */
    public function notifySimilarListing(object $newListing): void
    {
        $newListing = $this->requireListingRow($newListing);
        $users = $this->listing->getCategoryAlertUsers($newListing->category, $newListing->platform);
        foreach ($users as $userId) {
            if ((int) $userId === (int) $newListing->seller_id) continue;
            $this->outbox?->record('notification', (int) $userId, 'notification.requested', [
                'user_id' => (int) $userId,
                'type' => \App\Models\Notification::TYPE_INFO,
                'title' => 'آگهی مشابه جدید در ویترین',
                'message' => "آگهی جدیدی در دسته «{$newListing->category}» منتشر شد: «{$newListing->title}»",
                'data' => ['listing_id' => $newListing->id],
                'action_url' => url('/vitrine/' . $newListing->id),
                'action_text' => 'مشاهده آگهی',
                'priority' => 'normal'
            ]);
        }
    }

    /** @param ListingRow $listing */
    public function notifyListingApproved(int $sellerId, object $listing): void
    {
        $listing = $this->requireListingRow($listing);
        $this->outbox?->record('notification', $sellerId, 'notification.requested', [
            'user_id' => $sellerId,
            'type' => \App\Models\Notification::TYPE_INFO,
            'title' => 'آگهی شما تایید شد',
            'message' => "آگهی «{$listing->title}» توسط تیم ویترین تایید و منتشر شد.",
            'data' => ['listing_id' => $listing->id],
            'action_url' => url('/vitrine/' . $listing->id),
            'action_text' => 'مشاهده آگهی',
            'priority' => 'high'
        ]);
    }

    /** @return VitrineResult */
    public function processExpiredEscrows(): array
    {
        if (!class_exists(\App\Jobs\Vitrine\ProcessExpiredVitrineEscrowsJob::class)) {
            return ["success" => false, "message" => "Job class not found"];
        }
        $job = $this->getService(\App\Jobs\Vitrine\ProcessExpiredVitrineEscrowsJob::class);
        return $this->normalizeVitrineResult($job->handle());
    }

    /** @return VitrineResult */
    public function rateListing(int $raterId, int $listingId, int $stars, string $comment = ''): array
    {
        if (!class_exists(\App\Jobs\Vitrine\RateVitrineListingJob::class)) {
            return ["success" => false, "message" => "Job class not found"];
        }
        $job = $this->getService(\App\Jobs\Vitrine\RateVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($raterId, $listingId, $stars, $comment));
    }

    /** @return VitrineResult */
    public function reportListing(int $reporterId, int $listingId, string $reason, string $description = ''): array
    {
        if (!class_exists(\App\Jobs\Vitrine\ReportVitrineListingJob::class)) {
            return ["success" => false, "message" => "Job class not found"];
        }
        $job = $this->getService(\App\Jobs\Vitrine\ReportVitrineListingJob::class);
        return $this->normalizeVitrineResult($job->handle($reporterId, $listingId, $reason, $description));
    }

    /**
         * @param VitrineFilters $filters
         * @return VitrinePayload
         */
    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        $filters['status'] = 'active';
        $filters['listing_type'] = 'sell';
        $q = is_scalar($filters['q'] ?? null) ? (string)$filters['q'] : '';
        $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'newest';
        [$sortCol, $sortDir] = match ($sort) {
            'oldest'     => ['created_at', 'ASC'],
            'price_asc'  => ['price_usdt', 'ASC'],
            'price_desc' => ['price_usdt', 'DESC'],
            default      => ['created_at', 'DESC'],
        };
        return $this->listing->searchNative($q, $filters, $limit, $offset, $sortCol, $sortDir);
    }

    /** @return VitrineResult */
    public function toggleWatch(int $userId, int $listingId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        $listing = $this->requireListingRow($listing);

        $alreadyWatched = $this->listing->isWatched($userId, $listingId);
        if ($alreadyWatched) {
            $this->listing->removeWatch($userId, $listingId);
            return ['success' => true, 'watched' => false, 'message' => 'از لیست علاقه‌مندی‌ها حذف شد.'];
        } else {
            $this->listing->addWatch($userId, $listingId);
            return ['success' => true, 'watched' => true, 'message' => 'به لیست علاقه‌مندی‌ها اضافه شد.'];
        }
    }

    /**
         * @param VitrineFilters $filters
         * @return VitrinePayload
         */
    public function getAdminIndexData(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->adminList($filters, $perPage, $offset),
            'total'      => $this->listing->adminCount($filters),
            'stats'      => $this->listing->adminStats(),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }
}