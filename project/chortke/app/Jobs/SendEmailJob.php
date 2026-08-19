<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EmailService;
use App\Services\EmailDeliveryStore;

/**
 * SendEmailJob — ارسال غیرمسدودکننده و پس‌زمینه ایمیل‌های سیستم چرتکه به صورت آنی
 */
class SendEmailJob
{
    private EmailService $emailService;
    private EmailDeliveryStore $emailStore;

    public function __construct(EmailService $emailService, EmailDeliveryStore $emailStore) {
        $this->emailService = $emailService;
        $this->emailStore = $emailStore;
    }

    /**
     * اجرای تسک ارسال ایمیل پس‌زمینه
     */
    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data): void
    {
        $emailId  = isset($data['email_id']) ? str_value($data['email_id']) : null;
        $toEmail  = str_value($data['to_email'] ?? '');
        $toName   = str_value($data['to_name'] ?? '');
        $subject  = str_value($data['subject'] ?? '');
        $bodyHtml = str_value($data['body_html'] ?? '');

        if (!$toEmail || !$subject || !$bodyHtml) {
            return;
        }

        // 🚀 BUG-01 Fix: Claim the email ID before processing
        // این کار باعث می‌شود اگر processQueue همزمان در حال اجرا باشد، تداخلی پیش نیاید.
        if ($emailId && !$this->emailStore->findAndLock($emailId)) {
            return; // قبلاً توسط پردازشگر دیگری (مثلاً کرون) برداشته شده است
        }

        // ارسال واقعی ایمیل از طریق SMTP
        $sent = $this->emailService->sendDirect($toEmail, $toName, $subject, $bodyHtml);

        if ($emailId) {
            if ($sent) {
                $this->emailStore->markAsSent($emailId);
            } else {
                $this->emailStore->markAsFailed($emailId, 'SMTP send failed via SendEmailJob background queue');
            }
        }
    }
}
