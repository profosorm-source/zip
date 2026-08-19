<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;
use App\Constants\SessionKeys;

/**
 * SecurityHeadersMiddleware — اعمال هدرهای امنیتی به تمام پاسخ‌ها
 * 
 * SECURITY NOTES:
 * - CSP nonce is set as a response header (in addition to request attribute)
 * - This ensures CSP can be enforced even if views use output buffering
 * - All security headers are set atomically to prevent partial exposure
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        // LOW-02 Fix: Generate nonce at the START of the request pipeline
        // This ensures the nonce is available for all code that runs during $next($request)
        $nonce = $request->nonce();
        
        // Store in request attribute for view access (backward compatibility)
        $request->setAttribute(SessionKeys::CSP_NONCE, $nonce);
        
        // Execute the request
        $response = $next($request);

        // Ensure we have a Response object
        if (!$response instanceof Response) {
            $content = (string)$response;
            $response = new Response();
            $response->setContent($content);
        }

        // Apply security headers. Configuration is an administrative input boundary:
        // an invalid type must not turn a request into a TypeError/500 response.
        $env = $this->stringConfig('app.env', 'production');

        // Content Security Policy with nonce
        $csp = $this->buildCSP($env, $nonce);
        $response->header('Content-Security-Policy', $csp);
        
        
        // جلوگیری از حملات رایج. Request::header() عمداً mixed برمی‌گرداند؛
        // فقط مقدار رشته‌ایِ بدون CR/LF را در پاسخ بازتاب می‌دهیم.
        $requestId = $this->safeHeaderValue($request->header('x-request-id'));
        $correlationId = $this->safeHeaderValue($request->header('x-correlation-id'));
        $response->header(
            'X-Correlation-ID',
            $correlationId !== '' ? $correlationId : ($requestId !== '' ? $requestId : bin2hex(random_bytes(8)))
        );
        $response->header('X-Request-ID', $requestId);
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header('X-Content-Type-Options', 'nosniff');
        // MED-05 Fix: X-XSS-Protection is deprecated and can be used as an attack vector in old browsers.
        // Modern CSP is sufficient.
        $response->header('X-XSS-Protection', '0');
        $referrerPolicy = str_starts_with($request->uri(), '/reset-password')
            ? 'no-referrer'
            : 'strict-origin-when-cross-origin';
        $response->header('Referrer-Policy', $referrerPolicy);
        
        // سیاست‌های دسترسی به سخت‌افزار
        $permissionsPolicy = 'camera=(), microphone=(), geolocation=(self), payment=(self)';
        $response->header('Permissions-Policy', $permissionsPolicy);
        
        // HSTS (فقط در پروادکشن و HTTPS)
        if ($env === 'production' && $request->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        
        // Cross-Origin Policies (اصلاح شده برای جلوگیری از شکستن CDNها)
        // require-corp فقط اگر واقعاً نیاز به ایزوله‌سازی پردازش باشد اعمال شود
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('Cross-Origin-Resource-Policy', 'same-site');
        // LOW-01 Fix: Using credentialless for COEP to avoid breaking cross-origin resources (like CDNs)
        $response->header('Cross-Origin-Embedder-Policy', 'credentialless');

        // LOW-02 Fix: Remove framework identification for security through obscurity
        $response->header('Server', '');
        
        // LOW-L-02 Fix: Modern CSP reporting
        $appUrl = rtrim($this->stringConfig('app.url'), '/');
        $reportUrl = $appUrl . '/api/v1/security/csp-report';
        $response->header('Reporting-Endpoints', 'csp-endpoint="' . $reportUrl . '"');
        $reportTo = json_encode([
            'group' => 'csp-group',
            'max_age' => 10886400,
            'endpoints' => [['url' => $reportUrl]],
        ], JSON_UNESCAPED_SLASHES);
        // json_encode() can return false for malformed configuration bytes. Do
        // not pass false to Response::header(), and keep the request available.
        if (is_string($reportTo)) {
            $response->header('Report-To', $reportTo);
        }
        
        // LOW-02 Fix: Add Cache-Control for sensitive pages
        if ($this->isSensitivePage($request->uri())) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }
        
        return $response;
    }
    
    private function buildCSP(string $env, string $nonce): string
    {
        // Permanent Architecture: CDN/static asset sources are config/env driven.
        $cdn = implode(' ', $this->cdnWhitelist());
        $scripts = trim("'self' 'nonce-{$nonce}' https://www.google.com https://www.gstatic.com {$cdn}");
        $styles = trim("'self' 'unsafe-inline' https://fonts.googleapis.com {$cdn}");
        $fonts = trim("'self' https://fonts.gstatic.com {$cdn}");
        $images = trim("'self' data: https://*.google.com https://*.gstatic.com https://img.youtube.com https://i.ytimg.com https://*.ytimg.com {$cdn}");
        $connect = trim("'self' https://www.google.com https://www.youtube.com https://youtube.com {$cdn}");
        $frames = trim("'self' https://www.google.com https://www.youtube.com https://youtube.com {$cdn}");
        return implode('; ', [
            "default-src 'self'",
            "script-src {$scripts}",
            "style-src {$styles}",
            "font-src {$fonts}",
            "img-src {$images}",
            "frame-src {$frames}",
            "connect-src {$connect}",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests",
            "report-uri /api/v1/security/csp-report",
            "report-to csp-group"
        ]);
    }

    /** @return list<string> */
    private function cdnWhitelist(): array
    {
        $envCdn = $_ENV['CSP_CDN_WHITELIST'] ?? getenv('CSP_CDN_WHITELIST') ?: null;
        $configCdn = function_exists('config') ? config('security.csp_cdn_whitelist', []) : [];

        if (is_string($envCdn) && trim($envCdn) !== '') {
            $sources = $this->normaliseCspSources(explode(',', $envCdn));
        } else {
            $sources = $this->normaliseCspSources(is_array($configCdn) ? $configCdn : []);
        }

        $assetUrl = $this->stringConfig('app.asset_url');
        if ($assetUrl !== '') {
            $origin = $this->originFromUrl($assetUrl);
            if ($origin !== null) {
                $sources[] = $origin;
            }
        }
        if (empty($sources)) {
            $sources = ['https://cdn.jsdelivr.net', 'https://code.jquery.com'];
        }

        return array_values(array_unique($sources));
    }

    /**
     * CSP directives are security-sensitive. Invalid configuration entries are
     * discarded rather than cast (for example, integer 123 must not become an
     * invalid CSP source token). This also makes the callback contract explicit
     * for PHPStan: every input item is mixed, every output item is a string.
     *
     * @param array<int, mixed> $candidates
     * @return list<string>
     */
    private function normaliseCspSources(array $candidates): array
    {
        $sources = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $source = trim($candidate);
            if ($source !== '') {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    private function originFromUrl(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!in_array($scheme, ['http', 'https'], true) || empty($host)) {
            return null;
        }
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        if (!function_exists('config')) {
            return $default;
        }

        $value = config($key, $default);
        return is_string($value) ? $value : $default;
    }

    private function safeHeaderValue(mixed $value): string
    {
        if (!is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
            return '';
        }

        return trim($value);
    }

    /**
     * Check if the current page is sensitive and should have cache disabled
     */
    private function isSensitivePage(string $uri): bool
    {
        $sensitivePaths = [
            '/login',
            '/register',
            '/password/reset',
            '/dashboard',
            '/settings',
            '/admin',
            '/profile',
            '/2fa',
            '/verify',
            '/payment',
            '/withdrawal',
        ];
        
        foreach ($sensitivePaths as $path) {
            if (str_starts_with($uri, $path)) {
                return true;
            }
        }
        
        return false;
    }
}