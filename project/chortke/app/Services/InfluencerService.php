<?php

namespace App\Services;

use App\Services\Influencer\InfluencerCommandService;
use App\Services\Influencer\InfluencerQueryService;
use App\Contracts\WalletServiceInterface;
use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Contracts\LoggerInterface;
use Core\Database;
use App\Services\Settings\AppSettings;

/**
 * InfluencerService — Facade
 *
 * تمام logic به InfluencerCommandService و InfluencerQueryService منتقل شده.
 * این کلاس فقط Backward-Compatible API ارائه می‌دهد.
 */
class InfluencerService
{
    const SYSTEM_ACTOR_ID = -1;

    private InfluencerCommandService $commandService;
    private InfluencerQueryService $queryService;
    private InfluencerModel $profileModel;
    private StoryOrder $orderModel;
    private Database $db;

    public function __construct(
        InfluencerCommandService $commandService,
        InfluencerQueryService $queryService,
        InfluencerModel $profileModel,
        StoryOrder $orderModel,
        Database $db
    ) {
        $this->commandService = $commandService;
        $this->queryService = $queryService;
        $this->profileModel = $profileModel;
        $this->orderModel = $orderModel;
        $this->db = $db;
    }

    // ─── Command delegates ──────────────────────────────────────

    /** @param array<string, mixed> $data
     *  @return array{success: bool, message: string, profile?: \stdClass, verification_code?: string} */
    public function registerInfluencer(int $userId, array $data): array
    { return $this->commandService->registerInfluencer($userId, $data); }

    /** @return array<string, mixed> */
    public function submitVerificationPost(int $userId, string $postUrl): array
    { return $this->commandService->submitVerificationPost($userId, $postUrl); }

    /** @param array<string, mixed> $data
     *  @return array{success: bool, message: string, order?: \stdClass} */
    public function createOrder(int $customerId, int $influencerId, array $data): array
    { return $this->commandService->createOrder($customerId, $influencerId, $data); }

    /** @return array{success: bool, message: string} */
    public function respondToOrder(int $orderId, int $influencerUserId, string $decision, ?string $reason = null): array
    { return $this->commandService->respondToOrder($orderId, $influencerUserId, $decision, $reason); }

    /** @param array<string, mixed> $proofData
     *  @return array{success: bool, message: string} */
    public function submitProof(int $orderId, int $influencerUserId, array $proofData): array
    { return $this->commandService->submitProof($orderId, $influencerUserId, $proofData); }

    /** @return array{success: bool, message: string, data?: array<string, mixed>} */
    public function buyerConfirm(int $orderId, int $customerId): array
    { return $this->commandService->buyerConfirm($orderId, $customerId); }

    /** @return array{success: bool, message: string, order_id?: int} */
    public function buyerDispute(int $orderId, int $customerId, string $reason): array
    { return $this->commandService->buyerDispute($orderId, $customerId, $reason); }

    /** @return array{success: bool, message: string, data?: array<string, mixed>} */
    public function completeOrder(int $orderId, int $actorId, string $reason = 'completed'): array
    { return $this->commandService->completeOrder($orderId, $actorId, $reason); }

    /** @return array<string, mixed> */
    public function refundOrder(int $orderId, int $actorId, float $refundPercent = 100.0, string $reason = ''): array
    { return $this->commandService->refundOrder($orderId, $actorId, $refundPercent, $reason); }

    public function processExpiredBuyerChecks(): int
    { return $this->commandService->processExpiredBuyerChecks(); }

    public function processExpiredPendingAcceptance(): int
    { return $this->commandService->processExpiredPendingAcceptance(); }

    public function cleanupOldFiles(int $days = 3): int
    { return $this->commandService->cleanupOldFiles($days); }

    /** @return array<string, mixed> */
    public function reportOrder(int $reporterId, int $orderId, string $reason, string $description = ''): array
    { return $this->commandService->reportOrder($reporterId, $orderId, $reason, $description); }

    /** @return array<string, mixed> */
    public function rateInfluencer(int $raterId, int $orderId, int $stars, string $comment = ''): array
    { return $this->commandService->rateInfluencer($raterId, $orderId, $stars, $comment); }


    /** Backward-compatible API wrappers used by Api\InfluencerController.
     *  @return list<\stdClass> */
    public function getOrdersByCustomer(int $customerId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        return $this->orderModel->getByCustomer($customerId, $status, $limit, $offset);
    }

    public function countOrdersByCustomer(int $customerId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM story_orders WHERE customer_id = ?';
        $params = [$customerId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ─── Query delegates ────────────────────────────────────────

    /** @param array<string, mixed> $filters
     *  @return array{items: list<\stdClass>, total: int} */
    public function searchInfluencers(array $filters, int $limit, int $offset): array
    { return $this->queryService->searchInfluencers($filters, $limit, $offset); }

    /** @param array<string, mixed> $filters
     *  @return array{items: list<\stdClass>, total: int} */
    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    { return $this->queryService->searchInfluencersAdmin($q, $filters, $limit, $offset); }

    public function getProfileByUserId(int $userId): ?\stdClass
    { return $this->profileModel->findByUserId($userId); }

    public function getProfileById(int $id): ?\stdClass
    { return $this->profileModel->findProfile($id); }

    /** @param array<string, mixed> $filters
     *  @return list<\stdClass> */
    public function listVerifiedProfiles(array $filters = [], string $sort = 'priority', int $limit = 20, int $offset = 0): array
    { return $this->profileModel->getVerified($filters, $sort, $limit, $offset); }

    /** @param array<string, mixed> $filters */
    public function countVerifiedProfiles(array $filters = []): int
    { return $this->profileModel->countVerified($filters); }
}
