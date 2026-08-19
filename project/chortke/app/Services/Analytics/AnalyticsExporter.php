<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Contracts\LoggerInterface;

class AnalyticsExporter
{
    public function __construct() {
            }

    /**
     * تولید CSV
     */
    /** @param array<string, array<int|string, mixed>> $data */
    public function generateCSV(array $data): void
    {
        $filename = 'analytics_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        
        // BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        $this->putCsvRow($output, ['گزارش تحلیلات', date('Y-m-d H:i:s')]);
        $this->putCsvRow($output, []);
        
        // User Metrics
        if (!empty($data['users'])) {
            $this->putCsvRow($output, ['تحلیلات کاربران']);
            $this->putCsvRow($output, ['توضیح', 'مقدار']);
            $this->putCsvRow($output, ['کل کاربران', str_value($data['users']['total'] ?? 0)]);
            $this->putCsvRow($output, ['کاربران فعال', $data['users']['active'] ?? 0]);
            $this->putCsvRow($output, ['کاربران جدید', $data['users']['new_today'] ?? 0]);
            $this->putCsvRow($output, ['KYC تأیید شده', $data['users']['kyc_verified'] ?? 0]);
            $this->putCsvRow($output, []);
        }
        
        // Transaction Metrics
        if (!empty($data['transactions'])) {
            $this->putCsvRow($output, ['تحلیلات تراکنش‌ها']);
            $this->putCsvRow($output, ['توضیح', 'تعداد', 'مبلغ']);
            $this->putCsvRow($output, ['کل تراکنش‌ها', str_value($data['transactions']['total_transactions'] ?? 0), '']);
            $this->putCsvRow($output, ['درآمد پلتفرم', '', $data['transactions']['site_revenue'] ?? 0]);
            $this->putCsvRow($output, ['واریز‌های ماهانه', '', $data['transactions']['monthly_deposits'] ?? 0]);
            $this->putCsvRow($output, ['برداشت‌های ماهانه', '', $data['transactions']['monthly_withdrawals'] ?? 0]);
            $this->putCsvRow($output, []);
        }
        
        // Task Metrics
        if (!empty($data['tasks'])) {
            $this->putCsvRow($output, ['وظایف']);
            $this->putCsvRow($output, ['توضیح', 'مقدار']);
            $this->putCsvRow($output, ['کل تسک‌ها', str_value($data['tasks']['total'] ?? 0)]);
            $this->putCsvRow($output, ['تسک‌های فعال', $data['tasks']['active'] ?? 0]);
            $this->putCsvRow($output, ['تکمیل‌شده این ماه', $data['tasks']['completed_month'] ?? 0]);
            $this->putCsvRow($output, []);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تولید Excel (HTML-based)
     */
    /** @param array<string, array<int|string, mixed>> $data */
    public function generateExcel(array $data): void
    {
        $filename = 'analytics_report_' . date('Y-m-d_H-i-s') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        $html = $this->generateExcelHTML($data);
        echo $html;
        exit;
    }

    /**
     * تولید PDF (HTML-based)
     */
    /** @param array<string, array<int|string, mixed>> $data */
    public function generatePDF(array $data): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>گزارش تحلیلات</title>
            <style>
                * { margin: 0; padding: 0; }
                body { font-family: Arial, sans-serif; direction: rtl; background: white; }
                h1 { text-align: center; margin: 20px 0; }
                h2 { margin: 20px 0 10px; border-bottom: 2px solid #333; padding-bottom: 5px; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background: #f2f2f2; font-weight: bold; }
                tr:nth-child(even) { background: #f9f9f9; }
                .header { text-align: center; margin-bottom: 20px; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                @media print { body { margin: 0; padding: 10px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>گزارش تحلیلات سیستم</h1>
                <p>تاریخ: <?= date('Y-m-d H:i:s') ?></p>
            </div>

            <?php if (!empty($data['users'])): ?>
            <h2>تحلیلات کاربران</h2>
            <table>
                <tr><th>توضیح</th><th>مقدار</th></tr>
                <tr><td>کل کاربران</td><td><?= $this->escape($data['users']['total'] ?? 0) ?></td></tr>
                <tr><td>کاربران فعال</td><td><?= $this->escape($data['users']['active'] ?? 0) ?></td></tr>
                <tr><td>DAU</td><td><?= $this->escape($data['users']['dau'] ?? 0) ?></td></tr>
                <tr><td>MAU</td><td><?= $this->escape($data['users']['mau'] ?? 0) ?></td></tr>
            </table>
            <?php endif; ?>

            <?php if (!empty($data['transactions'])): ?>
            <h2>تحلیلات مالی</h2>
            <table>
                <tr><th>نوع</th><th>مبلغ</th></tr>
                <tr><td>کل واریز‌ها</td><td><?= number_format(float_value($data['transactions']['total_deposits'] ?? 0), 0) ?></td></tr>
                <tr><td>کل برداشت‌ها</td><td><?= number_format(float_value($data['transactions']['total_withdrawals'] ?? 0), 0) ?></td></tr>
                <tr><td>درآمد پلتفرم</td><td><?= number_format(float_value($data['transactions']['site_revenue'] ?? 0), 0) ?></td></tr>
            </table>
            <?php endif; ?>

            <div class="footer">
                <p>این گزارش به صورت خودکار تولید شده است</p>
            </div>

            <script>
                window.print();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * تولید HTML برای Excel
     */
    /** @param array<string, array<int|string, mixed>> $data */
    private function generateExcelHTML(array $data): string
    {
        $html = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $html .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $html .= '<html><body><table border="1">';
        
        // Header
        $html .= '<tr><td colspan="2" style="font-weight:bold; font-size:14px;">گزارش تحلیلات</td></tr>';
        $html .= '<tr><td>تاریخ:</td><td>' . date('Y-m-d H:i:s') . '</td></tr>';
        $html .= '<tr><td colspan="2"></td></tr>';
        
        // Users
        if (!empty($data['users'])) {
            $html .= '<tr><td colspan="2" style="font-weight:bold;">کاربران</td></tr>';
            $html .= '<tr><td>کل کاربران</td><td>' . $this->escape($data['users']['total'] ?? 0) . '</td></tr>';
            $html .= '<tr><td>کاربران فعال</td><td>' . $this->escape($data['users']['active'] ?? 0) . '</td></tr>';
        }
        
        // Transactions
        if (!empty($data['transactions'])) {
            $html .= '<tr><td colspan="2" style="font-weight:bold;">تراکنش‌ها</td></tr>';
            $html .= '<tr><td>کل واریز‌ها</td><td>' . number_format(float_value($data['transactions']['total_deposits'] ?? 0), 0) . '</td></tr>';
            $html .= '<tr><td>کل برداشت‌ها</td><td>' . number_format(float_value($data['transactions']['total_withdrawals'] ?? 0), 0) . '</td></tr>';
        }
        
        $html .= '</table></body></html>';
        return $html;
    }

    /**
     * M30 Fix: متد پاک‌سازی محلی جهت رفع وابستگی به لایه ویو (e) در لایه سرویس
     */
    private function escape(mixed $value): string
    {
        return htmlspecialchars(str_value($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * L-02 Fix: نوشتن ایمن یک ردیف CSV با خنثی‌سازی formula injection.
     * مقادیری که با = + - @ یا tab/CR شروع می‌شوند با یک آپوستروف خنثی می‌شوند
     * تا Excel/Sheets آن‌ها را به‌عنوان فرمول اجرا نکند.
     * @param resource $output
     * @param array<int, mixed> $row
     */
    private function putCsvRow($output, array $row): void
    {
        $safe = [];
        foreach ($row as $cell) {
            if (is_string($cell)) {
                $t = ltrim($cell);
                if ($t !== '' && in_array($t[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $cell = "'" . $cell;
                }
            }
            $safe[] = $cell;
        }
        $csv = [];
        foreach ($safe as $value) {
            if ($value !== null && !is_scalar($value) && !$value instanceof \Stringable) {
                throw new \UnexpectedValueException('CSV cells must be scalar or stringable.');
            }
            $csv[] = $value === null ? '' : str_value($value);
        }
        \fputcsv($output, $csv, ',', '"', '\\');
    }
}
