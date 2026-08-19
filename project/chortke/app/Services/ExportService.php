<?php

namespace App\Services;

use App\Models\ExportData;

use App\Contracts\LoggerInterface;
use Core\Session;
use App\Services\Shared\PolicyService;
use Core\RateLimiter;

class ExportService
{


    private ExportData $exportData;
    private Session $session;
    private PolicyService $policyService;
    private RateLimiter $rateLimiter;
    public function __construct(
        ExportData $exportData,
        Session $session,
        PolicyService $policyService,
        RateLimiter $rateLimiter
    ) {        $this->exportData = $exportData;
        $this->session = $session;
        $this->policyService = $policyService;
        $this->rateLimiter = $rateLimiter;

            }
    /**
     * خروجی CSV (Streaming version)
     */
    /** @param list<string> $headers */
    public function exportCsvStream(array $headers, \PDOStatement $stmt, string $filename, bool $maskPii = false): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.csv';

        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache, no-store, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');

        // BOM for UTF-8 Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = \fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        // Header
        \fputcsv($output, $headers, separator: ',', enclosure: '"', escape: '\\');

        // Rows (Streamed)
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            if ($maskPii) {
                $row = $this->maskSensitiveData($row);
            }
            $row = $this->sanitizeRowForCsv($row);
            \fputcsv($output, \array_values($row), separator: ',', enclosure: '"', escape: '\\');
            if (\connection_aborted()) break;
        }

        \fclose($output);
    }

    /**
     * خروجی CSV از آرایه‌ی آماده (نسخه‌ی غیر-streaming)
     * مکمل exportCsvStream برای مواردی که داده از قبل در آرایه پردازش شده است.
     */
    /** @param list<string> $headers
     *  @param list<array<int|string, mixed>> $rows */
    public function exportCsv(array $headers, array $rows, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.csv';

        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache, no-store, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');

        echo "\xEF\xBB\xBF";

        $output = \fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        \fputcsv($output, $headers, separator: ',', enclosure: '"', escape: '\\');

        foreach ($rows as $row) {
            $csvRow = $this->sanitizeRowForCsv((array)$row);
            \fputcsv($output, \array_values($csvRow), separator: ',', enclosure: '"', escape: '\\');
        }

        \fclose($output);
    }

    /**
     * ماسک کردن داده‌های حساس
     */
    /** @param array<string, mixed> $row
     *  @return array<string, mixed> */
    private function maskSensitiveData(array $row): array
    {
        if (isset($row['email'])) {
            $parts = explode('@', (string)(is_scalar($row['email']) ? $row['email'] : ''));
            if (count($parts) === 2) {
                $row['email'] = substr($parts[0], 0, 3) . '***@' . $parts[1];
            }
        }
        if (isset($row['mobile'])) {
            $row['mobile'] = substr((string)(is_scalar($row['mobile']) ? $row['mobile'] : ''), 0, 4) . '***' . substr((string)(is_scalar($row['mobile']) ? $row['mobile'] : ''), -2);
        }
        return $row;
    }

    /**
     * ردیف را برای خروجی CSV امن و scalar می‌کند.
     * این متد قرارداد واحدِ تبدیل است: خروجی همیشه scalar|string|null است
     * تا fputcsv بتواند مستقیماً روی آن کار کند (بدون وصله در محل مصرف).
     *
     * @param array<int|string, mixed> $row
     * @return array<string, bool|float|int|string|null>
     */
    private function sanitizeRowForCsv(array $row): array
    {
        foreach ((array)$row as $key => $value) {
            // ۱. مقادیر غیر scalar را به string تبدیل کن (CSV فقط scalar قبول می‌کند)
            if (!is_scalar($value) && $value !== null) {
                $value = json_encode($value) ?: '';
            }
            // ۲. حفاظت ضد formula injection
            if (is_string($value)) {
                $val = trim($value);
                if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
                    $value = "'" . $value;
                }
            }
            $row[$key] = $value;
        }
        /** @var array<string, bool|float|int|string|null> $row */
        return $row;
    }

    private function authorizeExport(string $permission): void
    {
        $userIdValue = $this->session->get('user_id');
        $userId = is_int($userIdValue) ? $userIdValue : (is_numeric($userIdValue) ? (int)$userIdValue : 0);

        if (!$userId || !$this->policyService->authorizeById($permission, $userId)) {
            $response = (new \Core\Response())->setStatusCode(403);
            $response->json(['success' => false, 'error' => 'Unauthorized export attempt']);
            throw new \Core\Exceptions\HttpResponseException($response);
        }

        $key = 'export:' . $userId . ':' . $permission;
        if (!$this->rateLimiter->attempt($key, 5, 3600)) { // 5 exports per hour
            $response = (new \Core\Response())->setStatusCode(429);
            $response->json(['success' => false, 'error' => 'Rate limit exceeded: Maximum 5 exports per hour']);
            throw new \Core\Exceptions\HttpResponseException($response);
        }
    }

    /**
     * خروجی JSON
     */
    /** @param array<string, mixed> $data */
    /** @param array<mixed> $data */
    public function exportJson(array $data, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.json';

        \header('Content-Type: application/json; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache');

        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی کاربران
     */
    /** @param array<string, mixed> $filters
     *  @return array{headers: list<string>, rows: list<array<int, mixed>>} */
    public function prepareUsersExport(array $filters = []): array
    {
        $dateFrom = isset($filters['date_from']) && is_scalar($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = isset($filters['date_to']) && is_scalar($filters['date_to']) ? (string)$filters['date_to'] : null;
        
        $rows = $this->exportData->getUsers($dateFrom, $dateTo);
        
        $headers = ['شناسه', 'نام', 'ایمیل', 'موبایل', 'سطح', 'وضعیت', 'تاریخ ثبت‌نام', 'آخرین ورود', 'موجودی تومان', 'موجودی تتر'];

        $statusMap = [0 => 'غیرفعال', 1 => 'فعال', 2 => 'تعلیق', 3 => 'مسدود'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->full_name,
                $r->email,
                $r->mobile ?? '',
                $r->level_slug ?? 'silver',
                $statusMap[(int)($r->status ?? 0)] ?? 'نامشخص',
                $r->created_at,
                $r->last_login ?? '',
                $r->balance_irt,
                $r->balance_usdt,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی تراکنش‌ها
     */
    /** @param array<string, mixed> $filters
     *  @return array{headers: list<string>, rows: list<array<int, mixed>>} */
    public function prepareTransactionsExport(array $filters = []): array
    {
        $dateFrom = isset($filters['date_from']) && is_scalar($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = isset($filters['date_to']) && is_scalar($filters['date_to']) ? (string)$filters['date_to'] : null;
        $type = isset($filters['type']) && is_scalar($filters['type']) ? (string)$filters['type'] : null;
        $status = isset($filters['status']) && is_scalar($filters['status']) ? (string)$filters['status'] : null;
        
        $rows = $this->exportData->getTransactions($dateFrom, $dateTo, $type, $status);

        $headers = ['شناسه', 'شماره تراکنش', 'کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->transaction_id,
                $r->full_name ?? '',
                $r->type,
                $r->currency,
                $r->amount,
                $r->balance_before,
                $r->balance_after,
                $r->status,
                $r->created_at,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * خروجی کاربران
     */
    /** @param array<string, mixed> $filters */
    public function exportUsers(array $filters = []): void
    {
        $this->authorizeExport('admin.export.users');

        $dateFrom = isset($filters['from']) && is_scalar($filters['from']) ? (string)$filters['from'] : null;
        $dateTo = isset($filters['to']) && is_scalar($filters['to']) ? (string)$filters['to'] : null;
        $kycStatus = isset($filters['kyc_status']) && is_scalar($filters['kyc_status']) ? (string)$filters['kyc_status'] : null;
        $tierLevel = isset($filters['level_slug']) && is_scalar($filters['level_slug']) ? (string)$filters['level_slug'] : null;
        
        $stmt = $this->exportData->getUsersStatement($dateFrom, $dateTo, $kycStatus, $tierLevel);
        
        $headers = ['#', 'نام', 'ایمیل', 'موبایل', 'KYC', 'سطح', 'وضعیت', 'تاریخ', 'آخرین ورود'];
        
        $this->exportCsvStream($headers, $stmt, 'users_export', true);
    }

    /**
     * خروجی تراکنش‌ها
     */
    /** @param array<string, mixed> $filters */
    public function exportTransactionsStream(array $filters = []): void
    {
        $this->authorizeExport('admin.export.transactions');

        $dateFrom = isset($filters['from']) && is_scalar($filters['from']) ? (string)$filters['from'] : null;
        $dateTo = isset($filters['to']) && is_scalar($filters['to']) ? (string)$filters['to'] : null;
        $type = isset($filters['type']) && is_scalar($filters['type']) ? (string)$filters['type'] : null;
        $status = isset($filters['status']) && is_scalar($filters['status']) ? (string)$filters['status'] : null;
        
        $stmt = $this->exportData->getTransactionsStatement($dateFrom, $dateTo, $type, $status);
        
        $headers = ['#', 'شماره تراکنش', 'نام کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];
        
        $this->exportCsvStream($headers, $stmt, 'transactions_export', false);
    }

    /**
     * خروجی برداشت‌ها
     */
    /** @param array<string, mixed> $filters */
    public function exportWithdrawalsStream(array $filters = []): void
    {
        $this->authorizeExport('admin.export.withdrawals');

        $dateFrom = isset($filters['from']) && is_scalar($filters['from']) ? (string)$filters['from'] : null;
        $dateTo = isset($filters['to']) && is_scalar($filters['to']) ? (string)$filters['to'] : null;
        $status = isset($filters['status']) && is_scalar($filters['status']) ? (string)$filters['status'] : null;
        $currency = isset($filters['currency']) && is_scalar($filters['currency']) ? (string)$filters['currency'] : null;
        
        $stmt = $this->exportData->getWithdrawalsStatement($dateFrom, $dateTo, $status, $currency);
        
        $headers = ['#', 'کد پیگیری', 'نام', 'ایمیل', 'مبلغ', 'کارمزد', 'مبلغ نهایی', 'ارز', 'وضعیت', 'روش', 'تاریخ'];
        
        $this->exportCsvStream($headers, $stmt, 'withdrawals_export', true);
    }

    /**
     * خروجی AuditTrail
     */
    /** @param array<string, mixed> $filters */
    public function exportAuditTrail(array $filters = []): void
    {
        $this->authorizeExport('admin.export.audit');

        $dateFrom = isset($filters['from']) && is_scalar($filters['from']) ? (string)$filters['from'] : null;
        $dateTo = isset($filters['to']) && is_scalar($filters['to']) ? (string)$filters['to'] : null;
        $event = isset($filters['event']) && is_scalar($filters['event']) ? (string)$filters['event'] : null;
        $userId = isset($filters['user_id']) ? (int)(is_numeric($filters['user_id']) ? $filters['user_id'] : 0) : null;
        
        $stmt = $this->exportData->getAuditTrailStatement($dateFrom, $dateTo, $event, $userId);
        
        $headers = ['#', 'رویداد', 'کاربر', 'انجام‌دهنده', 'جزئیات', 'IP', 'زمان'];
        
        $this->exportCsvStream($headers, $stmt, 'audit_trail_export', false);
    }
}
