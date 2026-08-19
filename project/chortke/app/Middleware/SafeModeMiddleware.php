<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Closure;

/**
 * SafeModeMiddleware — جلوگیری از تغییرات حساس در حالت Safe Mode
 */
class SafeModeMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $isSafeMode = (bool)config('app.safe_mode', false);

        if ($isSafeMode && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            // ✅ Fix L4: لیست سفید مسیرها از کانفیگ لوادشده
            $allowedPathsConfig = config('app.safe_mode_whitelist', ['/login', '/logout', '/verify-2fa']);
            $allowedPaths = is_array($allowedPathsConfig) ? $allowedPathsConfig : [];
            
            // 🚀 BUG FIX [M-05]: جلوگیری از دور زدن لیست سفید با Path Overrides
            $pathsToVerify = [$request->uri()];
            $override = $request->header('override-path') ?? $request->header('x-rewrite-url');
            if (is_string($override) && $override !== '') {
                $overridePath = strtok($override, '?');
                $pathsToVerify[] = '/' . ltrim($overridePath === false ? $override : $overridePath, '/');
            }

            // 🔐 M-09 FIX: SafeMode was bypassable via request headers. The old logic marked
            // the request whitelisted if ANY candidate path matched, so an attacker could hit
            // a protected mutating route while attaching a spoofed `override-path: /login`
            // (or `x-rewrite-url`) header to satisfy the whitelist. Invert to AND-semantics:
            // EVERY candidate path (the real URI *and* any rewrite/override header) must be
            // whitelisted — a single non-whitelisted candidate blocks the request.
            $isWhitelisted = true;
            foreach ($pathsToVerify as $path) {
                if (!in_array($path, $allowedPaths, true)) {
                    $isWhitelisted = false;
                    break;
                }
            }

            if (!$isWhitelisted) {
                $response = new Response();
                
                if ($request->isAjax() || str_contains($request->uri(), '/api/')) {
                    $response->json([
                        'success' => false,
                        'message' => 'سیستم در حالت امن (Safe Mode) قرار دارد. تغییرات مجاز نیست.',
                        'error'   => 'SAFE_MODE_ENABLED'
                    ], 403);
                }

                session()->setFlash('error', 'سیستم در حالت امن قرار دارد و امکان ثبت تغییرات وجود ندارد.');
                $response->redirect(url('/')); 

                // 🔐 M-09 (defense-in-depth): json()/redirect() normally throw HttpResponseException
                // and unwind before $next(), but returning the block response here honors the
                // middleware contract and guarantees the mutating handler never runs even if a
                // custom/overridden Response transport does not throw.
                return $response;
            }
        }

        $result = $next($request);
        
        if ($result instanceof Response) {
            return $result;
        }

        $response = new Response();
        $response->setContent((string)$result);
        return $response;
    }
}