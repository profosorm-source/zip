<?php

namespace App\Controllers\Admin;

use App\Services\EmailDeliveryStore;
use App\Services\EmailService;
use App\Models\EmailQueue;
use App\Services\Search\SearchOrchestrator;

class EmailQueueController extends BaseAdminController
{
    private EmailService $emailService;
    private EmailDeliveryStore $emailStore;
    private SearchOrchestrator $searchService;

    public function __construct(
        EmailService $emailService,
        EmailDeliveryStore $emailStore,
        SearchOrchestrator $searchService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->emailService = $emailService;
        $this->emailStore = $emailStore;
        $this->searchService = $searchService;
    }

    public function index(): void
    {
        $page    = max(1, $this->request->int('page', 1));
        $perPage = 30;
        $statusRaw = $this->request->get('status');
        $status = $statusRaw !== null ? str_value($statusRaw) : null;
        $search  = trim($this->request->str('search', ''));
        $offset  = ($page - 1) * $perPage;

        $filters = [];
        if (!empty($status)) {
            $filters['status'] = $status;
        }

        // هر دو مسیر (search و list) شکل {items, total} برمی‌گردانند؛ یک‌جا به view نگاشت می‌شود
        if (!empty($search)) {
            $result = $this->searchService->searchEmails($search, $filters, $perPage, $offset);
        } else {
            $result = $this->emailStore->getEmailsForAdmin($page, $perPage, $status, $search);
        }

        $total = (int)($result['total'] ?? 0);

        view('admin/email-queue/index', [
            'title'      => 'صف ایمیل',
            'emails'     => $result['items'] ?? [],
            'stats'      => $result['stats'] ?? [],
            'total'      => $total,
            'page'       => $page,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
            'search'     => $search,
        ]);
    }

    public function process(): void
    {
        $result = $this->emailService->processQueue(20);
        $this->response->json($result);
    }

    public function retryFailed(): void
    {
        $count = $this->emailStore->retryAllFailed();
        $this->response->json(['success' => true, 'count' => $count]);
    }

    public function retry(): void
    {
        $id = $this->request->int('id');
        $ok = $this->emailStore->retryEmail($id);
        $this->response->json(['success' => $ok, 'message' => $ok ? 'آماده تلاش مجدد' : 'یافت نشد']);
    }
}