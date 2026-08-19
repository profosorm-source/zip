<?php

namespace App\Middleware;

use Core\Container;
use App\Services\LogService;
use App\Middleware\BaseMiddleware;
use Core\Request;
use Core\Response;
use Closure;

/**
 * Middleware for Distributed Tracing (Option 3).
 * 
 * Sets correlation_id VERY EARLY in the pipeline so that:
 * - Listeners (Escrow, Referral, Notification, Audit, Outbox)
 * - Outbox events
 * - Saga executions
 * - LogService
 * can all carry the same trace id across the distributed flow.
 * 
 * This is the ONLY new middleware added in the refactor.
 * It extends BaseMiddleware for consistency with the rest of the project.
 */
class TracingMiddleware extends BaseMiddleware
{
    #[ \Core\Attributes\Inject ]
    private \App\Services\LogService $logService;

    public function __construct(\App\Services\LogService $logService)
    {
        $this->logService = $logService;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // Try to get from incoming header (for distributed calls / gateways)
        $rawId = $request->headers['x-correlation-id'] 
            ?? $request->headers['x-request-id'] 
            ?? $request->header('X-Correlation-ID') 
            ?? $request->header('X-Request-ID')
            ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['REQUEST_ID'] ?? ''));

        $rawId = trim(str_value($rawId));

        // Finding #4 & #5 Fix: Validate and sanitize trace ID (max 64 chars, alphanumeric/dash/underscore only)
        if ($rawId !== '' && strlen($rawId) <= 64 && preg_match('/^[a-zA-Z0-9_\-]+$/', $rawId)) {
            $correlationId = $rawId;
        } else {
            $correlationId = bin2hex(random_bytes(16));
        }

        // Synchronize across request attributes, headers, and server superglobals
        $request->setAttribute('request_id', $correlationId);
        $request->setAttribute('x_correlation_id', $correlationId);
        $request->headers['x-request-id'] = $correlationId;
        $request->headers['x-correlation-id'] = $correlationId;
        $_SERVER['REQUEST_ID'] = $correlationId;
        $_SERVER['HTTP_X_REQUEST_ID'] = $correlationId;
        $_SERVER['HTTP_X_CORRELATION_ID'] = $correlationId;

        // Inject into LogService if available
        try {
            if (method_exists($this->logService, 'setCorrelationId')) {
                $this->logService->setCorrelationId($correlationId);
            }
        } catch (\Throwable $e) {
            // Logger not critical for tracing
        }

        // 🚀 Sentry Performance: شروع transaction برای کل HTTP request
        // این تنها جایی است که startTransaction صدا زده می‌شود — یک بار به ازای هر request
        $transactionName = $request->method() . ' ' . $request->uri();
        \App\Services\Sentry\SentryExceptionHandler::startTransaction(
            $transactionName,
            'http.request',
            [
                'correlation_id' => $correlationId,
                'method'         => $request->method(),
                'uri'            => $request->uri(),
            ]
        );

        // Continue the request pipeline
        $response = $next($request);

        // Use BaseMiddleware helper to normalize response
        $response = $this->toResponse($response);

        // Add correlation headers to response (for clients and downstream services)
        $response->header('X-Correlation-ID', $correlationId);
        $response->header('X-Request-ID', $correlationId);

        return $response;
    }
}
