<?php

namespace App\Controllers\User;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class TicketController extends BaseUserController
{
    private TicketService $ticketService;
    private UploadService $uploadService;
    
    public function __construct(
        \App\Services\TicketService $ticketService,
        \App\Services\UploadService $uploadService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
    }
    
    /**
     * لیست تیکت‌ها
     */
    public function index(): void
    {
        // 🛡️ MEDIUM-01: Rate limit on read endpoint to prevent metadata scraping and DOS
        rate_limit('social', 'ticket_list', "user_" . user_id());
        $userId = int_value(session()->get(\App\Constants\SessionKeys::USER_ID) ?: 1);
        
        $status = is_string($this->request->get('status', '')) ? $this->request->str('status') : null;
        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;
        
        $result = $this->ticketService->listUserTickets($userId, $status, $page, $perPage);
        $tickets = $result['tickets'] ?? [];
        $total = $result['total'] ?? 0;
        $totalPages = ceil($total / $perPage);
        
        // شمارش خوانده نشده
        $unreadCount = $this->ticketService->countUnread($userId, false);
        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06)
        $userObj = $this->loadCurrentUserOrRedirect();
        
        $this->view('user/tickets/index', [
            'tickets' => $tickets,
            'status' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'unreadCount' => $unreadCount,
            'user' => $userObj
        ]);
    }
    
    /**
     * فرم ایجاد تیکت
     */
    public function create(): void
    {
        $categories = $this->ticketService->getCategories();
        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06)
        $userObj = $this->loadCurrentUserOrRedirect();
        
        $this->view('user/tickets/create', [
            'categories' => $categories,
            'user' => $userObj
        ]);
    }
    
    /**
     * ذخیره تیکت جدید
     */
    public function store(): void
    {
        $userId = (int) user_id();

        $data = $this->request->all();
        $data['message'] = trim(str_value($data['message'] ?? ''));
        if (!isset($data['category_id']) && isset($data['department'])) {
            $data['category_id'] = is_numeric($data['department']) ? (int)$data['department'] : 1;
        }
        if (!isset($data['category_id']) || int_value($data['category_id']) <= 0) {
            $data['category_id'] = 1;
        }
        if (isset($data['priority']) && $data['priority'] === 'medium') {
            $data['priority'] = 'normal';
        }
        
        // Validation
        $validationData = is_array($data) ? $data : [];
        $validator = $this->validatorFactory()->make($validationData, [
            'category_id' => 'required|integer',
            'subject' => 'required|min:5|max:150',
            'message' => 'required|min:10|max:5000',
            'priority' => 'required|in:low,normal,high,urgent'
        ]);
        
        if ($validator->fails()) {
            session()->setFlash('error', 'لطفاً تمام فیلدها را به درستی پر کنید.');
            session()->setFlash('errors', $validator->errors());
            session()->setFlash('old', $data);
            redirect('/tickets/create');
        }
        
        // آپلود فایل
        $attachments = [];
        
        if ($this->request->hasFile('attachments')) {
            $files = $this->request->uploadedFiles('attachments');

            if (count($files) > 5) {
                session()->setFlash('error', 'حداکثر ۵ فایل مجاز است.');
                redirect('/tickets/create');
            }
            
            foreach ($files as $file) {
                // 🛡️ CRITICAL-02: Validate Magic Bytes using finfo to strictly enforce allowed formats
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo === false) {
                    session()->setFlash('error', 'فایل ' . htmlspecialchars(str_value($file['name'] ?? 'نامعتبر'), ENT_QUOTES, 'UTF-8') . ' نوع یا محتوای نامعتبری دارد.');
                    redirect('/tickets/create');
                }
                $mimeType = finfo_file($finfo, str_value($file['tmp_name']));
                finfo_close($finfo);
                
                if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                    $this->logger->warning('ticket.attachment.invalid_magic_bytes', ['user_id' => $userId, 'mime' => $mimeType]);
                    session()->setFlash('error', 'فایل ' . htmlspecialchars(str_value($file['name'] ?? 'نامعتبر'), ENT_QUOTES, 'UTF-8') . ' نوع یا محتوای نامعتبری دارد.');
                    redirect('/tickets/create');
                }

                $uploadResult = $this->uploadService->upload(
                    $file,
                    'ticket_attachments',
                    ['image/jpeg', 'image/png'],
                    3 * 1024 * 1024 // 3MB
                );
                
                if (!$uploadResult['success']) {
                    $this->logger->warning('ticket.attachment.failed', [
                        'user_id' => $userId,
                        'error' => $uploadResult['message'] ?? 'Unknown upload error'
                    ]);
                    session()->setFlash('error', 'خطا در آپلود فایل پیوست. لطفاً دوباره امتحان کنید.');
                    redirect('/tickets/create');
                }

                if (!$this->uploadService->getPath($uploadResult['path'])) {
                    $this->logger->warning('ticket.attachment.invalid_path', ['user_id' => $userId, 'path' => $uploadResult['path']]);
                    session()->setFlash('error', 'مسیر فایل پیوست نامعتبر است.');
                    redirect('/tickets/create');
                }
                
                // 🛡️ MEDIUM-02: فیلتر کاراکترهای نام پیوست با Regex و basename جهت ارتقای امنیت نام فایل
                $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename(str_value($file['name'])));
                $attachments[] = [
                    'name' => htmlspecialchars(str_value($cleanName), ENT_QUOTES, 'UTF-8'),
                    'path' => $uploadResult['path']
                ];
            }
        }
        
        $data['attachments'] = $attachments;
        
        // ایجاد تیکت
        $result = $this->ticketService->create($userId, $data);
        
        if ($result['success']) {
            session()->setFlash('success', $result['message']);
            redirect('/tickets/show/' . $result['ticket_id']);
        }
        
        session()->setFlash('error', $result['message']);
        session()->setFlash('old', $data);
        redirect('/tickets/create');
    }
    
    /**
     * نمایش تیکت
     */
    public function show(int $id): string
    {
        $userId = (int) user_id();
        
        $ticket = $this->ticketService->getById($id);
        
        if (!$ticket) {
            $this->session->setFlash('error', 'تیکت یافت نشد.');
            redirect('/tickets');
        }

        if ((int)$ticket->user_id !== $userId) {
            $this->session->setFlash('error', 'شما به این تیکت دسترسی ندارید.');
            redirect('/tickets');
        }
        
        $messages = $this->ticketService->getMessages($id);
        
        // علامت‌گذاری به عنوان خوانده شده
        $this->ticketService->markAsRead($id, false);
        
        return view('user/tickets/show', [
            'ticket' => $ticket,
            'messages' => $messages,
            'user' => user()
        ]);
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply(): void
    {
        $this->validateCsrf();
        $userId = (int) user_id();

        // پشتیبانی از هر دو حالت: JSON ساده و FormData (با فایل پیوست)
        $contentType = str_value($this->request->header('content-type'));
        if (str_contains($contentType, 'application/json')) {
            $data = $this->request->json();
        } elseif (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $data = $this->request->all();
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'نوع محتوا پشتیبانی نمی‌شود.'
            ], 415);
            return;
        }

        $validationData = is_array($data) ? $data : [];
        $validator = $this->validatorFactory()->make($validationData, [
            'ticket_id' => 'required|integer',
            'message'   => 'required|min:5|max:5000'
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'پیام نامعتبر است.',
                'errors'  => $validator->errors()
            ]);
            return;
        }

        // 🛡️ CRITICAL-01: Sanitization is cleanly handled in TicketService to prevent double encoding
        $messageRaw = trim(str_value($data['message'] ?? ''));
        $message = $messageRaw;

        // 🛡️ IDOR Check: بررسی مالکیت تیکت قبل از پاسخ
        $ticketId = int_value($data['ticket_id'] ?? 0);
        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket || (int)$ticket->user_id !== $userId) {
            $this->response->json([
                'success' => false, 
                'message' => 'دسترسی غیرمجاز.'
            ], 403);
            return;
        }

        // 🛡️ MEDIUM-02: Prevent users from replying to already closed tickets directly at the controller edge
        if ($ticket->status === 'closed') {
            $this->response->json([
                'success' => false,
                'message' => 'امکان ارسال پاسخ برای تیکت بسته شده وجود ندارد.'
            ], 403);
            return;
        }

        // پردازش فایل‌های پیوست با استفاده از Request Wrapper
        $attachments = [];
        $attachmentsValue = $this->request->file('attachments');
        $attachmentsFile = is_array($attachmentsValue) ? $attachmentsValue : [];
        $names = $attachmentsFile['name'] ?? null;
        if ($names !== null && $names !== '' && $names !== []) {
            $filesCount = is_array($names) ? count($names) : 1;
            if ($filesCount > 5) {
                $this->response->json([
                    'success' => false,
                    'message' => 'حداکثر ۵ فایل مجاز است.'
                ], 400);
                return;
            }

            // نرمال سازی فایل ها جهت پردازش صحیح تک فایل یا چند فایل
            $normalizedFiles = [];
            if (!is_array($names)) {
                $normalizedFiles[] = [
                    'name' => str_value($names),
                    'type' => str_value($attachmentsFile['type'] ?? ''),
                    'tmp_name' => str_value($attachmentsFile['tmp_name'] ?? ''),
                    'error' => int_value($attachmentsFile['error'] ?? UPLOAD_ERR_NO_FILE),
                    'size' => int_value($attachmentsFile['size'] ?? 0),
                ];
            } else {
                $types = is_array($attachmentsFile['type'] ?? null) ? $attachmentsFile['type'] : [];
                $temporaryNames = is_array($attachmentsFile['tmp_name'] ?? null) ? $attachmentsFile['tmp_name'] : [];
                $errors = is_array($attachmentsFile['error'] ?? null) ? $attachmentsFile['error'] : [];
                $sizes = is_array($attachmentsFile['size'] ?? null) ? $attachmentsFile['size'] : [];
                foreach ($names as $key => $name) {
                    $normalizedFiles[] = [
                        'name' => str_value($name),
                        'type' => str_value($types[$key] ?? ''),
                        'tmp_name' => str_value($temporaryNames[$key] ?? ''),
                        'error' => int_value($errors[$key] ?? UPLOAD_ERR_NO_FILE),
                        'size' => int_value($sizes[$key] ?? 0),
                    ];
                }
            }

            foreach ($normalizedFiles as $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    // 🛡️ HIGH-02: Validate Magic Bytes using finfo in reply attachments as well
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo === false) {
                        continue;
                    }
                    $mimeType = finfo_file($finfo, str_value($file['tmp_name']));
                    finfo_close($finfo);
                    
                    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                        $this->logger->warning('ticket.reply.attachment.invalid_magic_bytes', ['user_id' => $userId, 'mime' => $mimeType]);
                        continue;
                    }

                    $uploadResult = $this->uploadService->upload(
                        $file,
                        'ticket_attachments',
                        ['image/jpeg', 'image/png'],
                        3 * 1024 * 1024
                    );
                    if (!$uploadResult['success']) {
                        $this->logger->warning('ticket.reply.attachment.failed', [
                            'user_id' => $userId,
                            'error' => $uploadResult['message'] ?? 'Unknown upload error'
                        ]);
                        continue;
                    }
                    
                    // 🛡️ MEDIUM-02: فیلتر کاراکترهای نام پیوست با Regex و basename جهت ارتقای امنیت نام فایل
                    $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename(str_value($file['name'])));
                    $attachments[] = [
                        'name' => htmlspecialchars(str_value($cleanName), ENT_QUOTES, 'UTF-8'),
                        'path' => $uploadResult['path'],
                    ];
                }
            }
        }

        $result = $this->ticketService->reply(
            $ticketId,
            $userId,
            $message,
            false,
            $attachments
        );

        $this->response->json($result);
    }

    /**
     * بستن تیکت
     */
    public function close(): void
    {
        $data     = $this->request->json();
        $ticketId = int_value($data['id'] ?? 0);

        if (!$ticketId) {
            $this->response->json(['success' => false, 'message' => 'شناسه نامعتبر است.']);
            return;
        }

        $result = $this->ticketService->close($ticketId, (int) user_id(), false);

        $this->response->json($result);
    }

    /**
     * دانلود پیوست تیکت با بررسی دسترسی و احراز هویت (مبارزه با IDOR)
     */
    public function downloadAttachment(string $filename): void
    {
        $this->requireAuth();
        
        // ۱. ضدعفونی نام فایل پیوست
        $filename = basename($filename);
        
        // ۲. پیدا کردن پیام مرتبط با فایل پیوست در دیتابیس
        $attachment = $this->ticketService->getAttachmentMessage($filename);
        
        if (!$attachment) {
            $this->response->json(['success' => false, 'message' => 'فایل یافت نشد.'], 404);
            return;
        }
        
        $ticket = $this->ticketService->getById((int)$attachment->ticket_id);
        if (!$ticket) {
            $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.'], 404);
            return;
        }
        
        // ۳. بررسی دسترسی کاربر
        $userId = (int) user_id();
        $isAdmin = function_exists('is_admin') ? is_admin() : false;
        
        if (!$isAdmin && (int)$ticket->user_id !== $userId) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }
        
        // ۴. خواندن و سرو فایل به صورت ایمن
        $path = base_path("storage/uploads/ticket_attachments/{$filename}");
        if (!file_exists($path)) {
            $this->response->json(['success' => false, 'message' => 'فایل در سرور یافت نشد.'], 404);
            return;
        }
        
        // پاک کردن هرگونه بافر خروجی
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: ' . mime_content_type($path));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}