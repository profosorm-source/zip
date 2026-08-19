<?php

namespace App\Services;

use App\Models\FileAccess;
use App\Contracts\LoggerInterface;
class FileAccessService
{


    private FileAccess $fileModel;
    /** پوشه‌هایی که بدون احراز هویت قابل دسترسی هستند */
    private const PUBLIC_FOLDERS = ['avatars', 'banners', 'captcha'];

    /** پوشه‌های حساس که دسترسی باید لاگ شود */
    private const SENSITIVE_FOLDERS = ['kyc', 'receipts', 'dispute-evidence'];

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        FileAccess $fileModel
    ) {        $this->logger = $logger;

                $this->fileModel = $fileModel;
    }
    /** @return array<string, mixed> */
    public function checkAccess(string $folder, string $filename, ?int $userId, bool $isAdmin): array
    {
        // ── ادمین: دسترسی کامل ──────────────────────────────────────────────
        if ($isAdmin) {
            return $this->allow();
        }

        // ── عمومی ────────────────────────────────────────────────────────────
        if (in_array($folder, self::PUBLIC_FOLDERS, true)) {
            return $this->allow();
        }

        // ── از اینجا login الزامی است ────────────────────────────────────────
        if (!$userId) {
            return $this->deny_result('برای مشاهده این فایل باید وارد سیستم شوید');
        }

        // ── per-folder ───────────────────────────────────────────────────────
        return match ($folder) {
            'kyc'                                             => $this->accessKyc($filename, $userId),
            'receipts'                                        => $this->accessReceipt($filename, $userId),
            'task-proofs'                                     => $this->accessTaskProof($filename, $userId),
            'task-samples'                                    => $this->accessTaskSample($filename, $userId),
            'ad-tasks'                                        => $this->accessAdTaskSample($filename, $userId),
            'dispute-evidence'                                => $this->accessDisputeEvidence($filename, $userId),
            'story-proofs', 'inf-proof', 'inf-brief'          => $this->accessStoryProof($filename, $userId),
            'story-media'                                     => $this->accessStoryMedia($filename, $userId),
            'influencer', 'influencer-profiles', 'influencer-verification' => $this->accessInfluencerProfile($filename, $userId),
            'ticket-attachments', 'bug-reports'               => $this->accessTicketAttachment($filename, $userId),
            'messages'                                        => $this->accessMessageAttachment($filename, $userId),
            default                                           => $this->deny_result('پوشه ناشناخته است'),
        };
    }

    public function isSensitiveFolder(string $folder): bool
    {
        return in_array($folder, self::SENSITIVE_FOLDERS, true);
    }

    public function logAccess(string $folder, string $filename, string $action, ?int $userId, string $ip): void
    {
        if ($userId === null) {
            return;
        }

        try {
            $this->fileModel->logFileAccess($folder, $filename, $userId, $action, $ip);
        } catch (\Throwable) {
            // silent
        }
    }

    public function logDeniedAccess(string $folder, string $filename, ?int $userId, string $ip): void
    {
        $logUserId = $userId ?? 0;

        // H-09 Fix: Log security warnings to system logs for brute force detection
        $this->logger->warning('file.access.denied', [
            'folder' => $folder,
            'filename' => $filename,
            'user_id' => $logUserId,
            'ip' => $ip
        ]);

        try {
            $this->fileModel->logDeniedFileAccess($folder, $filename, $logUserId, $ip);
        } catch (\Throwable) {
            // silent
        }
    }
    /** @return array<string, mixed> */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => ''];
    }
    /** @return array<string, mixed> */
    private function deny_result(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
    /** @return array<string, mixed> */
    private function accessKyc(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkKycOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessReceipt(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkReceiptOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessTaskProof(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTaskProofOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    /** @return array<string, mixed> */
    private function accessTaskSample(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTaskSampleOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    /** @return array<string, mixed> */
    private function accessAdTaskSample(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkAdTaskSampleOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    /** @return array<string, mixed> */
    private function accessDisputeEvidence(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkDisputeEvidenceOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessStoryProof(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkStoryProofOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessStoryMedia(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkStoryMediaOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessInfluencerProfile(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkInfluencerProfileOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function accessMessageAttachment(string $filename, int $userId): array
    {
        // M-14 FIX: only the sender or recipient of the direct message may view its attachment.
        if (!$this->fileModel->checkMessageAttachmentOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
    /** @return array<string, mixed> */
    private function accessTicketAttachment(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTicketAttachmentOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
}
