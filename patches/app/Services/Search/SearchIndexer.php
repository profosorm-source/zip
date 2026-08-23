<?php

declare(strict_types=1);

namespace App\Services\Search;

use Core\Database;

/**
 * SearchIndexer — نگه‌دارنده‌ی Read-Model جستجو (search_projections)
 *
 * این کلاس تنها نقطه‌ی نوشتن (Single Writer) در جدول projection است.
 * Listenerها و Backfill از طریق همین کلاس projection را به‌روز نگه می‌دارند تا
 * مسیر خواندن (SearchProjectionRepository) بتواند با MATCH ... AGAINST و بدون
 * JOIN به جداول live و بدون LIKE '%...%'، جستجوی Scale‌پذیر انجام دهد.
 *
 * فیلدها:
 *   entity_type : نوع موجودیت (مثلاً 'transaction', 'ticket', 'vitrine')
 *   entity_id   : شناسه‌ی رکورد در جدول مبدأ
 *   owner_id    : مالک رکورد (برای ownership در User Search) — null برای رکوردهای عمومی
 *   scope       : 'admin' | 'user' | 'module'  (دامنه‌ی دیدِ projection)
 *   module      : نام ماژول منطقی برای فیلتر/تجمیع (مثلاً 'transactions')
 *   ref         : شناسه‌ی نمایشی کوتاه (tracking_code, transaction_id, ...) برای جستجوی دقیق
 *   title       : عنوان قابل‌جستجو (FULLTEXT)
 *   content     : متن کامل قابل‌جستجو (FULLTEXT)
 *   metadata    : payload نمایشی برای ساخت نتیجه بدون رجوع به جدول live
 */
class SearchIndexer
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * درج یا بروزرسانی یک projection (UPSERT اتمیک بر اساس entity_type+entity_id).
     *
     * @param array<string, mixed> $metadata
     */
    public function index(
        string $entityType,
        int $entityId,
        ?string $title,
        ?string $content,
        array $metadata = [],
        bool $isActive = true,
        ?int $ownerId = null,
        string $scope = 'module',
        ?string $module = null,
        ?string $ref = null
    ): void {
        $this->validateIndexFields($entityType, $entityId, $metadata, $ownerId, $scope, $module);

        $sql = "
            INSERT INTO search_projections
                (entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, is_active, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                owner_id   = VALUES(owner_id),
                scope      = VALUES(scope),
                module     = VALUES(module),
                ref        = VALUES(ref),
                title      = VALUES(title),
                content    = VALUES(content),
                metadata   = VALUES(metadata),
                is_active  = VALUES(is_active),
                updated_at = NOW()
        ";

        $this->db->execute($sql, [
            $entityType,
            $entityId,
            $ownerId,
            $scope,
            $module ?? $entityType,
            $ref !== null ? mb_substr($ref, 0, 190) : null,
            $title,
            $content,
            $this->encodeMetadata($metadata),
            $isActive ? 1 : 0,
        ]);
    }

    /**
     * نمایه‌گذاری دسته‌ای (برای Backfill) — هر سطر به همان قرارداد index() است.
     *
     * @param array<int, mixed> $rows
     * @return int تعداد سطرهای پردازش‌شده
     */
    public function indexBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $placeholders = [];
        $params = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Search index batch rows must be arrays.');
            }

            $entityType = $row['entity_type'] ?? null;
            $entityId = $row['entity_id'] ?? null;
            $title = $row['title'] ?? null;
            $content = $row['content'] ?? null;
            $metadata = $row['metadata'] ?? [];
            $isActive = $row['is_active'] ?? true;
            $ownerId = $row['owner_id'] ?? null;
            $scope = $row['scope'] ?? 'module';
            $module = $row['module'] ?? null;
            $ref = $row['ref'] ?? null;

            if (!is_string($entityType) || !is_int($entityId)) {
                throw new \UnexpectedValueException('Search index batch rows require entity_type string and entity_id integer.');
            }
            if ($title !== null && !is_string($title)) {
                throw new \UnexpectedValueException('Search index batch title must be a string or null.');
            }
            if ($content !== null && !is_string($content)) {
                throw new \UnexpectedValueException('Search index batch content must be a string or null.');
            }
            if (!is_bool($isActive)) {
                throw new \UnexpectedValueException('Search index batch is_active must be boolean.');
            }
            if ($ownerId !== null && !is_int($ownerId)) {
                throw new \UnexpectedValueException('Search index batch owner_id must be an integer or null.');
            }
            if (!is_string($scope)) {
                throw new \UnexpectedValueException('Search index batch scope must be a string.');
            }
            if ($module !== null && !is_string($module)) {
                throw new \UnexpectedValueException('Search index batch module must be a string or null.');
            }
            if ($ref !== null && !is_string($ref)) {
                throw new \UnexpectedValueException('Search index batch ref must be a string or null.');
            }
            $typedMetadata = $this->requireMetadata($metadata);
            $this->validateIndexFields($entityType, $entityId, $typedMetadata, $ownerId, $scope, $module);

            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
            $params[] = $entityType;
            $params[] = $entityId;
            $params[] = $ownerId;
            $params[] = $scope;
            $params[] = $module ?? $entityType;
            $params[] = $ref !== null ? mb_substr($ref, 0, 190) : null;
            $params[] = $title;
            $params[] = $content;
            $params[] = $this->encodeMetadata($typedMetadata);
            $params[] = $isActive ? 1 : 0;
        }

        $sql = "
            INSERT INTO search_projections
                (entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, is_active, created_at, updated_at)
            VALUES " . implode(', ', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                owner_id   = VALUES(owner_id),
                scope      = VALUES(scope),
                module     = VALUES(module),
                ref        = VALUES(ref),
                title      = VALUES(title),
                content    = VALUES(content),
                metadata   = VALUES(metadata),
                is_active  = VALUES(is_active),
                updated_at = NOW()
        ";

        $this->db->execute($sql, $params);
        return count($rows);
    }

    /**
     * حذف نرم (soft) یک projection — رکورد می‌ماند ولی از نتایج خارج می‌شود.
     */
    public function deactivate(string $entityType, int $entityId): void
    {
        $this->db->execute(
            "UPDATE search_projections SET is_active = 0, updated_at = NOW() WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );
    }

    /**
     * حذف کامل یک projection از سیستم جستجو.
     */
    public function remove(string $entityType, int $entityId): void
    {
        $this->db->execute(
            "DELETE FROM search_projections WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMetadata(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Search index metadata must be an array.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Search index metadata keys must be strings.');
            }
            $metadata[$key] = $item;
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function validateIndexFields(
        string $entityType,
        int $entityId,
        array $metadata,
        ?int $ownerId,
        string $scope,
        ?string $module
    ): void {
        if ($entityType === '' || $entityId <= 0) {
            throw new \InvalidArgumentException('Search index entity_type and entity_id must be valid.');
        }
        if ($ownerId !== null && $ownerId <= 0) {
            throw new \InvalidArgumentException('Search index owner_id must be positive or null.');
        }
        if (!in_array($scope, ['admin', 'user', 'module'], true)) {
            throw new \InvalidArgumentException('Search index scope is invalid.');
        }
        if ($module !== null && $module === '') {
            throw new \InvalidArgumentException('Search index module must be non-empty or null.');
        }
        foreach ($metadata as $key => $_value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Search index metadata keys must be strings.');
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function encodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
