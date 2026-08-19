<?php
// app/Controllers/User/ContentController.php

namespace App\Controllers\User;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Services\ContentService;
use App\Controllers\User\BaseUserController;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\UnauthorizedException;
use App\Contracts\LoggerInterface;
use App\Validators\Requests\StoreContentRequest;

/**
 * کنترلر مدیریت محتوا
 * 
 * @package App\Controllers\User
 */
class ContentController extends BaseUserController
{
    private const ITEMS_PER_PAGE = 10;
    private const REVENUES_PER_PAGE = 15;
    
    private ContentService $contentService;
    private ContentSubmission $contentSubmissionModel;
    private ContentRevenue $contentRevenueModel;
    // $logger inherited from parent
    // $csrf inherited from parent

    public function __construct(
        ContentRevenue $contentRevenueModel,
        ContentSubmission $contentSubmissionModel,
        ContentService $contentService,
        \Core\CSRF $csrf,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
        $this->contentService = $contentService;
        $this->csrf = $csrf;
    }

    /**
     * صفحه لیست محتواهای کاربر
     * 
     * @return string HTML view
     */
    public function index(): string
    {
        try {
            $this->requireAuth();
            $user = $this->userService->find((int)$this->userId());

            // 🛡️ MUST HAVE KYC VERIFIED
            if (($user->kyc_status ?? '') !== 'verified') {
                $this->session->setFlash('error', 'جهت فعالیت در سیستم تولید محتوا، احراز هویت حساب شما باید تکمیل و تایید شده باشد.');
                redirect(url('/kyc'));
            }

            $userId = (int) user_id();
            
            $status = $this->sanitizeStatus($this->request->get('status'));
            $page = $this->sanitizePage($this->request->get('page'));
            $offset = ($page - 1) * self::ITEMS_PER_PAGE;

            // بهینه‌سازی: دریافت تمام داده‌ها با یک query
            $data = $this->contentSubmissionModel->getUserContentData(
                $userId, 
                $status, 
                self::ITEMS_PER_PAGE, 
                $offset
            );

            // MED-06 Fix: Removed redundant user find call

            view('user.content.index', [
                'user' => $user,
                'submissions' => $data['submissions'],
                'stats' => $data['stats'],
                'totalRevenue' => $data['totalRevenue'],
                'pendingRevenue' => $data['pendingRevenue'],
                'total' => $data['total'],
                'totalPages' => $data['totalPages'],
                'currentPage' => $page,
                'currentStatus' => $status,
            ]);
            return '';
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Error in content index', $e);
            view('errors.500');
            return '';
        }
    }

    /**
     * صفحه ارسال محتوای جدید
     * 
     * @return string HTML view
     */
    public function create(): string
    {
        try {
            $this->requireAuth();
            $user = $this->userService->find((int)$this->userId());

            // 🛡️ MUST HAVE KYC VERIFIED
            if (($user->kyc_status ?? '') !== 'verified') {
                $this->session->setFlash('error', 'جهت ثبت محتوای جدید ابتدا باید احراز هویت خود را تکمیل کنید.');
                redirect(url('/kyc'));
            }

            view('user.content.create', [
                'user' => $user,
                'agreementText' => $this->contentService->getAgreementText(),
                'settings' => $this->contentService->getSettings(),
            ]);
            return '';
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Error in content create', $e);
            view('errors.500');
            return '';
        }
    }

    /**
     * ثبت محتوای جدید (POST)
     * 
     * @return array<string, mixed> JSON response
     */
    /** @return void */
    public function store(): void
    {
        try {
            // خواندن و sanitize داده‌ها
            $input = $this->getJsonInput();
            
            // Validate CSRF Token
            if (!$this->validateCsrfToken()) {
                $this->response->json([
                    'success' => false,
                    'message' => 'توکن امنیتی نامعتبر است.',
                ], 403);
                return;
            }

            // اعتبارسنجی
            $validator = new StoreContentRequest($input);
            $validator->validate();

            if ($validator->fails()) {
                $this->response->json([
                    'success' => false,
                    'message' => 'اطلاعات ورودی نامعتبر است.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();
            
            // Sanitize input fields to prevent HTML Injection / Stored XSS (H-04)
            $data['title'] = strip_tags(str_value($data['title'] ?? ''));
            $data['description'] = strip_tags(str_value($data['description'] ?? ''), '<br><p><strong><em>');
            $data['video_url'] = trim(str_value($data['video_url'] ?? ''));
            
            // Submit content (Throws BusinessException on failure)
            $result = $this->contentService->submitContent((int) user_id(), $data);

            $this->response->json($result, 200);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Core\Exceptions\BusinessException $e) {
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => method_exists($e, 'getErrors') ? $e->getErrors() : [],
            ], 422);
        } catch (\Throwable $e) {
            $this->logError('Error in content store', $e);
            $this->response->json([
                'success' => false,
                'message' => 'خطای سیستمی در ثبت محتوا.',
            ], 500);
        }
    }

    /**
     * مشاهده جزئیات یک محتوا
     * 
     * @return string HTML view
     * @throws NotFoundException
     */
    public function show(): string
    {
        try {
            $id = $this->sanitizeId($this->request->param('id'));
            $userId = (int) user_id();

            $submission = $this->contentSubmissionModel->find((int)$id);

            if (!$submission || $submission->user_id !== $userId) {
                throw new NotFoundException('محتوای مورد نظر یافت نشد.');
            }

            // درآمدهای این محتوا
            $revenues = $this->contentRevenueModel->getBySubmission($id);
            $user = $this->userService->find((int)$this->userId());

            view('user.content.show', [
                'user' => $user,
                'submission' => $submission,
                'revenues' => $revenues,
            ]);
            return '';
            
        } catch (NotFoundException $e) {
            view('errors.404');
            return '';
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Error in content show', $e);
            view('errors.500');
            return '';
        }
    }

    /**
     * لیست درآمدها
     * 
     * @return string HTML view
     */
    public function revenues(): string
    {
        try {
            $userId = (int) user_id();
            $page = $this->sanitizePage($this->request->get('page'));
            $offset = ($page - 1) * self::REVENUES_PER_PAGE;

            $revenues = $this->contentRevenueModel->getByUser(
                $userId, 
                self::REVENUES_PER_PAGE, 
                $offset
            );
            
            $total = $this->contentRevenueModel->countByUser($userId);
            $totalPages = (int)ceil($total / self::REVENUES_PER_PAGE);

            // استفاده از cache برای آمار
            $totalPaid = $this->contentRevenueModel->getTotalUserRevenue(
                $userId, 
                ContentRevenue::STATUS_PAID
            );
            $totalPending = $this->contentRevenueModel->getTotalUserRevenue(
                $userId, 
                ContentRevenue::STATUS_PENDING
            );

            $user = $this->userService->find((int)$this->userId());

            view('user.content.revenues', [
                'user' => $user,
                'revenues' => $revenues,
                'totalPaid' => $totalPaid,
                'totalPending' => $totalPending,
                'total' => $total,
                'totalPages' => $totalPages,
                'currentPage' => $page,
            ]);
            return '';
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Error in content revenues', $e);
            view('errors.500');
            return '';
        }
    }

    /**
     * دریافت ورودی JSON به صورت امن
     * 
     * @return array
     */
    /** @return array<string, mixed> */
    private function getJsonInput(): array
    {
        $input = json_decode((file_get_contents('php://input') ?: ''), true);
        
        if (!is_array($input)) {
            $input = $this->request->body();
        }
        
        return is_array($input) ? $input : [];
    }

    /**
     * Sanitize وضعیت
     * 
     * @param mixed $status
     * @return string|null
     */
    private function sanitizeStatus($status): ?string
    {
        if (!is_string($status)) {
            return null;
        }
        
        $allowedStatuses = ContentSubmission::ALLOWED_STATUSES;
        return in_array($status, $allowedStatuses, true) ? $status : null;
    }

    /**
     * Sanitize شماره صفحه
     * 
     * @param mixed $page
     * @return int
     */
    private function sanitizePage($page): int
    {
        $page = filter_var($page, FILTER_VALIDATE_INT);
        return max(1, $page ?: 1);
    }

    /**
     * Sanitize شناسه
     * 
     * @param mixed $id
     * @return int
     */
    private function sanitizeId($id): int
    {
        return (int)filter_var($id, FILTER_VALIDATE_INT) ?: 0;
    }

    /**
     * بررسی CSRF Token
     * 
     * @return bool
     */
    private function validateCsrfToken(): bool
    {
        // M16 Fix: همسان‌سازی اعتبارسنجی برای پشتیبانی همزمان از هدر استاندارد و هدر کلاینت‌های Axios/Vue
        $token = is_string($this->request->header('X-CSRF-TOKEN')) 
            ? $this->request->header('X-CSRF-TOKEN') 
            : (is_string($this->request->header('X-XSRF-TOKEN')) ? $this->request->header('X-XSRF-TOKEN') : null);
        
        if (!$token) {
            return false;
        }
        
        return $this->csrf->verify($token);
    }

    private function logError(string $message, \Throwable $exception): void
    {
        $this->logger->error('content.error', [
            'channel' => 'content',
            'message' => $message,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'user_id' => function_exists('user_id') ? user_id() : null,
        ]);
    }
}
