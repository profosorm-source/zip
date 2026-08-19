<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

class GlobalExceptionMiddleware extends BaseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $result = $next($request);
            return $this->toResponse($result);
        } catch (\Throwable $e) {
            // لاگ خطا (بدون throw مجدد)
            try {
                error_log('[GlobalException] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            } catch (\Throwable $ignore) { /* intentional: non-blocking operation */ }

            // ارسال به Sentry — آخرین خط دفاعی برای exception‌های uncaught
            try {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                    'layer'  => 'GlobalExceptionMiddleware',
                    'uri'    => $request->uri(),
                    'method' => $request->method(),
                ]);
            } catch (\Throwable $ignore) { /* intentional: non-blocking operation */ }

            // تشخیص نوع درخواست: API/AJAX → JSON، بقیه → HTML
            $wantsJson = $request->isAjax()
                || str_starts_with($request->uri(), '/api/')
                || str_contains(str_value($request->header('Accept') ?? ''), 'application/json');

            if ($wantsJson) {
                // API / AJAX → JSON response
                $payload = \Core\ExceptionHandler::getJsonPayloadForException($e);

                $response = new Response();
                $response->setStatusCode($payload['code']);
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response;
            }

            // HTML → نمایش صفحه خطای کاربرپسند با کد وضعیت صحیح (نه همیشه ۵۰۰)
            $isDebug = (config('app.debug') === true || config('app.debug') === 'true');

            $payload = \Core\ExceptionHandler::getJsonPayloadForException($e);
            $statusCode = $payload['code'];

            $response = new Response();
            $response->setStatusCode($statusCode);
            $response->header('Content-Type', 'text/html; charset=utf-8');

            if ($isDebug) {
                // حالت debug — نمایش جزئیات خطا
                $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
                $line = $e->getLine();
                $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
                $html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>خطای سیستمی</title>
<style>body{font-family:Tahoma,sans-serif;background:#f5f5f5;padding:40px;direction:rtl}
.box{background:#fff;border-radius:12px;padding:30px;max-width:800px;margin:0 auto;box-shadow:0 2px 20px rgba(0,0,0,.1)}
h1{color:#e53935;font-size:20px}pre{background:#f8f8f8;padding:15px;border-radius:8px;overflow-x:auto;font-size:13px;direction:ltr;text-align:left}
.file{color:#666;font-size:13px}</style></head>
<body><div class="box">
<h1>⚠️ خطای سیستمی (HTTP {$statusCode})</h1>
<p><strong>{$msg}</strong></p>
<p class="file">📁 {$file}:{$line}</p>
<pre>{$trace}</pre>
<p style="margin-top:20px"><a href="javascript:history.back()" style="color:#1976d2">← بازگشت</a></p>
</div></body></html>
HTML;
                $response->setContent($html);
                return $response;
            }

            // حالت production — استفاده از قالب‌های استاندارد موجود در views/errors
            // (همان قالب‌هایی که Router برای 404 استفاده می‌کند) تا صفحات خطا
            // در سراسر پروژه یکدست باشند، نه یک HTML جداگانه در این میدل‌ویر.
            $viewMap = [401 => '419', 403 => '403', 404 => '404', 419 => '419', 429 => '429', 503 => '503'];
            $viewName = $viewMap[$statusCode] ?? '500';
            $viewFile = __DIR__ . "/../../views/errors/{$viewName}.php";

            ob_start();
            if (is_file($viewFile)) {
                require $viewFile;
            } else {
                require __DIR__ . '/../../views/errors/500.php';
            }
            $response->setContent(ob_get_clean());
            return $response;
        }
    }
}
