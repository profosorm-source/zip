<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Constants\SessionKeys;

class HandleGoogleCallbackJob
{
    use OAuthHelperTrait;
    private string $googleClientId;
    private string $googleClientSecret;
    private string $googleRedirectUri;
    private string $facebookClientId;
    private string $facebookClientSecret;
    private string $facebookRedirectUri;

    private \Core\Session $session;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\AuditTrail $auditTrail;
    /** @var array<string, mixed> */
    private array $oAuthConfig;
    /** @param array<string, mixed> $oAuthConfig */
    /** @param array<string, mixed> $oAuthConfig */
public function __construct(
        \Core\Session $session,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\AuditTrail $auditTrail,
        array $oAuthConfig = []
    ) {        $this->session = $session;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = str_value($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = str_value($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = str_value($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = str_value($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = str_value($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = str_value($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

/** @return array<string, mixed> */
public function handle(string $code, string $state): array
    {
        if (!$this->session->has(SessionKeys::OAUTH_STATE)) {
            return ['success' => false, 'message' => 'Invalid request: session state missing.'];
        }

        $stored = $this->session->get(SessionKeys::OAUTH_STATE);
        $this->session->remove(SessionKeys::OAUTH_STATE);
        $this->session->remove(SessionKeys::OAUTH_STATE . '_used');

        try {
            if (!is_array($stored) || !isset($stored['token']) || !isset($stored['created_at'])) {
                return ['success' => false, 'message' => 'Invalid state structure.'];
            }

            if ($stored['token'] !== $state) {
                return ['success' => false, 'message' => 'Invalid state token match failed.'];
            }

            // HIGH-H-15 Fix: Verify state signature (bound to original IP and Session ID)
            $expectedSignature = hash_hmac('sha256', $state . '|' . ($stored['ip'] ?? '') . '|' . ($stored['session_id'] ?? ''), secure_key());
            if (!hash_equals($expectedSignature, (string)($stored['signature'] ?? ''))) {
                $this->logger->critical('oauth.google.state_signature_mismatch', [
                    'state' => $state,
                    'ip' => $this->clientIp()
                ]);
                return ['success' => false, 'message' => 'State signature verification failed.'];
            }

            // HIGH-06 Fix: Verify session binding (CRIT-01 Fix: Session ID must match exactly)
            if (($stored['session_id'] ?? '') !== $this->session->getId()) {
                $this->logger->critical('oauth.google.session_mismatch', [
                    'expected' => $stored['session_id'] ?? 'none',
                    'current' => $this->session->getId(),
                    'ip' => $this->clientIp()
                ]);
                return ['success' => false, 'message' => 'Session mismatch during OAuth flow.'];
            }

            // CRIT-01 Fix: HIGH-H-10 - Strict IP binding for OAuth flow to prevent replay attacks
            // OAuth flows are particularly vulnerable to man-in-the-middle attacks where attacker
            // starts the flow from different IP than the one completing it
            $expectedIp = $stored['ip'] ?? '';
            $currentIp = $this->clientIp();
            
            if (!$this->matchIpSubnet($expectedIp, $currentIp)) {
                $this->logger->critical('oauth.google.ip_mismatch_replay_attack_detected', [
                    'expected_ip' => $expectedIp,
                    'current_ip' => $currentIp,
                    'state' => $state
                ]);
                
                // HIGH-03 Fix: Audit suspicious IP change during OAuth flow
                $this->auditTrail->record('oauth.google.ip_mismatch_blocked', 0, [
                    'expected_ip' => $expectedIp,
                    'current_ip' => $currentIp,
                    'state' => $state,
                    'session_id' => $this->session->getId()
                ]);

                // CRIT-01 Fix: Block OAuth completion on IP change when strict binding is enabled.
                // Default false to prevent breaking NAT/VPN/Mobile users.
                $strictIpBinding = config('oauth.strict_ip_binding', false) || feature_enabled('oauth_strict_ip_binding');
                if ($strictIpBinding) {
                    $this->session->destroy(); // CRIT-01: Destroy session to prevent any partial state exploitation
                    return ['success' => false, 'message' => 'IP مبدأ تغییر کرده است. به دلایل امنیتی، لطفاً دوباره تلاش کنید.'];
                }
                
                // If not strict, at minimum log and audit
                $this->logger->warning('oauth.google.ip_changed', [
                    'expected' => $expectedIp,
                    'received' => $currentIp
                ]);
            }


            // 🛡️ Hardened Expiration: Bound security state validity to maximum 5 minutes
            if ((time() - (int)$stored['created_at']) > 300) {
                return ['success' => false, 'message' => 'The sign-in state has expired. Please try again.'];
            }

            $token = $this->getGoogleToken($code);
            if (!$token['success']) return $token;

            $idToken = $token['id_token'] ?? null;
            if (!is_string($idToken) || $idToken === '') {
                $this->logger->error('oauth.google.id_token_missing', ['token_resp' => $token]);
                return ['success' => false, 'message' => 'توکن هویتی (ID Token) از طرف گوگل صادر نشده است'];
            }

            // 🛡️ Security Upgrade: استفاده از ID Token و راستی‌آزمایی رمزنگاری شده به جای access_token
            // این کار جلوگیری از هرگونه جعل هویت و جعل دسترسی (Authentication Bypass) را می‌گیرد
            $userInfo = $this->verifyGoogleIdToken($idToken);
            if (!$userInfo['success']) return $userInfo;

            // MED-M-04 Fix: Validate nonce in ID Token to prevent replay attacks
            $userData = is_array($userInfo['data'] ?? null) ? $userInfo['data'] : [];
            if (empty($userData['nonce']) || !hash_equals(str_value($stored['nonce']), str_value($userData['nonce']))) {
                $this->logger->critical('oauth.google.nonce_mismatch', [
                    'expected' => $stored['nonce'] ?? 'none',
                    'received' => $userData['nonce'] ?? 'none'
                ]);
                return ['success' => false, 'message' => 'Nonce validation failed.'];
            }

            return $this->linkOrCreateUser('google', $userData);
        } catch (\Exception $e) {
            $this->logger->error('oauth.google.callback_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ورود با گوگل'];
        } finally {
            $this->session->remove(SessionKeys::OAUTH_STATE);
            $this->session->remove(SessionKeys::OAUTH_STATE . '_used');
        }
    }
}
