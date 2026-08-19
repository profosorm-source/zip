<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use Core\Request;
use Core\Response;

/**
 * SecurityController — Handling security related callbacks (CSP reports, etc.)
 */
class SecurityController extends BaseController
{
    public function __construct(?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Handle Content Security Policy violation reports
     */
    public function cspReport(): void
    {
        // 🛡️ CRIT-11 / Finding #11 Fix: CSP reporting schema sanitization, URL query stripping & length bounding.
        $rawReport = $this->request->json();

        if (!is_array($rawReport) || empty($rawReport)) {
            $this->response->setStatusCode(204);
            $this->response->send();
            return;
        }

        $cspData = $rawReport['csp-report'] ?? $rawReport;
        if (!is_array($cspData)) {
            $this->response->setStatusCode(204);
            $this->response->send();
            return;
        }

        $cleanReport = [];
        $allowedKeys = ['document-uri', 'referrer', 'violated-directive', 'effective-directive', 'original-policy', 'disposition', 'blocked-uri', 'status-code', 'script-sample'];

        foreach ($allowedKeys as $key) {
            if (isset($cspData[$key])) {
                $val = str_value($cspData[$key]);
                // Strip query string from URIs to prevent leaking sensitive parameters in logs
                if (in_array($key, ['document-uri', 'blocked-uri', 'referrer'], true)) {
                    $val = (string)preg_replace('/\?.*$/', '', $val);
                }
                $cleanReport[$key] = mb_substr($val, 0, 255);
            }
        }

        $this->logger->warning('security.csp_violation', [
            'report' => $cleanReport,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string)$this->request->userAgent(), 0, 255)
        ]);

        $this->response->setStatusCode(204); // No Content
        $this->response->send();
    }

    /**
     * Log security events sent from frontend
     */
    public function logEvent(): void
    {
        $payload = $this->request->json() ?? $this->request->body();
        $this->logger->info('security.frontend_event', [
            'payload' => $payload,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);
        $this->response->json(['success' => true]);
    }
}
