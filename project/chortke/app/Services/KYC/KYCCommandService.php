<?php

declare(strict_types=1);

namespace App\Services\KYC;

use App\Contracts\LoggerInterface;
use App\Models\KYCVerification;
use App\Services\UploadService;
use App\Adapters\KycFaceVerificationAdapter;
use Core\Database;
use Core\Encryption;
use Core\RateLimiter;
// Core\IdempotencyKey حذف شد — KYCCommandService هرگز از آن استفاده نمی‌کرد (dead code)
use App\Events\KYCApprovedEvent;
use App\Services\Notification\NotificationService;

/**
 * سرویس Command احراز هویت — عملیات نوشتن (ثبت، تأیید، رد)
 */
class KYCCommandService
{
    private LoggerInterface $logger;
    private Database $db;
    private UploadService $uploadService;
    private KYCVerification $kycModel;
    private KycFaceVerificationAdapter $aiAdapter;
    private Encryption $encryption;
        private RateLimiter $rateLimiter;

    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private NotificationService $notificationService;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        UploadService $uploadService,
        KYCVerification $kycModel,
        KycFaceVerificationAdapter $aiAdapter,
        Encryption $encryption,
        RateLimiter $rateLimiter,
        NotificationService $notificationService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->uploadService = $uploadService;
        $this->kycModel = $kycModel;
        $this->aiAdapter = $aiAdapter;
        $this->encryption = $encryption;
        $this->rateLimiter = $rateLimiter;
        $this->notificationService = $notificationService;
        $this->outbox = $outbox;
    }

    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     * Guarantees ?object. Guard: if (!$x)
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $files
     * @return array<string, mixed>
     */
    public function submit(int $userId, array $data, array $files): array
    {
        return $this->handle($userId, $data, $files);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $files
     * @return array<string, mixed>
     */
    public function handle(int $userId, array $data, array $files): array
    {
        $uploadResult = null;
        $ikey         = $data['idempotency_key'] ?? null;

        // 🛡️ Service-Layer Rate Limiting: Prevent automated/spam KYC submissions
        if (!$this->rateLimiter->attempt("kyc_submit:{$userId}", 2, 3600)) {
            $this->logger->warning('kyc.submit.rate_limited', ['user_id' => $userId]);
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های شما برای احراز هویت بیش از حد مجاز است. لطفاً یک ساعت دیگر تلاش کنید.'
            ];
        }

        try {
            // 1) ورودی پایه
            if (empty($files['verification_image'])) {
                return ['success' => false, 'message' => 'تصویر احراز هویت الزامی است'];
            }

            $canSubmit = $this->canSubmitKYC($userId);
            if (!$canSubmit['can']) {
                return ['success' => false, 'message' => $canSubmit['reason']];
            }

            // The schema enforces one KYC row per user. A rejected request may be
            // replaced with a fresh submission after the cooldown window.
            $existingKyc = $this->toObject($this->kycModel->findByUserId($userId));
            if (!$existingKyc) {
                // no existing (or invalid) — continue
            } elseif ((string)$existingKyc->status === 'rejected') {
                $this->db->query('DELETE FROM kyc_documents WHERE kyc_id = ?', [(int)$existingKyc->id]);
                $this->db->query('DELETE FROM kyc_verifications WHERE id = ?', [(int)$existingKyc->id]);
            }

            $nationalCode = trim(str_value($data['national_code'] ?? ''));
            if ($nationalCode !== '' && !preg_match('/^\d{10}$/', $nationalCode)) {
                return ['success' => false, 'message' => 'کد ملی نامعتبر است'];
            }

            if ($nationalCode !== '') {
                // NOTE: ciphertext is no longer deterministic (AES-GCM with random IV),
                // so equality compare on ciphertext is impossible. We rely on the
                // deterministic HMAC fingerprint column `national_code_hash`.
                $ncHash = hash_hmac('sha256', $nationalCode, secure_key());
                $stmt = $this->db->prepare(
                    "SELECT user_id FROM kyc_verifications
                     WHERE status = 'verified' AND national_code_hash = ? LIMIT 1"
                );
                $stmt->execute([$ncHash]);
                $row = $stmt->fetch(\PDO::FETCH_OBJ);
                if (is_object($row) && (int)($row->user_id ?? 0) !== $userId) {
                    return [
                        'success' => false,
                        'message' => 'این کد ملی قبلاً در سیستم ثبت شده است'
                    ];
                }
            }

            // 2) آپلود فایل
            $uploadResult = $this->uploadService->upload(is_array($files['verification_image']) ? $files['verification_image'] : [], 'kyc');
            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => $uploadResult['message']];
            }

            $filename   = strval($uploadResult['filename']);
            $uploadPath = $this->uploadService->getPath('kyc/' . $filename);

            // 3) بررسی فتوشاپ/ریسک
            /** @var array{suspicious: bool, reasons: array<int, string>} $photoshopCheck */
            $photoshopCheck = $uploadPath
                ? $this->detectPhotoshop($uploadPath)
                : ['suspicious' => false, 'reasons' => []];

            // --- U-4: بررسی هوش مصنوعی (اگر فعال باشد) ---
            $aiCheck = ['is_valid' => true, 'confidence' => 1.0];
            if ($this->aiAdapter->isConfigured() && $uploadPath) {
                $aiAnalysis = $this->aiAdapter->analyzeImage($uploadPath);
                if (!empty($aiAnalysis['success'])) {
                    $aiCheck = $aiAnalysis;
                    if (empty($aiCheck['is_valid'])) {
                        $photoshopCheck['suspicious'] = true;
                        $aiNotes = is_array($aiCheck) ? ($aiCheck['ai_notes'] ?? 'عدم تأیید تصویر') : 'عدم تأیید تصویر';
                        $photoshopCheck['reasons'][]  = 'رد شدن توسط هوش مصنوعی: ' . str_value($aiNotes);
                    }
                } else {
                    // Downstream AI Service Failure -> FAIL-SECURE: force manual review.
                    $photoshopCheck['suspicious'] = true;
                    $photoshopCheck['reasons'][]  = 'عدم امکان تأیید خودکار (خطای سرویس هوش مصنوعی). جهت بررسی دستی ارجاع شد.';
                    $aiMsg = is_array($aiAnalysis) ? ($aiAnalysis['message'] ?? 'Unknown AI service error') : 'Unknown AI service error';
                    $this->logger->warning('kyc.ai_check.failed_secure', [
                        'user_id' => $userId,
                        'error'   => str_value($aiMsg),
                    ]);
                }
            }

            $payload = [
                'national_code'      => $nationalCode,
                'birth_date'         => !empty($data['birth_date']) ? str_value($data['birth_date']) : null,
                'verification_image' => $filename,
            ];

            $this->db->beginTransaction();

            $kycId = $this->kycModel->create([
                'user_id'            => $userId,
                'verification_image' => $filename,
                'national_code'      => $nationalCode !== ''
                    ? $this->encryption->encrypt($nationalCode, 'kyc.national_code')
                    : null,
                'national_code_hash' => $nationalCode !== ''
                    ? hash_hmac('sha256', $nationalCode, secure_key())
                    : null,
                'birth_date'         => !empty($data['birth_date'])
                    ? $this->encryption->encrypt(str_value($data['birth_date']), 'kyc.birth_date')
                    : null,
                'status'             => !empty($photoshopCheck['suspicious']) ? 'under_review' : 'pending',
                'ip_address'         => function_exists('get_client_ip') ? get_client_ip() : null,
                'user_agent'         => function_exists('get_user_agent') ? get_user_agent() : null,
                'device_fingerprint' => function_exists('generate_device_fingerprint')
                    ? generate_device_fingerprint()
                    : null,
            ]);

            if (!$kycId) {
                $this->db->rollback();
                $this->uploadService->delete('kyc/' . $filename);
                return ['success' => false, 'message' => 'خطا در ثبت احراز هویت'];
            }

            $newStatus = !empty($photoshopCheck['suspicious']) ? 'under_review' : 'pending';
            $this->db->query('UPDATE users SET kyc_status = ?, updated_at = NOW() WHERE id = ?', [$newStatus, $userId]);

            $this->db->commit();

            $this->outbox?->record('kyc', $kycId, 'kyc.status_changed', [
                'kyc_id'     => (int)$kycId,
                'user_id'    => $userId,
                'old_status' => null,
                'new_status' => $newStatus,
                'metadata'   => [
                    'photoshop_suspicious' => !empty($photoshopCheck['suspicious']),
                    'ai_verified'          => $aiCheck['is_valid'] ?? true,
                ],
            ]);

            return [
                'success' => true,
                'message' => 'درخواست احراز هویت ثبت شد',
                'kyc_id'  => (int)$kycId,
            ];
        } catch (\Core\Exceptions\BusinessException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            if (!empty($uploadResult['filename'])) {
                $this->uploadService->delete('kyc/' . $uploadResult['filename']);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            // اگر فایل آپلود شده اما DB ثبت نشده بود، orphan نماند
            if (!empty($uploadResult['filename'])) {
                $this->uploadService->delete('kyc/' . $uploadResult['filename']);
            }

            $this->logger->critical('kyc.submit.exception', [
                'channel'   => 'kyc',
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
                'file'      => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'kyc.submit',
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در ثبت احراز هویت'];
        }
    }
    

    /** @return array<string, mixed> */
    public function verify(int $kycId, int $adminId): array
    {
        try {
        $this->db->beginTransaction();

        $kyc = $this->toObject($this->kycModel->findForUpdate($kycId));
        if (!$kyc) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        // H-2: concurrency lock check
        if (!empty($kyc->under_review_by) && (int)$kyc->under_review_by !== $adminId) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'این درخواست توسط ادمین دیگری در حال بررسی است'];
        }

$okKyc = $this->kycModel->update($kycId, [
            'status' => 'verified',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'verified_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', (strtotime('+1 year') ?: time())),
            'rejection_reason' => null,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

if (!$okKyc) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $this->db->query(
            'UPDATE users SET kyc_status=?, kyc_level=GREATEST(COALESCE(kyc_level,0),1), kyc_verified_at=NOW(), updated_at=NOW() WHERE id=?',
            ['verified', (int)$kyc->user_id]
        );

        $this->db->commit();

        $this->outbox?->record('kyc', $kycId, 'kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'verified',
            'admin_id' => $adminId
        ]);

        // Dispatch class-based approved event for new listeners
        $this->outbox?->record('kyc', $kycId, KYCApprovedEvent::class, [
            'user_id' => (int)$kyc->user_id,
            'kyc_id' => $kycId,
        ]);

        $this->notificationService->kycVerified((int)$kyc->user_id);

        return ['success' => true, 'message' => 'KYC با موفقیت تایید شد'];
    } catch (\Throwable $e) {
        $this->db->rollback();

        $this->logger->critical('kyc.verify.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
            'operation' => 'kyc.verify',
            'kyc_id'   => $kycId,
            'admin_id' => $adminId,
        ]);
        return ['success' => false, 'message' => 'خطای سیستمی در تایید KYC'];
    }
    }
    

    /** @return array<string, mixed> */
    public function reject(int $kycId, int $adminId, string $reason): array
    {
        try {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'دلیل رد الزامی است'];
        }

        $this->db->beginTransaction();

        $kyc = $this->toObject($this->kycModel->findForUpdate($kycId));
        if (!$kyc) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        // H-2: concurrency lock check
        if (!empty($kyc->under_review_by) && (int)$kyc->under_review_by !== $adminId) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'این درخواست توسط ادمین دیگری در حال بررسی است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $this->db->query(
            'UPDATE users SET kyc_status=?, kyc_level=0, kyc_verified_at=NULL, updated_at=NOW() WHERE id=?',
            ['rejected', (int)$kyc->user_id]
        );

        $this->db->commit();

        $this->outbox?->record('kyc', $kycId, 'kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'rejected',
            'reason' => $reason,
            'admin_id' => $adminId
        ]);

        $this->notificationService->kycRejected((int)$kyc->user_id, $reason);

        return ['success' => true, 'message' => 'KYC با موفقیت رد شد'];
    } catch (\Throwable $e) {
        $this->db->rollback();

        $this->logger->critical('kyc.reject.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
            'operation' => 'kyc.reject',
            'kyc_id'   => $kycId,
            'admin_id' => $adminId,
        ]);
        return ['success' => false, 'message' => 'خطای سیستمی در رد KYC'];
    }
}

    /** @return array<string, mixed> */
    private function canSubmitKYC(int $userId): array
    {
        $existingKYC = $this->toObject($this->kycModel->findByUserId($userId));
        if (!$existingKYC) { 
        return ['can' => true];
        }

        if ($existingKYC->status === 'verified') {
            return ['can' => false, 'reason' => 'احراز هویت شما قبلاً تأیید شده است'];
        }

        if (in_array($existingKYC->status, ['pending', 'under_review'], true)) {
            return ['can' => false, 'reason' => 'درخواست قبلی شما در حال بررسی است'];
        }

        if ($existingKYC->status === 'rejected') {
            return ['can' => true];
        }

        return ['can' => true];
    }

    /**
     * Lightweight EXIF-based heuristic to flag retouched / re-saved images.
     * The actual fraud decision is taken by the operator + AI adapter.
     */
    /** @return array<string, mixed> */
    private function detectPhotoshop(string $imagePath): array
    {
        $suspicious = false;
        $reasons    = [];

        $exif = @exif_read_data($imagePath);
        if (is_array($exif)) {
            if (isset($exif['Software'])) {
                $software = strtolower(strval($exif['Software']));
                if (strpos($software, 'photoshop') !== false || strpos($software, 'gimp') !== false) {
                    $suspicious = true;
                    $reasons[]  = 'تصویر با نرم‌افزار ویرایش ساخته شده';
                }
            }

            if (isset($exif['DateTime'], $exif['DateTimeOriginal'])) {
                $diff = abs(strtotime(strval($exif['DateTime'])) - strtotime(strval($exif['DateTimeOriginal'])));
                if ($diff > 60) {
                    $suspicious = true;
                    $reasons[]  = 'اختلاف زمانی مشکوک بین ساخت و ویرایش';
                }
            }
        }

        if ($suspicious) {
            $this->logger->warning('kyc.image.suspicious', [
                'channel'    => 'kyc',
                'image_path' => basename($imagePath),
                'reasons'    => $reasons,
                'software'   => $exif['Software'] ?? null,
            ]);
        }

        return ['suspicious' => $suspicious, 'reasons' => $reasons];
    }
}
