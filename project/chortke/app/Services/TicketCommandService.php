<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Core\Database;
use Core\EventDispatcher;
use Core\RateLimiter;
use Core\TransactionWrapper;
use App\Contracts\ValidatorFactoryInterface;
use App\Contracts\LoggerInterface;
use App\Services\Shared\IdempotencyService;

class TicketCommandService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }


    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private EventDispatcher $events;
    private RateLimiter $rateLimiter;
    private IdempotencyService $idempotencyService;
    private ValidatorFactoryInterface $validatorFactory;
    private Database $db;
    private LoggerInterface $logger;

    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        ValidatorFactoryInterface $validatorFactory,
        EventDispatcher $eventDispatcher,
        Database $db,
        LoggerInterface $logger,
        Ticket $ticketModel,
        TicketMessage $messageModel,
        RateLimiter $rateLimiter,
        IdempotencyService $idempotencyService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->validatorFactory = $validatorFactory;
        $this->events = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->rateLimiter = $rateLimiter;
        $this->idempotencyService = $idempotencyService;
        $this->outbox = $outbox;
    }

    /** @param array<string, mixed> $data */
    public function guardCanCreateTicket(int $userId, array $data): void
    {
        if ($userId <= 0) {
            throw new \App\Exceptions\BusinessException('شناسه کاربر نامعتبر است');
        }

        $mergedData = array_merge([
            'category' => 'technical',
            'priority' => 'normal',
            'idempotency_key' => 'ticket_init_' . $userId . '_' . time(),
        ], $data);

        $rules = [
            'subject'        => 'required|string|min:5|max:200',
            'message'        => 'required|string|min:20|max:5000',
            'category'       => 'required|in:technical,billing,account,other',
            'priority'       => 'required|in:low,normal,high,urgent',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];

        try {
            $this->validate($mergedData, $rules, [], true);
        } catch (\Core\Exceptions\ValidationException $e) {
            throw new \App\Exceptions\BusinessException($this->formatValidationErrors($e->getErrors()));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: false, message: string}|array{success: true, message: string, ticket_id: int}
     */
    public function create(int $userId, array $data): array
    {
        try {
            $this->guardCanCreateTicket($userId, $data);
        } catch (\App\Exceptions\BusinessException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
        
        if (!$this->rateLimiter->attempt("ticket_creation:{$userId}", 3, 3600)) {
            $this->logger->warning('ticket.rate_limit_exceeded', ['user_id' => $userId]);
            return [
                'success' => false,
                'message' => 'شما اخیراً تیکت‌های زیادی ایجاد کرده‌اید. لطفاً کمی صبر کرده و مجدداً امتحان کنید.'
            ];
        }

        $categoryId = isset($data['category_id']) ? int_value($data['category_id']) : 0;
        if ($categoryId <= 0) {
            return [
                'success' => false,
                'message' => 'انتخاب دسته‌بندی تیکت الزامی است.'
            ];
        }

        $subject = htmlspecialchars(strip_tags(str_value($data['subject'])), ENT_QUOTES, 'UTF-8', false);

        $priority = $data['priority'] ?? 'normal';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if ($priority === 'normal') {
            $priority = $this->detectPriority($subject . ' ' . $data['message'], $categoryId);
        }

        $idempotencyKey = $data['idempotency_key'] ?? 'ticket_init_' . $userId . '_' . time();

        return $this->idempotencyService->executeWithTransaction(
            'ticket.create',
            $userId,
            $data,
            function() use ($userId, $categoryId, $subject, $priority, $data) {
                $ticketId = $this->ticketModel->create([
                    'user_id'     => $userId,
                    'category_id' => $categoryId,
                    'subject'     => $subject,
                    'priority'    => $priority,
                    'status'      => 'open',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);

                if (!$ticketId) {
                    throw new \RuntimeException('خطا در ثبت تیکت جدید.');
                }

                $msgData = [
                    'ticket_id' => $ticketId,
                    'user_id'   => $userId,
                    'message'   => htmlspecialchars(strip_tags(str_value($data['message'])), ENT_QUOTES, 'UTF-8', false),
                    'is_admin'  => 0,
                    'created_at'=> date('Y-m-d H:i:s'),
                ];
                
                if (!$this->messageModel->create($msgData)) {
                    throw new \RuntimeException('خطا در ثبت پیام تیکت.');
                }

                $this->logger->info('ticket.created', [
                    'ticket_id' => $ticketId,
                    'user_id'   => $userId,
                    'priority'  => $priority
                ]);

                $this->events->dispatch('ticket.created', ['ticket_id' => $ticketId, 'user_id' => $userId]);

                return [
                    'success' => true,
                    'message' => 'تیکت شما با موفقیت ثبت شد.',
                    'ticket_id' => $ticketId
                ];
            },
            $idempotencyKey === null ? null : str_value($idempotencyKey)
        );
    }

    /**
     * @param list<array<string, mixed>> $attachments
     * @return array<string, mixed>
     */
    public function reply(int $ticketId, int $userId, string $message, bool $isAdmin = false, array $attachments = []): array
    {
        if (!$isAdmin) {
            if (!$this->rateLimiter->attempt("ticket_reply:{$userId}", 5, 3600)) {
                $this->logger->warning('ticket.reply.rate_limit_exceeded', ['user_id' => $userId, 'ticket_id' => $ticketId]);
                return [
                    'success' => false,
                    'message' => 'تعداد پیام‌های ارسالی شما بیش از حد مجاز ساعتی است. لطفا کمی صبر کنید.'
                ];
            }
        }

        try {
            return $this->idempotencyService->executeWithTransaction(
                'ticket.reply',
                $userId,
                ['ticket_id' => $ticketId, 'message' => $message],
                function() use ($ticketId, $userId, $message, $isAdmin, $attachments) {
                $ticket = $this->toObject($this->db->fetch("SELECT * FROM tickets WHERE id = ? FOR UPDATE", [$ticketId]));
                
                if (!$ticket) {
                    return ['success' => false, 'message' => 'تیکت یافت نشد.'];
                }
                
                if (!$isAdmin && (int)$ticket->user_id !== $userId) {
                    return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
                }
                
                if ($ticket->status === 'closed' && !$isAdmin) {
                    return ['success' => false, 'message' => 'تیکت بسته شده است.'];
                }

                $errors = $this->validate(['message' => $message], ['message' => 'required|string|max:5000'], [], false);
                if ($errors) {
                    return ['success' => false, 'message' => 'متن پاسخ نباید بیشتر از ۵۰۰۰ کاراکتر باشد.'];
                }

                $this->messageModel->create([
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8', false),
                    'attachments' => $attachments,
                    'is_admin' => $isAdmin
                ]);
                
                $this->ticketModel->updateLastReply($ticketId, $isAdmin ? 'admin' : 'user');
                
                $this->outbox?->record('ticket', $ticketId, 'ticket.replied', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'is_admin' => $isAdmin,
                    'subject' => $ticket->subject,
                ]);
                
                return [
                    'success' => true,
                    'message' => 'پاسخ با موفقیت ثبت شد.'
                ];
            }, null);
            
        } catch (\Exception $e) {
            $this->logger->error('ticket.reply.failed', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطایی در ارسال پاسخ رخ داد. لطفاً دوباره تلاش کنید.'
            ];
        }
    }

    /** @return array<string, mixed> */
    public function close(int $ticketId, int $userId, bool $isAdmin = false): array
    {
        $ticket = $this->ticketModel->findById($ticketId);
        if (!$ticket) {
            return ['success' => false, 'message' => 'تیکت یافت نشد.'];
        }
        if (!$isAdmin && (int)$ticket->user_id !== $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($this->ticketModel->updateStatus($ticketId, 'closed')) {
            $this->logger->activity('ticket_closed', "تیکت #{$ticketId} بسته شد", $userId, []);
            return ['success' => true, 'message' => 'تیکت بسته شد.'];
        }
        return ['success' => false, 'message' => 'خطا در بستن تیکت.'];
    }

    public function detectPriority(string $text, int $categoryId = 0): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $urgentKeywords = ['فوری', 'اورژانس', 'بحرانی', 'خراب شد', 'کار نمیکند', 'هک', 'امنیتی', 'سرقت', 'پول', 'پرداخت نشد', 'کلاهبرداری', 'فیشینگ', 'واریز نشد'];
        $highKeywords = ['مهم', 'سریع', 'مشکل دارد', 'خطا', 'ارور', 'کار نمیکنه', 'خراب', 'باگ', 'bug', 'error', 'قطعی'];
        
        foreach ($urgentKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) return 'urgent';
        }
        foreach ($highKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) return 'high';
        }
        if (in_array($categoryId, [1, 2, 3, 4], true)) return 'high';
        return 'normal';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules, array $messages = [], bool $throw = false): array
    {
        $validator = $this->validatorFactory->make($data, $rules, $messages, $this->db);
        if ($throw) {
            return $validator->validateOrFail();
        }
        $result = $validator->result();
        return $result['valid'] ? [] : $result['errors'];
    }

    /** @param array<string, mixed> $errors */
    private function formatValidationErrors(array $errors): string
    {
        $messages = [];
        foreach ((array)$errors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $err) {
                $messages[] = $err;
            }
        }
        return implode(' | ', $messages);
    }

    /** @return array<string, mixed> */
    public function parseBrowser(?string $ua): array
    {
        if (!$ua) {
            return ['browser' => null, 'os' => null];
        }

        $browser = 'Unknown';
        $os = 'Unknown';

        if (\preg_match('/Edg[e]?\/(\S+)/i', $ua)) {
            $browser = 'Edge';
        } elseif (\preg_match('/OPR\/(\S+)/i', $ua)) {
            $browser = 'Opera';
        } elseif (\preg_match('/Chrome\/(\S+)/i', $ua)) {
            $browser = 'Chrome';
        } elseif (\preg_match('/Firefox\/(\S+)/i', $ua)) {
            $browser = 'Firefox';
        } elseif (\preg_match('/Safari\/(\S+)/i', $ua) && !\preg_match('/Chrome/i', $ua)) {
            $browser = 'Safari';
        }

        if (\preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (\preg_match('/Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (\preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        } elseif (\preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (\preg_match('/iPhone|iPad/i', $ua)) {
            $os = 'iOS';
        }

        return ['browser' => $browser, 'os' => $os];
    }
}
