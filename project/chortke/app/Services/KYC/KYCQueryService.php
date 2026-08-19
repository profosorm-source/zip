<?php

declare(strict_types=1);

namespace App\Services\KYC;

use App\Contracts\LoggerInterface;
use App\Models\KYCVerification;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use Core\Database;

/**
 * سرویس Query احراز هویت — فقط خواندن (جستجو، بررسی وضعیت)
 */
class KYCQueryService
{
    private LoggerInterface $logger;
    private Database $db;
    private KYCVerification $kycModel;
    private \Core\Encryption $encryption;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        KYCVerification $kycModel,
        \Core\Encryption $encryption
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->kycModel = $kycModel;
        $this->encryption = $encryption;
    }

    /**
     * ROOT FIX (principled): Centralized toObject (standard pattern).
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    public function isApproved(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT kyc_status FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $status = $stmt->fetchColumn();
            return $status === 'verified';
        } catch (\Throwable $e) {
            $this->logger->error('kyc.is_approved.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * بررسی اینکه کاربر می‌تواند KYC ثبت کند یا نه
     */
    /** @return array<string, mixed> */
    public function canSubmitKYC(int $userId): array
    {
        $existingKYC = $this->toObject($this->kycModel->findByUserId($userId));
        if (!$existingKYC) { return ['can' => true]; }

        $statusValue = get_object_vars($existingKYC)['status'] ?? null;
        $status = is_scalar($statusValue) ? (string)$statusValue : '';

        if ($status === 'verified') {
            return ['can' => false, 'reason' => 'احراز هویت شما قبلاً تأیید شده است'];
        }

        if (in_array($status, ['pending', 'under_review'], true)) {
            return ['can' => false, 'reason' => 'درخواست قبلی شما در حال بررسی است'];
        }

        if ($status === 'rejected') {
            return ['can' => true];
        }

        return ['can' => true];
    }

    /**
     * تشخیص Photoshop ساده
     */
    /** @return array<string, mixed> */
    public function detectPhotoshop(string $imagePath): array
    {
        $suspicious = false;
        $reasons    = [];

        $exif = @exif_read_data($imagePath);
        if ($exif) {
            if (isset($exif['Software'])) {
                $software = strtolower($exif['Software']);
                if (strpos($software, 'photoshop') !== false || strpos($software, 'gimp') !== false) {
                    $suspicious = true;
                    $reasons[]  = 'تصویر با نرم‌افزار ویرایش ساخته شده';
                }
            }

            if (isset($exif['DateTime']) && isset($exif['DateTimeOriginal'])) {
                $diff = abs(strtotime($exif['DateTime']) - strtotime($exif['DateTimeOriginal']));
                if ($diff > 60) {
                    $suspicious = true;
                    $reasons[]  = 'اختلاف زمانی مشکوک بین ساخت و ویرایش';
                }
            }
        }

        if ($suspicious) {
            $this->logger->warning('kyc.image.suspicious', [
                'channel' => 'kyc',
                'image_path' => basename($imagePath),
                'reasons' => $reasons,
                'software' => $exif['Software'] ?? null
            ]);
        }

        return ['suspicious' => $suspicious, 'reasons' => $reasons];
    }

    /**
     * ثبت KYC با یک فایل
     */
    public function getAll(SearchQuery $query, bool $maskPII = false): SearchResult
    {
        $filters = $query->getFilters();
        if ($query->getTerm()) {
            $filters['q'] = $query->getTerm();
        }
        $filters['sort'] = $query->getSort();

        $results = $this->kycModel->getAll($filters, $query->getLimit(), $query->getOffset());
        $total = $this->count($filters);

        foreach ($results as $kyc) {
            if (!empty($kyc->national_code)) {
                $decrypted = $this->encryption->tryDecrypt((string)$kyc->national_code);
                if ($decrypted === null) {
                    $this->logger->error('kyc.decrypt.failed', [
                        'column'  => 'national_code',
                        'kyc_id'  => (int)($kyc->id ?? 0),
                        'user_id' => (int)($kyc->user_id ?? 0),
                    ]);
                    $kyc->national_code = '[CORRUPTED]';
                } else {
                    $kyc->national_code = $maskPII
                        ? (strlen((string)$decrypted) >= 5 ? substr($decrypted, 0, 3) . '****' . substr($decrypted, -2) : '*****')
                        : $decrypted;
                }
            }
            if (!empty($kyc->birth_date)) {
                $decrypted = $this->encryption->tryDecrypt((string)$kyc->birth_date);
                if ($decrypted === null) {
                    $this->logger->error('kyc.decrypt.failed', [
                        'column'  => 'birth_date',
                        'kyc_id'  => (int)($kyc->id ?? 0),
                        'user_id' => (int)($kyc->user_id ?? 0),
                    ]);
                    $kyc->birth_date = '[CORRUPTED]';
                } else {
                    $kyc->birth_date = $maskPII
                        ? (strlen((string)$decrypted) >= 4 ? substr($decrypted, 0, 4) . '/**/**' : '**//**')
                        : $decrypted;
                }
            }
        }
        return new SearchResult($results, $total);
    }

    /**
     * شمارش رکوردهای احراز هویت بر اساس فیلتر
     */
    /** @param array<string, mixed> $filters */
    public function count(array $filters = []): int
    {
        return $this->kycModel->count($filters);
    }

    /**
     * یافتن رکورد خاص
     */
    public function find(int $id, bool $maskPII = false): ?object
    {
        $kyc = $this->toObject($this->kycModel->find($id));
        if (!$kyc) { return null; }

        if (!empty($kyc->national_code)) {
            $decrypted = $this->encryption->tryDecrypt((string)$kyc->national_code);
            if ($decrypted === null) {
                $this->logger->error('kyc.decrypt.failed', [
                    'column'  => 'national_code',
                    'kyc_id'  => (int)($kyc->id ?? 0),
                    'user_id' => (int)($kyc->user_id ?? 0),
                ]);
                $kyc->national_code = '[CORRUPTED]';
            } else {
                $kyc->national_code = $maskPII
                    ? (strlen((string)$decrypted) >= 5 ? substr($decrypted, 0, 3) . '****' . substr($decrypted, -2) : '*****')
                    : $decrypted;
            }
        }
        if (!empty($kyc->birth_date)) {
            $decrypted = $this->encryption->tryDecrypt((string)$kyc->birth_date);
            if ($decrypted === null) {
                $this->logger->error('kyc.decrypt.failed', [
                    'column'  => 'birth_date',
                    'kyc_id'  => (int)($kyc->id ?? 0),
                    'user_id' => (int)($kyc->user_id ?? 0),
                ]);
                $kyc->birth_date = '[CORRUPTED]';
            } else {
                $kyc->birth_date = $maskPII
                    ? (strlen((string)$decrypted) >= 4 ? substr($decrypted, 0, 4) . '/**/**' : '**//**')
                    : $decrypted;
            }
        }
        return $kyc;
    }

    /**
     * ✅ دریافت آمار وضعیت‌ها با یک کوئری GROUP BY
     * به جای 4 کوئری جداگانه
     */
    /** @return array<string, mixed> */
    public function getStatsByStatus(): array
    {
        $stats = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count FROM kyc_verifications GROUP BY status"
        );

        $result = [
            'pending' => 0,
            'under_review' => 0,
            'verified' => 0,
            'rejected' => 0,
        ];

        foreach ($stats as $stat) {
            $status = is_scalar($stat->status ?? null) ? (string)$stat->status : '';
            $count = is_scalar($stat->count ?? null) ? (int)$stat->count : 0;
            if (isset($result[$status])) {
                $result[$status] = $count;
            }
        }

        return $result;
    }

    /**
     * حذف فیزیکی تصویر احراز هویت و تغییر وضعیت دیتابیس به وضعیت پاک‌شده
     */
    public function deleteVerificationImage(int $id): bool
    {
        $kyc = $this->toObject($this->kycModel->find($id));
        if ($kyc === null) return false;

        return $this->kycModel->updateImageStatusToDeleted($id);
    }
}
