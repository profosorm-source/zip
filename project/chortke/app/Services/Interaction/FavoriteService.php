<?php

declare(strict_types=1);

namespace App\Services\Interaction;

use App\Enums\InteractionType;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * سرویس مدیریت علاقه‌مندی‌ها (Favorites / Bookmarks)
 * مسئولیت: لایک کردن یا ذخیره کردن یک محتوا در لیست علاقه‌مندی‌های کاربر
 */
class FavoriteService
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;

        
    }

    /**
     * تغییر وضعیت علاقه‌مندی (اگر بود حذف کن، اگر نبود اضافه کن)
     */
    public function toggle(int $userId, string $interactableType, int $interactableId, ModuleContext $context): bool
    {
        try {
            $this->db->beginTransaction();
            // اصلاح کلیدی معماری همزمانی در تعاملات موبایل (Mobile Rapid Toggle Lock):
            // قفل‌گذاری اتمیک ردیف تعاملی جهت جلوگیری از تداخل در اثر کلیک‌های پیاپی کاربران موبایل
            $stmt = $this->db->prepare("
                SELECT id FROM interactions 
                WHERE user_id = ? AND interactable_type = ? AND interactable_id = ? AND interaction_type = ?
                FOR UPDATE
            ");
            $stmt->execute([
                $userId,
                $interactableType,
                $interactableId,
                InteractionType::FAVORITE->value
            ]);
            
            $existing = $stmt->fetchColumn();

            if ($existing) {
                $delStmt = $this->db->prepare("DELETE FROM interactions WHERE id = ?");
                $res = $delStmt->execute([$existing]);
                $this->db->commit();
                return $res;
            } else {
                $insStmt = $this->db->prepare("
                    INSERT INTO interactions 
                    (user_id, interactable_type, interactable_id, interaction_type, context, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $res = $insStmt->execute([
                    $userId,
                    $interactableType,
                    $interactableId,
                    InteractionType::FAVORITE->value,
                    $context->value
                ]);
                $this->db->commit();
                return $res;
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('favorite_service.toggle_failed', [
                'user_id' => $userId,
                'entity' => $interactableType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * بررسی اینکه آیا کاربر این محتوا را لایک کرده یا نه
     */
    public function hasFavorited(int $userId, string $interactableType, int $interactableId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM interactions 
            WHERE user_id = ? AND interactable_type = ? AND interactable_id = ? AND interaction_type = ?
        ");
        $stmt->execute([
            $userId,
            $interactableType,
            $interactableId,
            InteractionType::FAVORITE->value
        ]);
        
        return (bool)$stmt->fetchColumn();
    }
}
