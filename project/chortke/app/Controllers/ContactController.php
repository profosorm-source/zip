<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use App\Validators\Requests\ContactMessageRequest;

/**
 * Contact Form Controller
 */
class ContactController extends BaseController
{
    private \App\Services\ContactService $contactService;

    public function __construct(\App\Services\ContactService $contactService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->contactService = $contactService;
    }

    /**
     * ارسال پیام تماس
     */
    public function send(): void
    {
        $this->validateCsrf();

        // 🛡️ MED-03: استفاده از نام‌های فیلد گمراه‌کننده جهت جلوگیری از دور زدن Honeypot توسط بات‌های هوشمند
        $honeypots = ['user_name', 'confirm_email', 'address', 'phone_number_ext'];
        foreach ($honeypots as $field) {
            if (!empty($this->request->input($field))) {
                // لاگ آی‌پی بات
                $ip = $this->request->ip();
                $this->logger->warning('honeypot_triggered', ['ip' => $ip, 'field' => $field]);
                
                // بازگشت پاسخ موفق دروغین به بات‌ها
                $this->response->json([
                    'success' => true,
                    'message' => 'پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.'
                ]);
            }
        }

        $formRequest = new ContactMessageRequest($this->request->all());
        if (!$formRequest->validate()) {
            $errors = $formRequest->errors();
            $first = reset($errors);
            $firstError = is_array($first) ? reset($first) : $first;
            $this->response->json([
                'success' => false,
                'message' => $firstError ?: 'اطلاعات ورودی نامعتبر است',
                'errors'  => $errors,
            ], 422);
            return;
        }
        $validated = $formRequest->validated();
        $name    = str_value($validated['name'] ?? '');
        $subject = str_value($validated['subject'] ?? '');
        $email   = str_value($validated['email'] ?? '');
        $message = str_value($validated['message'] ?? '');

        $data = [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'website' => $this->request->input('website'),
            'captcha_token' => $this->request->input('captcha_token'),
            'captcha_response' => $this->request->input('captcha_response'),
        ];

        $result = $this->contactService->sendMessage($data);

        if (!$result['success']) {
            $statusCode = int_value($result['status_code'] ?? 422);
            $this->response->json(['success' => false, 'message' => $result['message'], 'errors' => $result['errors'] ?? []], $statusCode);
            return;
        }

        $this->response->json(['success' => true, 'message' => $result['message']]);
    }
}