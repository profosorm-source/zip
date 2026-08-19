<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;

/**
 * DebugController — فقط در حالت debug=true و از طریق AdminMiddleware قابل دسترس است.
 *
 * مسیر: GET /admin/debug/router   (ثبت‌شده در routes/system.php)
 * دسترسی: [AuthMiddleware, AdminMiddleware] — هیچ درخواست بدون احراز هویت ادمین رد نمی‌شود.
 *
 * حفاظت IP-based قبلی حذف شد — AdminMiddleware (Auth + Role check + 15s DB re-validation)
 * این مسئولیت را با امنیت بالاتر انجام می‌دهد.
 */
class DebugController extends BaseController
{
    public function __construct(?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
    }

    public function router(): void
    {
        $remoteAddr = $this->request->ip();
        $clientIp   = function_exists('get_client_ip') ? get_client_ip() : $remoteAddr;

        $rawPath = $this->request->str('path', '');

        if ($rawPath === '') {
            $this->response->html('<pre style="direction:ltr;text-align:left;">Missing "path" query param</pre>', 400);
            return;
        }

        // sanitize پایه
        $path = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $rawPath);
        $path = str_replace(['..', '\\', "\0"], '', $path);
        $path = '/' . ltrim($path, '/');

        // الگوی امن: folder + filename
        $safe = '#^/file/view/([a-zA-Z0-9_-]+)/([a-zA-Z0-9._-]+)$#';

        $m  = [];
        $ok = \preg_match($safe, $path, $m) === 1;

        $out  = "=== APP ROUTER DEBUG ===\n";
        $out .= "Remote addr: {$remoteAddr}\n";
        $out .= "Resolved client IP: {$clientIp}\n";
        $out .= "Input path: {$rawPath}\n";
        $out .= "Sanitized path: {$path}\n\n";
        $out .= "[SAFE] => " . ($ok ? '1' : '0') . "\n";
        $out .= "Matches: " . \json_encode($m, JSON_UNESCAPED_UNICODE) . "\n\n";

        // اسکن Router.php برای دیدن placeholder/preg_replace
        $routerFile = __DIR__ . '/../../core/Router.php';
        $out .= "Router.php: {$routerFile}\n";
        if (\file_exists($routerFile)) {
            $lines = \file($routerFile);
            $out .= "---- lines containing preg_replace/placeholder ----\n";
            foreach ((array)$lines as $i => $line) {
                $l = \trim((string)$line);
                if (\strpos($l, 'preg_replace') !== false && (\strpos($l, '{') !== false || \strpos($l, '\{') !== false)) {
                    $out .= \str_pad((string)($i + 1), 5, ' ', STR_PAD_LEFT) . " | " . $l . "\n";
                }
            }
        } else {
            $out .= "Router.php NOT FOUND\n";
        }

        $this->response->html(
            '<pre style="direction:ltr;text-align:left;">' . \htmlspecialchars($out, ENT_QUOTES, 'UTF-8') . '</pre>'
        );
    }
}
