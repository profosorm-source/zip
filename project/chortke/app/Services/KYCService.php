<?php

namespace App\Services;

use App\Services\KYC\KYCCommandService;
use App\Services\KYC\KYCQueryService;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;
use App\Models\KYCVerification;
use Core\Database;
use Core\Encryption;
use Core\RateLimiter;
use App\Services\Search\SearchQuery;
use App\Data\SearchResult;

/**
 * KYCService — Facade
 * Logic به KYCCommandService و KYCQueryService منتقل شده.
 */
class KYCService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    private KYCCommandService $commandService;
    private KYCQueryService $queryService;
    // BUGFIX-CTRL-RAW-SQL-2026-06: keep a reference to the model so the
    // admin-side concurrency lock and document cleanup can be exposed
    // without re-instantiating it (or letting the controller talk to
    // Database directly the way it used to).
    private KYCVerification $kycModel;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        KYCVerification $kycModel,
        UploadService $uploadService,
        \App\Adapters\KycFaceVerificationAdapter $aiAdapter,
        Encryption $encryption,
        RateLimiter $rateLimiter,
        NotificationService $notificationService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->kycModel = $kycModel;
        $this->commandService = new KYCCommandService(
            $logger, $db,
            $uploadService,
            $kycModel,
            $aiAdapter,
            $encryption,
            $rateLimiter,
            $notificationService,
            $outbox
        );
        $this->queryService = new KYCQueryService($logger, $db, $kycModel, $encryption);
    }

    // ─── Admin operations exposed for KYCController ────────────

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06: thin proxy to KYCVerification::lockForReview().
     * Returns true if this admin acquired (or refreshed) the review lock.
     */
    public function lockForReview(int $kycId, int $adminId, int $staleMinutes = 30): bool
    {
        return $this->kycModel->lockForReview($kycId, $adminId, $staleMinutes);
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06: thin proxy to KYCVerification::deleteDocuments().
     */
    public function deleteDocuments(int $kycId): bool
    {
        return $this->kycModel->deleteDocuments($kycId);
    }

    // ─── Command ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $files
     * @return array<string, mixed>
     */
    public function submitKYC(int $userId, array $data, array $files): array
    { return $this->commandService->submit($userId, $data, $files); }

    /** @return array<string, mixed> */
    public function verifyKYC(int $kycId, int $adminId): array
    { return $this->commandService->verify($kycId, $adminId); }

    /** @return array<string, mixed> */
    public function rejectKYC(int $kycId, int $adminId, string $reason): array
    { return $this->commandService->reject($kycId, $adminId, $reason); }

    // ─── Query ──────────────────────────────────────────────────

    public function isApproved(int $userId): bool
    { return $this->queryService->isApproved($userId); }

    /** @return array<string, mixed> */
    public function canSubmitKYC(int $userId): array
    { return $this->queryService->canSubmitKYC($userId); }

    /** @return array<string, mixed> */
    public function detectPhotoshop(string $imagePath): array
    { return $this->queryService->detectPhotoshop($imagePath); }

    public function getAll(mixed $query = [], int|bool $limitOrMask = 20, int $offset = 0, bool $maskPII = false): mixed
    {
        if ($query instanceof SearchQuery) {
            return $this->queryService->getAll($query, (bool)$limitOrMask);
        }

        return $this->kycModel->getAll(
            is_array($query) ? $query : [],
            is_int($limitOrMask) ? $limitOrMask : 20,
            $offset
        );
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters = []): int
    { return $this->queryService->count($filters); }

    public function find(int $id, bool $maskPII = false): ?object
    {
        $result = $this->toObject($this->queryService->find($id, $maskPII));
        if (!$result) { return null; }
        // Already normalized inside queryService, but ensure guard at facade too
        if (isset($result->id)) {
            return $result;
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function getStatsByStatus(): array
    { return $this->queryService->getStatsByStatus(); }

    public function deleteVerificationImage(int $id): bool
    { return $this->queryService->deleteVerificationImage($id); }
}
