<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;
use App\Services\DirectMessageService;
use App\Validators\Requests\SendMessageRequest;

/**
 * MessageController - مدیریت پیام‌های مستقیم کاربران
 */
class MessageController extends BaseUserController
{
    private DirectMessageService $messageService;
    private \App\Services\UploadService $uploadService;

    public function __construct(
        DirectMessageService $messageService,
        \App\Services\UploadService $uploadService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->messageService = $messageService;
        $this->uploadService = $uploadService;
    }

    /**
     * لیست conversations
     */
    public function index(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $page = int_value($this->request->query('page') ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;

            $conversations = $this->messageService->getConversations($userId, $limit, $offset);
            $unreadTotal = $this->messageService->getUnreadCount($userId);

            $this->view('user/messages/index', [
                'conversations' => $conversations,
                'unread_total' => $unreadTotal,
                'page' => $page
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('messages.index.failed', ['error' => $e->getMessage()]);
            $this->response->error('خطا در بارگذاری پیام‌ها');
        }
    }

    /**
     * نمایش conversation
     */
    public function show(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $otherUserId = (int)$this->request->param('id');
            $page = int_value($this->request->query('page') ?? 1);
            $limit = 50;
            $offset = ($page - 1) * $limit;

            if ($userId === $otherUserId) {
                $this->response->error('نمی‌توانید برای خودتان پیام بفرستید');
                return;
            }

            $otherUser = $this->messageService->getUserInfo($otherUserId, $userId);

            if (!$otherUser) {
                $this->response->error('مکالمه‌ای با کاربر مورد نظر یافت نشد.', [], 404);
                return;
            }

            // 🛡️ HIGH-06: جلوگیری از نمایش مکالمه‌های خالی یا ثبت سوابق نامطلوب بدون تاریخچه معتبر بین طرفین
            if (!$this->messageService->hasConversation($userId, $otherUserId)) {
                $this->response->error('مکالمه‌ای با کاربر مورد نظر یافت نشد.', [], 404);
                return;
            }

            $messages = $this->messageService->getConversation(
                $userId,
                $otherUserId,
                $limit,
                $offset
            );
            // Opening a conversation is the authoritative read boundary.
            // Persist both is_read/read_at and clear its Redis unread counter.
            $this->messageService->markAsRead($userId, $otherUserId);

            $this->view('user/messages/show', [
                'messages' => $messages,
                'other_user' => $otherUser,
                'page' => $page
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('messages.show.failed', ['error' => $e->getMessage()]);
            $this->response->error('خطا در بارگذاری conversation');
        }
    }

    /**
     * ارسال پیام
     */
    public function send(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            // 🛡️ Rate Limit: حداکثر ۳۰ پیام در دقیقه برای هر کاربر
            $rateLimiter = app(\Core\RateLimiter::class); // TODO: inject via constructor
            if (!$rateLimiter->attempt("dm_send:{$userId}", 30, 60, true)) {
                $this->jsonError('تعداد پیام‌های ارسالی بیش از حد مجاز است. لطفاً کمی صبر کنید.', [], 429);
                return;
            }

            $recipientId = $this->request->int('recipient_id');
            if ($recipientId <= 0) {
                $recStr = str_value($this->request->input('recipient') ?? $this->request->input('recipient_id'));
                if ($recStr !== '') {
                    $recUser = $this->userService->findByCredentials($recStr);
                    if ($recUser) {
                        $recipientId = (int)$recUser->id;
                    }
                }
            }
            $message = trim($this->request->str('message'));
            $isEncrypted = (bool)$this->request->input('is_encrypted', false);

            // اعتبارسنجی
            $formRequest = new SendMessageRequest([
                'recipient_id' => $recipientId,
                'message'      => $message,
                'is_encrypted' => $isEncrypted ? 1 : 0,
            ]);
            if (!$formRequest->validate()) {
                $errors = $formRequest->errors();
                $firstValue = reset($errors);
                $firstError = is_array($firstValue) ? reset($firstValue) : $firstValue;
                $this->jsonError($firstError ?: 'اطلاعات ورودی نامعتبر است', $errors, 422);
                return;
            }
            $validated = $formRequest->validated();
            $recipientId = int_value($validated['recipient_id'] ?? $recipientId);
            $message     = str_value($validated['message'] ?? $message);

            // Attachments
            $attachments = [];
            if ($this->request->hasFiles()) {
                $attachments = $this->handleAttachments($this->request->files());
            }

            // ارسال پیام
            $result = $this->messageService->sendMessage(
                $userId,
                $recipientId,
                $message,
                $attachments,
                $isEncrypted
            );

            if (isset($result['error'])) {
                $this->jsonError(str_value($result['error']), [], 422);
                return;
            }

            $this->logger->info('message.sent_by_user', [
                'user_id' => $userId,
                'recipient_id' => $recipientId,
                'message_id' => $result['message_id']
            ]);

            $this->jsonSuccess('', $result);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('message.send.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در ارسال پیام', [], 500);
        }
    }

    /**
     * typing indicator
     */
    public function setTyping(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $userId = (int)$this->userId();
            $recipientId = $this->request->int('recipient_id');
            $isTyping = (bool)$this->request->input('is_typing', true);

            // 🛡️ NEW-15: اعمال محدودیت نرخ بروزرسانی وضعیت تایپ (محدودیت ۲۰ درخواست در دقیقه جهت جلوگیری از فالس پوزیتیو)
            $rateLimiter = app(\Core\RateLimiter::class); // TODO: inject via constructor
            $rateLimitId = "typing_limit:" . $userId;
            if (!$rateLimiter->attempt($rateLimitId, 20, 60, true)) {
                $this->jsonError('تعداد درخواست‌های تایپ بیش از حد مجاز است.', [], 429);
                return;
            }

            $this->messageService->setTyping($userId, $recipientId, $isTyping);

            $this->jsonSuccess('', ['ok' => true]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('typing.set.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * دریافت typing users
     */
    public function getTypingUsers(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            $typingUsers = $this->messageService->getTypingUsers($userId);

            $this->jsonSuccess('', [
                'typing_users' => $typingUsers,
                'count' => count($typingUsers)
            ]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('typing.get.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * حذف پیام
     */
    public function delete(): void
    {
        $this->requireAuth();

        try {
            // CORE-036: CSRF Protection
            $this->validateCsrf();

            $userId = (int)$this->userId();
            $messageId = (int)$this->request->param('id');

            $success = $this->messageService->deleteMessage($messageId, $userId);

            if (!$success) {
                $this->jsonError('پیام یافت نشد', [], 404);
                return;
            }

            $this->jsonSuccess('پیام با موفقیت حذف شد', ['ok' => true]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('message.delete.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در حذف پیام', [], 500);
        }
    }

    /**
     * اضافه کردن reaction
     */
    public function addReaction(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $userId = (int)$this->userId();
            $messageId = (int)$this->request->param('id');
            $emoji = trim($this->request->str('emoji'));

            // 🛡️ NEW-14: اعتبارسنجی ورودی واکنش‌ها بر اساس لیست سفید اموجی‌های مجاز
            $allowedEmojis = ['👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '🎉'];
            if (!in_array($emoji, $allowedEmojis, true)) {
                $this->jsonError('واکنش نامعتبر است.', [], 422);
                return;
            }

            $success = $this->messageService->addReaction($messageId, $userId, $emoji);

            if (!$success) {
                $this->jsonError('خطا در اضافه کردن reaction', [], 422);
                return;
            }

            $this->jsonSuccess('واکنش با موفقیت ثبت شد', ['ok' => true]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * مدیریت پیوست‌ها با استفاده از UploadService
     * @param array<string, mixed> $files
     * @return list<array<string, mixed>>
     */
    private function handleAttachments(array $files): array
    {
        $attachments = [];
        
        // اگر چند فایل باشد
        if (isset($files['name']) && !is_array($files['name'])) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $result = $this->uploadService->upload(
                $file, 
                'messages', 
                // PDF FIX: allow images + PDF only. ZIP is intentionally NOT permitted.
                ['jpg', 'png', 'jpeg', 'pdf'], 
                10 * 1024 * 1024 // 10MB
            );

            if ($result['success']) {
                $attachments[] = [
                    'filename' => $file['name'],
                    'file_path' => $result['path'],
                    'file_size' => $file['size'],
                    'mime_type' => $file['type']
                ];
            }
        }

        return $attachments;
    }

    /**
     * دریافت تعداد پیام‌های خوانده نشده
     */
    public function getUnreadCount(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $count = $this->messageService->getUnreadCount($userId);

            $this->jsonSuccess('', ['count' => $count]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('unread.count.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * علامت‌گذاری پیام‌ها به عنوان خوانده شده (HIGH-03)
     */
    public function markRead(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $otherUserId = $this->request->int('user_id');
            if ($otherUserId <= 0) {
                $this->jsonError('شناسه کاربر نامعتبر است', [], 400);
                return;
            }

            $this->messageService->markAsRead((int)$this->userId(), $otherUserId);
            $this->jsonSuccess('پیام‌ها به عنوان خوانده شده علامت‌گذاری شدند');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('mark.read.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در علامت‌گذاری پیام‌ها', [], 500);
        }
    }

}