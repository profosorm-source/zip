<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Models\ContentAgreement;
use App\Models\ContentRevenue;
use App\Models\ContentSubmission;
use App\Services\BulkOperationsService;
use App\Services\ContentService;
use App\Services\Search\SearchOrchestrator;
use Core\Exceptions\BusinessException;

class ContentController extends BaseAdminController
{
    private ContentSubmission $contentSubmissionModel;
    private ContentRevenue $contentRevenueModel;
    private ContentAgreement $contentAgreementModel;
    private ContentService $contentService;
    private BulkOperationsService $bulkService;
    private SearchOrchestrator $searchService;

    public function __construct(
        ContentAgreement $contentAgreementModel,
        ContentRevenue $contentRevenueModel,
        ContentSubmission $contentSubmissionModel,
        ContentService $contentService,
        BulkOperationsService $bulkService,
        SearchOrchestrator $searchService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->contentService = $contentService;
        $this->contentAgreementModel = $contentAgreementModel;
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
        $this->bulkService = $bulkService;
        $this->searchService = $searchService;
    }

    /**
     * لیست تمام محتواها (PRIMARY / ADMIN_ONLY)
     */
    public function index(): string
    {
        $filters = [
            'status' => $this->request->query('status'),
            'platform' => $this->request->query('platform'),
            'category' => $this->request->query('category'),
        ];

        $search = trim(str_value($this->request->query('search', '')));
        $page = max(1, int_value($this->request->query('page', 1)));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        if ($search !== '') {
            $result = $this->searchService->searchContent($search, $filters, $perPage, $offset);
            $submissions = $result['items'] ?? [];
            $total = int_value($result['total'] ?? 0);
        } else {
            $submissions = $this->contentSubmissionModel->getAll($filters, $perPage, $offset);
            $total = $this->contentSubmissionModel->countAll($filters);
        }

        $totalPages = (int)ceil($total / $perPage);
        $stats = $this->contentSubmissionModel->getStats();

        view('admin.content.index', [
            'user' => $this->currentAuthUser(),
            'submissions' => $submissions,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
            'search' => $search,
        ]);
        return '';
    }

    /**
     * مشاهده جزئیات محتوا (PRIMARY / ADMIN_ONLY)
     */
    public function show(): string
    {
        $id = int_value($this->request->param('id') ?? $this->request->get('id'));
        $submission = $this->contentSubmissionModel->findWithUser($id);

        if (!$submission) {
            view('errors.404');
            return '';
        }

        $revenues = $this->contentRevenueModel->getBySubmission($id);
        $agreement = $this->contentAgreementModel->findBySubmission($id);

        view('admin.content.show', [
            'user' => $this->currentAuthUser(),
            'submission' => $submission,
            'revenues' => $revenues,
            'agreement' => $agreement,
        ]);
        return '';
    }

    /** تأیید محتوا (PRIMARY / ADMIN_ONLY) */
    public function approve(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('id') ?? $this->request->get('id'));
            $adminId = user_id();
            if ($adminId === null) {
                throw new BusinessException('احراز هویت ادمین الزامی است.');
            }
            return $this->contentService->approveSubmission($id, $adminId);
        });
    }

    /** رد محتوا (PRIMARY / ADMIN_ONLY) */
    public function reject(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('id') ?? $this->request->get('id'));
            $input = $this->jsonInput();
            $reason = trim(str_value($input['reason'] ?? ''));

            if (mb_strlen($reason) < 10) {
                throw new BusinessException('لطفاً دلیل رد را وارد کنید (حداقل ۱۰ کاراکتر).');
            }

            $adminId = user_id();
            if ($adminId === null) {
                throw new BusinessException('احراز هویت ادمین الزامی است.');
            }
            return $this->contentService->rejectSubmission($id, $adminId, $reason);
        });
    }

    /** ثبت انتشار (PRIMARY / ADMIN_ONLY) */
    public function publish(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('id') ?? $this->request->get('id'));
            $input = $this->jsonInput();
            $publishedUrl = trim(str_value($input['published_url'] ?? ''));
            $channelName = trim(str_value($input['channel_name'] ?? ''));

            if ($publishedUrl === '' || !filter_var($publishedUrl, FILTER_VALIDATE_URL)) {
                throw new BusinessException('لینک انتشار معتبر را وارد کنید.');
            }
            if (mb_strlen((string)$publishedUrl) > 500) {
                throw new BusinessException('لینک انتشار بیش از حد طولانی است.');
            }
            if (mb_strlen((string)$channelName) > 255) {
                throw new BusinessException('نام کانال بیش از حد طولانی است.');
            }

            $adminId = user_id();
            if ($adminId === null) {
                throw new BusinessException('احراز هویت ادمین الزامی است.');
            }
            return $this->contentService->publishSubmission($id, $adminId, $publishedUrl, $channelName);
        });
    }

    /** تأیید گروهی (PRIMARY / ADMIN_ONLY) */
    public function bulkApprove(): void
    {
        $this->runJsonAction(function (): array {
            $input = $this->jsonInput();
            if (empty($input['ids']) || !is_array($input['ids'])) {
                throw new BusinessException('هیچ محتوایی انتخاب نشده است.');
            }

            $ids = array_values(array_filter(array_map('intval', $input['ids']), static fn(int $id): bool => $id > 0));
            if (empty($ids)) {
                throw new BusinessException('شناسه‌های انتخاب‌شده معتبر نیستند.');
            }

            $adminId = user_id();
            if ($adminId === null) {
                throw new BusinessException('احراز هویت ادمین الزامی است.');
            }

            $result = $this->bulkService->bulkUpdate(
                'content_submissions',
                $ids,
                [
                    'status' => ContentSubmission::STATUS_APPROVED,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'approved_by' => $adminId,
                ]
            );

            return [
                'success' => !empty($result['success']),
                'message' => !empty($result['success']) ? 'محتواهای انتخاب‌شده تأیید شدند.' : ($result['message'] ?? 'تأیید گروهی انجام نشد.'),
                'data' => $result,
            ];
        });
    }

    /** رد گروهی (PRIMARY / ADMIN_ONLY) */
    public function bulkReject(): void
    {
        $this->runJsonAction(function (): array {
            $input = $this->jsonInput();
            if (empty($input['ids']) || !is_array($input['ids'])) {
                throw new BusinessException('هیچ محتوایی انتخاب نشده است.');
            }

            $reason = trim(str_value($input['reason'] ?? ''));
            if (mb_strlen($reason) < 10) {
                throw new BusinessException('لطفاً دلیل رد را وارد کنید (حداقل ۱۰ کاراکتر).');
            }

            $ids = array_values(array_filter(array_map('intval', $input['ids']), static fn(int $id): bool => $id > 0));
            if (empty($ids)) {
                throw new BusinessException('شناسه‌های انتخاب‌شده معتبر نیستند.');
            }

            $adminId = $this->requireAdminId();

            $result = $this->bulkService->bulkUpdate(
                'content_submissions',
                $ids,
                [
                    'status' => ContentSubmission::STATUS_REJECTED,
                    'rejection_reason' => e($reason, ENT_QUOTES, 'UTF-8'),
                    'rejected_at' => date('Y-m-d H:i:s'),
                    'rejected_by' => $adminId,
                ]
            );

            return [
                'success' => !empty($result['success']),
                'message' => !empty($result['success']) ? 'محتواهای انتخاب‌شده رد شدند.' : ($result['message'] ?? 'رد گروهی انجام نشد.'),
                'data' => $result,
            ];
        });
    }

    /**
     * صادرات محتواها به CSV (PRIMARY / ADMIN_ONLY)
     */
    public function export(): void
    {
        $filters = [
            'status' => $this->request->query('status'),
            'platform' => $this->request->query('platform'),
            'search' => $this->request->query('search'),
        ];

        $search = trim(str_value($this->request->query('search', '')));
        $result = $this->searchService->searchContentForExport($search, $filters, 5000, 0);
        $items = $result['items'] ?? [];

        if (empty($items)) {
            $this->session->setFlash('warning', 'داده‌ای برای صادرات یافت نشد. ابتدا محتوا ثبت کنید یا فیلتر جستجو را تغییر دهید.');
            redirect(url('/admin/content'));
        }

        $headers = [
            'شناسه',
            'کاربر',
            'ایمیل',
            'عنوان',
            'پلتفرم',
            'لینک',
            'دسته',
            'وضعیت',
            'تاریخ ثبت',
            'تاریخ تأیید',
        ];

        $csv = $this->bulkService->exportToCSV($items, [], $headers, 'content_export');

        if (!empty($csv['success'])) {
            $filename = is_string($csv['filename'] ?? null) ? $csv['filename'] : 'content_export.csv';
            $filePath = is_string($csv['file_path'] ?? null) ? $csv['file_path'] : '';
            if ($filePath === '' || !is_file($filePath)) {
                throw new \RuntimeException('فایل خروجی CSV ایجاد نشده است.');
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache');
            readfile($filePath);
            exit;
        }

        $this->response->json($csv, 422);
    }

    /**
     * لیست درآمدها (PRIMARY / ADMIN_ONLY)
     */
    public function revenues(): string
    {
        $filters = [
            'status' => $this->request->query('status'),
            'period' => $this->request->query('period'),
        ];

        $page = max(1, int_value($this->request->query('page', 1)));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $revenues = $this->contentRevenueModel->getAll($filters, $perPage, $offset);
        $total = $this->contentRevenueModel->countAll($filters);
        $totalPages = (int)ceil($total / $perPage);
        $financialStats = $this->contentRevenueModel->getFinancialStats();

        view('admin.content.revenues', [
            'user' => $this->currentAuthUser(),
            'revenues' => $revenues,
            'financialStats' => $financialStats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
        return '';
    }

    /** ایجاد درآمد جدید (PRIMARY / ADMIN_ONLY) */
    public function createRevenue(): string
    {
        $id = int_value($this->request->param('id') ?? $this->request->get('id'));
        $submission = $this->contentSubmissionModel->findWithUser($id);

        if (!$submission) {
            view('errors.404');
            return '';
        }

        view('admin.content.revenue-create', [
            'user' => $this->currentAuthUser(),
            'submission' => $submission,
            'settings' => $this->contentService->getSettings(),
            'activeMonths' => $this->contentSubmissionModel->getActiveMonths(int_value($submission->user_id)),
        ]);
        return '';
    }

    /**
     * Helper برای دریافت ID ادمین با تضمین non-null
     * (مطابق معماری پروژه که از user_id() استفاده می‌کند)
     */
    protected function requireAdminId(): int
    {
        $id = user_id();
        if ($id === null) {
            throw new BusinessException('احراز هویت ادمین الزامی است.');
        }
        return $id;
    }

    /** ذخیره درآمد جدید (PRIMARY / ADMIN_ONLY) */
    public function storeRevenue(): void
    {
        $this->runJsonAction(function (): array {
            $input = $this->jsonInput();
            if (empty($input['submission_id'])) {
                $input['submission_id'] = int_value($this->request->param('id'));
            }

            $validator = $this->validatorFactory()->make($input, [
                'submission_id' => 'required|integer',
                'period' => 'required',
                'total_revenue' => 'required|numeric|min:0',
                'views' => 'nullable|integer|min:0',
                'idempotency_key' => 'nullable|string|min:10|max:128',
            ]);

            if ($validator->fails()) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات درآمد کامل یا معتبر نیست.',
                    'errors' => $validator->errors(),
                ];
            }

            $data = (array)$validator->data();
            $adminId = $this->requireAdminId();
            return $this->contentService->createRevenue($data, $adminId, is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : null);
        });
    }

    /** تعلیق محتوا (PRIMARY / ADMIN_ONLY) */
    public function suspend(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('id') ?? $this->request->get('id'));
            $input = $this->jsonInput();
            $reason = trim(str_value($input['reason'] ?? ''));

            if (mb_strlen($reason) < 10) {
                throw new BusinessException('لطفاً دلیل تعلیق را وارد کنید (حداقل ۱۰ کاراکتر).');
            }

            $adminId = $this->requireAdminId();
            return $this->contentService->suspendSubmission($id, $adminId, $reason);
        });
    }

    /** تأیید درآمد محتوا (PRIMARY / ADMIN_ONLY) */
    public function revenueApprove(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('rid') ?? $this->request->get('rid'));
            $revenue = $this->contentRevenueModel->find($id);
            if (!$revenue) {
                throw new BusinessException('رکورد درآمد یافت نشد.');
            }
            if (($revenue->status ?? '') !== ContentRevenue::STATUS_PENDING) {
                throw new BusinessException('فقط درآمدهای در انتظار قابل تأیید هستند.');
            }

            $adminId = $this->requireAdminId();

            $ok = $this->contentRevenueModel->update((int)$id, [
                'status' => ContentRevenue::STATUS_APPROVED,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => (bool)$ok,
                'message' => $ok ? 'درآمد تأیید شد.' : 'تأیید درآمد انجام نشد.',
                'data' => ['revenue_id' => $id],
            ];
        });
    }

    /** پرداخت درآمد محتوا (PRIMARY / ADMIN_ONLY) */
    public function revenuePay(): void
    {
        $this->runJsonAction(function (): array {
            $id = int_value($this->request->param('rid') ?? $this->request->get('rid'));
            $adminId = $this->requireAdminId();
            return $this->contentService->payRevenue($id, $adminId);
        });
    }

    private function currentAuthUser(): ?object
    {
        $auth = function_exists('auth') ? auth() : null;
        return is_object($auth) && method_exists($auth, 'user') ? $auth->user() : null;
    }

    /** @return array<string, mixed> */
    private function jsonInput(): array
    {
        return $this->request->all();
    }

    private function runJsonAction(callable $callback): void
    {
        $status = 200;

        try {
            $result = $callback();
            $status = !empty($result['success']) ? 200 : 422;
        } catch (BusinessException $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
            $status = 422;
        } catch (\Throwable $e) {
            $this->logger->error('admin.content.action_failed', [
                'error' => $e->getMessage(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $result = [
                'success' => false,
                'message' => 'خطای سیستمی در اجرای عملیات مدیریت محتوا.',
            ];
            $status = 500;
        }

        $this->response->json($result, $status);
    }
}
