<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\GeoIPService;
use App\Services\Auth\SessionService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\ScoreService;
use Core\Request;
use Core\Response;
use Core\Session;
use Closure;

/**
 * AdvancedFraudMiddleware — سیستم پیشرفته شناسایی تقلب و ریسک
 */
class AdvancedFraudMiddleware extends BaseMiddleware
{
    private BrowserFingerprintService $fingerprintService;
    private GeoIPService $ipQualityService;
    private SessionService $sessionService;
    private AccountTakeoverService $accountTakeoverService;
    private ScoreService $scoreService;
    private RiskDecisionService $decisionService;
    private LoggerInterface $logger;
    private Session $session;
    private NotificationServiceInterface $notifications;

    public function __construct(
        BrowserFingerprintService $fingerprintService,
        GeoIPService $ipQualityService,
        SessionService $sessionService,
        AccountTakeoverService $accountTakeoverService,
        ScoreService $scoreService,
        RiskDecisionService $decisionService,
        LoggerInterface $logger,
        Session $session,
        NotificationServiceInterface $notifications
    ) {
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionService = $sessionService;
        $this->accountTakeoverService = $accountTakeoverService;
        $this->scoreService = $scoreService;
        $this->decisionService = $decisionService;
        $this->logger = $logger;
        $this->session = $session;
        $this->notifications = $notifications;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $session = $this->session;
        if (!$session->has('user_id')) {
            return $this->toResponse($next($request));
        }

        // Graceful degradation: اگه سرویس‌های fraud (DB tables, Redis) در دسترس نباشن، request بلاک نشه
        try {
            return $this->performFraudChecks($request, $next, $session);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            // Redirect exceptions باید propagate بشن (block/challenge actions)
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('fraud.middleware.check_failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'uri' => $request->uri(),
                'user_id' => $session->get('user_id'),
            ]);

            // M-24 FIX: previously the middleware failed OPEN for ALL requests when the fraud engine
            // threw — a crafted input that reliably crashed a check would bypass IP blacklisting, the
            // account-takeover 2FA challenge and the risk decision entirely. Graceful degradation is
            // still acceptable for ordinary browsing, but security-sensitive routes (money movement,
            // account/security settings, admin) must fail CLOSED so an outage cannot be weaponised to
            // strip protection. This mirrors the fail-closed default already enforced by
            // FraudGuardService::handleSystemFailure() at the action layer.
            if ($this->isSensitivePath($request->uri())) {
                $this->logger->warning('fraud.middleware.fail_closed', [
                    'uri' => $request->uri(),
                    'user_id' => $session->get('user_id'),
                ]);
                $response = new Response();
                if ($request->isPost() || str_contains($request->uri(), '/api/')) {
                    $response->error(
                        'سرویس ارزیابی امنیتی موقتاً در دسترس نیست. لطفاً دقایقی دیگر دوباره تلاش کنید.',
                        [],
                        503
                    );
                } else {
                    $response->redirect(url('/login?error=security_unavailable'));
                }
                exit;
            }

            return $this->toResponse($next($request));
        }
    }

    /**
     * آیا مسیر درخواست حساس است و در صورت خرابی موتور ضدتقلب باید fail-closed شود؟
     */
    private function isSensitivePath(string $uri): bool
    {
        $path = strtolower(parse_url($uri, PHP_URL_PATH) ?: $uri);
        $sensitivePrefixes = [
            '/wallet', '/withdraw', '/deposit', '/payment', '/transfer', '/invest',
            '/vitrine', '/dispute', '/bank', '/card', '/kyc', '/admin',
            '/settings/security', '/security', '/2fa', '/api/user/wallet',
            '/api/wallet', '/api/payment', '/api/withdraw',
        ];
        foreach ($sensitivePrefixes as $prefix) {
            if (str_starts_with($path, $prefix) || str_contains($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function performFraudChecks(Request $request, Closure $next, Session $session): mixed
    {
        $userId = int_value($session->get('user_id'));
        $ip = get_client_ip();
        $userAgent = get_user_agent();
        $sessionId = $session->getId();
        $acceptLanguage = str_value($request->header('accept-language') ?? '');
        $acceptEncoding = str_value($request->header('accept-encoding') ?? '');

        $geoData = $this->ipQualityService->getGeolocation($ip);
        $this->sessionService->updateActivity($sessionId);

        if (!$session->get('fraud_check_done')) {
            // ✅ Pass all HTTP data explicitly
            $this->sessionService->recordSession(
                userId: $userId,
                sessionId: $sessionId,
                userAgent: $userAgent,
                ipAddress: $ip,
                acceptLanguage: $acceptLanguage,
                acceptEncoding: $acceptEncoding,
                geoData: $geoData
            );
            $session->set('fraud_check_done', true);
        }

        if ($this->ipQualityService->isIPBlacklisted($ip)) {
            $this->logger->warning('fraud.blocked_ip', ['ip' => $ip, 'user_id' => $userId]);
            $session->destroy();
            (new Response())->redirect(url('/login?error=blocked'));
        }

        $ipCheck = $this->ipQualityService->check($ip);
        if ($ipCheck['is_suspicious']) {
            $this->ipQualityService->logIPCheck($userId, $ip, $ipCheck);
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, float_value($ipCheck['score']) / 4, 'ip_quality', [
                'ip' => $ip,
                'reasons' => $ipCheck['reasons'],
            ]);

            $ipDetails = is_array($ipCheck['details'] ?? null) ? $ipCheck['details'] : [];
            if (!empty($ipDetails['is_tor'])) {
                $this->ipQualityService->blacklistIP($ip, 'Tor Network', 86400 * 7);
                $session->destroy();
                (new Response())->redirect(url('/login?error=tor_blocked'));
            }
        }

        // BUGFIX-FRAUD-LOGANOMALY-2026-06:
        //   The previous code called $sessionService->logAnomaly(...) which
        //   does not exist on SessionService — analyzeAnomaly() already
        //   performs the logging and fraud_logs persistence internally via
        //   its private logAnalysisResult() helper. The undefined-method
        //   call threw on every authenticated request and was silently
        //   swallowed by the outer try/catch in performFraudChecks(),
        //   logging "fraud.middleware.check_failed" for each hit and
        //   skipping the score delta below.
        $sessionCheck = $this->sessionService->analyzeAnomaly($userId, $sessionId);
        if ($sessionCheck['is_anomaly']) {
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, float_value($sessionCheck['score']) / 2, 'session_anomaly', [
                'anomalies' => $sessionCheck['anomalies'],
                'session_id' => $sessionId,
            ]);
        }

        $currentFingerprint = $this->fingerprintService->generate([
            'user_agent' => $userAgent,
            'language' => $acceptLanguage,
            'encoding' => $acceptEncoding
        ]);

        $takeoverCheck = $this->accountTakeoverService->detect($userId, $ip, $userAgent, $currentFingerprint);
        if ($takeoverCheck['is_takeover']) {
            $this->accountTakeoverService->logDetection($userId, $ip, $userAgent, $takeoverCheck);
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, (float) $takeoverCheck['risk_score'] / 2, 'account_takeover', [
                'signals' => $takeoverCheck['signals'],
            ]);

            if ($takeoverCheck['action'] === 'notify') {
                $this->notifications->send($userId, 'warning', 'هشدار امنیتی', str_value(config('messages.security.suspicious')));
            }
        }

        $decision = $this->decisionService->decide($userId, ['action' => 'general']);
        
        $decisionResult = str_value($decision['result'] ?? $decision['decision'] ?? 'allow');

        switch ($decisionResult) {
            case 'block':
                $this->notifications->send($userId, 'danger', 'هشدار امنیتی', str_value(config('messages.security.high_risk')));
                $session->destroy();
                (new Response())->redirect(url('/login?error=high_risk'));

            case 'challenge':
                if (!$session->get('2fa_verified')) {
                    $session->setFlash('warning', config('messages.security.challenge_2fa'));
                    (new Response())->redirect(url('/verify-2fa'));
                }
                break;

            case 'review':
                // وضعیت بررسی دستی (Review) — فلگ بررسی دستی را فعال کرده و لاگ ثبت می‌کنیم
                $session->set('under_manual_review', true);
                $this->logger->info('fraud.manual_review_triggered', [
                    'user_id' => $userId,
                    'ip' => $ip,
                ]);
                break;

            case 'allow':
            default:
                $session->remove('under_manual_review');
                break;
        }

        return $this->toResponse($next($request));
    }
}
