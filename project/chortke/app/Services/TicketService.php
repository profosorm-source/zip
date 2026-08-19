<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Core\Database;
use Core\EventDispatcher;
use Core\RateLimiter;
use App\Contracts\ValidatorFactoryInterface;
use App\Contracts\LoggerInterface;
use App\Services\Shared\IdempotencyService;

/**
 * TicketService - Facade proxy for Ticket Support
 * Delegates to TicketCommandService and TicketQueryService.
 *
 * REFACTOR: حذف تمام Container::getInstance() از داخل method body ها.
 * Database، Ticket و TicketMessage مستقیماً inject می‌شوند.
 */
class TicketService
{
    /** @return list<object> */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows)) return [];
        return array_map(fn($r) => is_object($r) ? $r : (object)(array)$r, $rows);
    }



    private TicketCommandService $commandService;
    private TicketQueryService $queryService;
    private Database $db;
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private ?\App\Contracts\LoggerInterface $logger = null;

    public function __construct(
        ValidatorFactoryInterface $validatorFactory,
        EventDispatcher $eventDispatcher,
        Database $db,
        LoggerInterface $logger,
        Ticket $ticketModel,
        TicketMessage $messageModel,
        RateLimiter $rateLimiter,
        IdempotencyService $idempotencyService
    ) {
        $this->db           = $db;
        $this->ticketModel  = $ticketModel;
        $this->messageModel = $messageModel;

        $this->commandService = new TicketCommandService(
            $validatorFactory, $eventDispatcher,
            $db, $logger, $ticketModel, $messageModel, $rateLimiter, $idempotencyService
        );
        $this->queryService = new TicketQueryService($db, $ticketModel);
    }

    // ─── Command delegation ───────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function guardCanCreateTicket(int $userId, array $data): void
    {
        $this->commandService->guardCanCreateTicket($userId, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: false, message: string}|array{success: true, message: string, ticket_id: int}
     */
    public function create(int $userId, array $data): array
    {
        return $this->commandService->create($userId, $data);
    }

    /** @param list<array<string, mixed>> $attachments
     *  @return array<string, mixed> */
    public function reply(int $ticketId, int $userId, string $message, bool $isAdmin = false, array $attachments = []): array
    {
        return $this->commandService->reply($ticketId, $userId, $message, $isAdmin, $attachments);
    }

    /** @return array<string, mixed> */
    public function close(int $ticketId, int $userId, bool $isAdmin = false): array
    {
        return $this->commandService->close($ticketId, $userId, $isAdmin);
    }

    public function detectPriority(string $text, int $categoryId = 0): string
    {
        return $this->commandService->detectPriority($text, $categoryId);
    }

    /** @return array<string, mixed> */
    public function parseBrowser(?string $ua): array
    {
        return $this->commandService->parseBrowser($ua);
    }

    // ─── Query delegation ─────────────────────────────────────────────────────

    /** @return list<\stdClass> */
    public function getUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        return $this->queryService->getUserTickets($userId, $status, $page, $perPage);
    }

    /** @return array{tickets: list<\stdClass>, total: int} */
    public function listUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $rows = $this->queryService->getUserTickets($userId, $status, $page, $perPage);
        return ['tickets' => $rows, 'total' => count($rows)];
    }

    /** @return list<\stdClass> */
    public function quickSearchTickets(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->queryService->quickSearchTickets($term, $userId, $limit);
    }

    /** @return list<\stdClass> */
    public function getCategories(): array
    {
        return $this->queryService->getCategories();
    }

    public function countUnread(int $userId, bool $isAdmin = false): int
    {
        return $this->messageModel->countUnread($userId, $isAdmin);
    }

    // ─── Admin queries (inject‌شده، بدون Container) ──────────────────────────

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed> */
    public function listForAdmin(array $filters, int $page, int $perPage): array
    {
        $where  = ['1=1'];
        $params = [];

        foreach (['status', 'priority', 'category_id', 'assigned_to'] as $col) {
            if (!empty($filters[$col])) {
                $where[]  = "t.{$col} = ?";
                $params[] = $filters[$col];
            }
        }

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $tickets = $this->toObjectArray($this->db->fetchAll(
            "SELECT t.*, u.full_name AS user_name, u.email AS user_email
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE {$whereStr}
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        ));

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets t WHERE {$whereStr}",
            $params
        );

        return ['tickets' => $tickets, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return [
            'total'   => $this->db->fetchColumn('SELECT COUNT(*) FROM tickets'),
            'open'    => $this->db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE status = 'open'"),
            'pending' => $this->db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE status = 'pending'"),
            'replied' => $this->db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE status = 'replied'"),
            'closed'  => $this->db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE status = 'closed'"),
        ];
    }

    public function getById(int $id): ?\stdClass
    {
        return $this->ticketModel->findById($id);
    }

    /** @return list<\stdClass> */
    public function getMessages(int $ticketId): array
    {
        return $this->messageModel->getByTicketId($ticketId);
    }

    public function getAttachmentMessage(string $filename): ?\stdClass
    {
        return $this->messageModel->findByAttachmentFilename($filename);
    }

    public function markAsRead(int $ticketId, bool $viewerIsAdmin = false): bool
    {
        return $this->messageModel->markAsRead($ticketId, $viewerIsAdmin);
    }

    public function updateStatus(int $id, string $status, ?int $operatorId = null): bool
    {
        return $this->ticketModel->updateStatus($id, $status);
    }

    public function updatePriority(int $id, string $priority, ?int $operatorId = null): bool
    {
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent', 'critical'], true)) {
            return false;
        }
        if ($priority === 'critical') {
            $priority = 'urgent';
        }
        return $this->ticketModel->update($id, ['priority' => $priority]);
    }

    // ─── Bug Reports ──────────────────────────────────────────────────────────

    /** @return list<\stdClass> */
    public function getBugReports(int $userId, int $limit = 15, int $offset = 0): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->tableExists('bug_reports')) {
            return $this->db->fetchAll(
                "SELECT br.*, COALESCE(c.comment_count, 0) AS comment_count
                 FROM bug_reports br
                 LEFT JOIN (
                    SELECT bug_report_id, COUNT(*) AS comment_count
                    FROM bug_report_comments
                    GROUP BY bug_report_id
                 ) c ON c.bug_report_id = br.id
                 WHERE br.user_id = ?
                 ORDER BY br.created_at DESC
                 LIMIT {$limit} OFFSET {$offset}",
                [$userId]
            ) ?: [];
        }

        return $this->db->fetchAll(
            "SELECT t.id, t.user_id,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.category')), 'other') AS category,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.description')), t.subject) AS description,
                    t.priority, t.status, t.created_at, t.updated_at, 0 AS comment_count
             FROM tickets t
             WHERE t.user_id = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.kind')) = 'bug_report'
             ORDER BY t.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        ) ?: [];
    }

    /** @param array<string, mixed> $filters
     *  @return list<\stdClass> */
    public function getAdminBugReports(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        if ($this->tableExists('bug_reports')) {
            $where  = ['1=1'];
            $params = [];
            foreach (['status', 'priority', 'category'] as $key) {
                if (!empty($filters[$key])) {
                    $where[]  = "br.{$key} = ?";
                    $params[] = $filters[$key];
                }
            }
            if (!empty($filters['search'])) {
                $where[]  = '(br.description LIKE ? OR br.page_url LIKE ?)';
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
            }
            $whereSql = implode(' AND ', $where);
            return $this->db->fetchAll(
                "SELECT br.*, u.full_name, u.email,
                        COALESCE(c.comment_count, 0) AS comment_count
                 FROM bug_reports br
                 LEFT JOIN users u ON u.id = br.user_id
                 LEFT JOIN (
                    SELECT bug_report_id, COUNT(*) AS comment_count
                    FROM bug_report_comments GROUP BY bug_report_id
                 ) c ON c.bug_report_id = br.id
                 WHERE {$whereSql}
                 ORDER BY br.created_at DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            ) ?: [];
        }

        return $this->db->fetchAll(
            "SELECT t.*, u.full_name, u.email,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.category')), 'other') AS category,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.description')), t.subject) AS description,
                    0 AS comment_count
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.kind')) = 'bug_report'
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        ) ?: [];
    }

    /** @param array<string, mixed> $filters */
    public function countAdminBugReports(array $filters = []): int
    {
        if ($this->tableExists('bug_reports')) {
            return (int)$this->db->fetchColumn('SELECT COUNT(*) FROM bug_reports');
        }
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.kind')) = 'bug_report'"
        );
    }

    /** @return array<string, mixed> */
    public function getAdminBugStats(): array
    {
        $reports = $this->getAdminBugReports([], 1, 500);
        $stats   = ['total' => count($reports), 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'critical' => 0];
        /** @var object{status: string, priority: string} $r */
        foreach ($reports as $r) {
            $status = (string)($r->status ?? 'open');
            if (isset($stats[$status])) $stats[$status]++;
            if (in_array((string)($r->priority ?? ''), ['critical', 'urgent'], true)) $stats['critical']++;
        }
        return $stats;
    }

    public function findBugReport(int $id): ?\stdClass
    {
        if ($this->tableExists('bug_reports')) {
            return $this->db->fetch('SELECT * FROM bug_reports WHERE id = ? LIMIT 1', [$id]) ?: null;
        }
        return $this->db->fetch(
            "SELECT t.id, t.user_id,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.category')), 'other') AS category,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.description')), t.subject) AS description,
                    JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.page_url')) AS page_url,
                    JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.bug_report.screenshot')) AS screenshot_path,
                    t.priority, t.status, t.created_at, t.updated_at, NULL AS admin_note
             FROM tickets t
             WHERE t.id = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.kind')) = 'bug_report'
             LIMIT 1",
            [$id]
        ) ?: null;
    }

    /** @return list<\stdClass> */
    public function getBugReportComments(int $id): array
    {
        if ($this->tableExists('bug_report_comments')) {
            return $this->db->fetchAll(
                'SELECT * FROM bug_report_comments WHERE bug_report_id = ? ORDER BY created_at ASC',
                [$id]
            ) ?: [];
        }

        $messages = $this->messageModel->getByTicketId($id);
        return array_map(static function ($m) {
            return (object)[
                'id'             => $m->id ?? null,
                'user_type'      => !empty($m->is_admin) ? 'admin' : 'user',
                'user_full_name' => !empty($m->is_admin) ? 'تیم پشتیبانی' : ($m->full_name ?? 'کاربر'),
                'comment'        => $m->message ?? '',
                'attachment_path'=> null,
                'created_at'     => $m->created_at ?? null,
            ];
        }, $messages);
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function submitBugReport(int $userId, array $data, ?object $uploadService = null): array
    {
        $categoryValue = $data['category'] ?? 'other';
        $category = is_scalar($categoryValue) ? (string)$categoryValue : 'other';
        $descriptionValue = $data['description'] ?? '';
        $description = trim(is_scalar($descriptionValue) ? (string)$descriptionValue : '');
        if ($description === '') {
            return ['success' => false, 'message' => 'توضیحات گزارش الزامی است.'];
        }

        if ($this->tableExists('bug_reports')) {
            $this->db->query(
                "INSERT INTO bug_reports
                 (user_id, page_url, page_title, category, description, screenshot_path,
                  screen_resolution, device_fingerprint, user_agent, ip_address,
                  status, priority, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 'normal', NOW(), NOW())",
                [
                    $userId,
                    $data['page_url'] ?? '',
                    $data['page_title'] ?? '',
                    $category,
                    $description,
                    $data['screenshot'] ?? null,
                    $data['screen_resolution'] ?? '',
                    $data['device_fingerprint'] ?? '',
                    $data['user_agent'] ?? '',
                    $data['ip_address'] ?? null,
                ]
            );
            return ['success' => true, 'message' => 'گزارش شما با موفقیت ثبت شد.', 'report_id' => (int)$this->db->lastInsertId()];
        }

        $categoryId = (int)($this->db->fetchColumn('SELECT id FROM ticket_categories ORDER BY id ASC LIMIT 1') ?: 1);
        $metadata   = json_encode([
            'kind'       => 'bug_report',
            'bug_report' => [
                'page_url'         => $data['page_url'] ?? '',
                'page_title'       => $data['page_title'] ?? '',
                'category'         => $category,
                'description'      => $description,
                'screenshot'       => $data['screenshot'] ?? null,
                'screen_resolution'=> $data['screen_resolution'] ?? '',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ticketId = $this->ticketModel->create([
            'user_id'     => $userId,
            'category_id' => $categoryId,
            'subject'     => 'گزارش مشکل: ' . mb_substr(strip_tags($description), 0, 80),
            'priority'    => 'normal',
            'metadata'    => $metadata,
        ]);
        if (!$ticketId) return ['success' => false, 'message' => 'خطا در ثبت گزارش مشکل.'];

        $this->messageModel->create([
            'ticket_id' => $ticketId,
            'user_id'   => $userId,
            'message'   => $description,
            'is_admin'  => 0,
        ]);

        return ['success' => true, 'message' => 'گزارش شما با موفقیت ثبت شد.', 'report_id' => $ticketId, 'ticket_id' => $ticketId];
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        try {
            return (bool)$this->db->fetchColumn(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** انتساب تیکت به ادمین */
    public function assignTo(int $ticketId, int $adminId): bool
    {
        try {
            return (bool)$this->db->execute(
                "UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?",
                [$adminId, $ticketId]
            );
        } catch (\Throwable $e) {
            $this->logger?->error('ticket.assign_failed', ['ticket_id' => $ticketId, 'admin_id' => $adminId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
