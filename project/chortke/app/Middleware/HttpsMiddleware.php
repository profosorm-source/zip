<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * HttpsMiddleware — اجبار به استفاده از پروتکل امن HTTPS
 */
class HttpsMiddleware
{
    public function __construct(private \Core\UrlGenerator $urlGenerator)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $env = config('app.env', 'production');

        if ($env === 'production' && !$request->isSecure()) {
            // Canonical host comes only from immutable UrlGenerator configuration.
            $uri = $request->uri();
            // MEDIUM-M-10 Fix: Robust URI sanitization using parse_url to prevent header injection or redirection bypasses
            $cleanPath = parse_url($uri, PHP_URL_PATH) ?: '/';
            $query = parse_url($uri, PHP_URL_QUERY);
            
            $redirectUrl = $this->urlGenerator->to(
                '/' . ltrim((string)$cleanPath, '/') . ($query ? '?' . $query : '')
            );
            $redirectUrl = preg_replace('#^http://#i', 'https://', $redirectUrl) ?? $redirectUrl;

            // Redirect before the rest of the response pipeline to avoid downstream
            // production-only header/rendering failures from turning HTTPS enforcement
            // into a 500 response.
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl, true, 301);
            }
            exit;
        }

        return $this->toResponse($next($request));
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result instanceof \Throwable) {
            $response = new Response();
            $response->status(500);
            $response->setContent('Internal Server Error');
            return $response;
        }

        $response = new Response();
        $response->setContent(str_value($result));
        return $response;
    }
}
