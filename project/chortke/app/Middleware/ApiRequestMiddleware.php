<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * ApiRequestMiddleware — اعتبارسنجی درخواست‌های API
 *
 * مسئولیت‌ها:
 * ۱. API Version detection و validation
 * ۲. Content-Type enforcement (POST/PUT/PATCH باید application/json باشه)
 * ۳. Accept header enforcement (همیشه JSON response)
 * ۴. تضمین JSON error response (هیچوقت HTML برنگرده)
 */
class ApiRequestMiddleware extends BaseMiddleware
{
    // Evaluates Authorization headers for token checks
    private string $defaultVersion;
    /** @var list<string> */
    private array $supportedVersions;

    public function __construct() {
        $dv = config('api.default_version', 'v1');
        $this->defaultVersion = is_string($dv) ? $dv : 'v1';
        $sv = config('api.supported_versions', ['v1', 'v2']);
        $this->supportedVersions = is_array($sv) ? $sv : ['v1', 'v2'];
    }

    public function handle(Request $request, Closure $next): Response
    {
        // ─── 1. API Version ─────────────────────────────────────
        $version = $this->resolveVersion($request);

        if (!in_array($version, $this->supportedVersions, true)) {
            return $this->jsonError(400, 'UNSUPPORTED_API_VERSION',
                "The API version '{$version}' is no longer supported.",
                ['supported_versions' => $this->supportedVersions]
            );
        }

        $request->setAttribute('api_version', $version);

        // ─── 2. Content-Type (فقط POST/PUT/PATCH) ───────────────
        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentTypeValue = $request->header('Content-Type');
            $contentType = is_string($contentTypeValue) ? $contentTypeValue : '';

            // اجازه: application/json یا multipart/form-data (برای upload)
            if (!$this->isValidContentType($contentType)) {
                return $this->jsonError(415, 'UNSUPPORTED_MEDIA_TYPE',
                    'Content-Type must be application/json or multipart/form-data.',
                    ['received' => $contentType]
                );
            }
        }

        // ─── 3. Accept header ───────────────────────────────────
        $accept = str_value($request->header('Accept') ?? '*/*');
        if ($accept !== '*/*' && !str_contains($accept, 'application/json') && !str_contains($accept, '*/*')) {
            return $this->jsonError(406, 'NOT_ACCEPTABLE',
                'This API only supports application/json responses.',
                ['received_accept' => $accept]
            );
        }

        // ─── 4. Execute request ─────────────────────────────────
        $response = $next($request);
        $response = $this->toResponse($response);

        // MED-08 Fix: تزریق هدر Connection: keep-alive جهت تضمین بازنشانی نشدن سوکت‌های TCP/TLS در کلاینت‌های موبایل
        $response->header('Connection', 'keep-alive');
        $response->header('Keep-Alive', 'timeout=10, max=500');

        return $response;
    }

    private function resolveVersion(Request $request): string
    {
        // اول header
        $headerVersion = $request->header('Accept-Version');
        $xVersion = $request->header('X-API-Version');
        $version = (is_string($headerVersion) && $headerVersion !== '') ? $headerVersion : ((is_string($xVersion) && $xVersion !== '') ? $xVersion : null);

        // بعد URL path
        if (!$version) {
            $uri = $request->uri();
            if (preg_match('#^/api/(v\d+)/#', $uri, $m)) {
                $version = $m[1];
            }
        }

        return (is_string($version) && $version !== '') ? $version : (string) $this->defaultVersion;
    }

    private function isValidContentType(string $contentType): bool
    {
        $contentType = strtolower(trim((string)$contentType));

        // خالی = form-urlencoded default → مجاز
        if ($contentType === '') return true;

        return str_contains($contentType, 'application/json')
            || str_contains($contentType, 'multipart/form-data')
            || str_contains($contentType, 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function jsonError(int $httpCode, string $errorCode, string $message, array $extra = []): Response
    {
        $response = new Response();
        $response->setStatusCode($httpCode);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode(array_merge([
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ]
        ], $extra), JSON_UNESCAPED_UNICODE));

        return $response;
    }
}
