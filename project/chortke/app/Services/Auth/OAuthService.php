<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Auth\AuthService;
use Core\Database;use App\Constants\SessionKeys;

class OAuthService
{

    use \App\Traits\ClientInfoTrait;

    private string $googleClientId;
    private string $googleRedirectUri;
    private string $facebookClientId;
    private string $facebookRedirectUri;

    private Database $db;
    private \Core\Session $session;
    /** @var array<string, mixed> */
    private array $oAuthConfig;
    private \App\Jobs\Auth\HandleGoogleCallbackJob $handleGoogleCallbackJob;
    private \App\Jobs\Auth\HandleFacebookCallbackJob $handleFacebookCallbackJob;
    private \App\Jobs\Auth\LinkSocialAccountJob $linkSocialAccountJob;
    private \App\Jobs\Auth\LinkSocialAccountSafeJob $linkSocialAccountSafeJob;
    private \App\Jobs\Auth\UnlinkSocialAccountJob $unlinkSocialAccountJob;
    private \Core\UrlGenerator $urlGenerator;
    /** @param array<string, mixed> $oAuthConfig */
    public function __construct(
        Database $db,
        \Core\Session $session,
        \App\Jobs\Auth\HandleGoogleCallbackJob $handleGoogleCallbackJob,
        \App\Jobs\Auth\HandleFacebookCallbackJob $handleFacebookCallbackJob,
        \App\Jobs\Auth\LinkSocialAccountJob $linkSocialAccountJob,
        \App\Jobs\Auth\LinkSocialAccountSafeJob $linkSocialAccountSafeJob,
        \App\Jobs\Auth\UnlinkSocialAccountJob $unlinkSocialAccountJob,
        \Core\UrlGenerator $urlGenerator,
        array $oAuthConfig = []
    ) {
        $this->db = $db;
        $this->session = $session;
        $this->handleGoogleCallbackJob = $handleGoogleCallbackJob;
        $this->handleFacebookCallbackJob = $handleFacebookCallbackJob;
        $this->linkSocialAccountJob = $linkSocialAccountJob;
        $this->linkSocialAccountSafeJob = $linkSocialAccountSafeJob;
        $this->unlinkSocialAccountJob = $unlinkSocialAccountJob;
        $this->urlGenerator = $urlGenerator;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = str_value($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleRedirectUri = str_value($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = str_value($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookRedirectUri = str_value($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

    /** @return array<string, mixed> */
    public function handleCallback(string $provider, string $code, string $state): array
    {
        if ($provider === "google") {
            return $this->handleGoogleCallback($code, $state);
        } elseif ($provider === "facebook") {
            return $this->handleFacebookCallback($code, $state);
        }
        
        throw new \InvalidArgumentException("Unsupported provider: {$provider}");
    }

    /** @return array<string, mixed> */
    public function handleGoogleCallback(string $code, string $state): array
    {
        return $this->handleGoogleCallbackJob->handle($code, $state);
    }

    /** @return array<string, mixed> */
    public function handleFacebookCallback(string $code, string $state): array
    {
        return $this->handleFacebookCallbackJob->handle($code, $state);
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function linkSocialAccount(int $userId, string $provider, array $userData): array
    {
        return $this->linkSocialAccountJob->handle($userId, $provider, $userData);
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function linkSocialAccountSafe(int $userId, string $provider, array $userData): array
    {
        return $this->linkSocialAccountSafeJob->handle($userId, $provider, $userData);
    }

    /** @return array<string, mixed> */
    public function unlinkSocialAccount(int $userId, string $provider): array
    {
        return $this->unlinkSocialAccountJob->handle($userId, $provider);
    }




    public function getGoogleAuthUrl(): string
    {
        $redirectUri = $this->buildRedirectUri('/auth/callback/google');
        
        // CRIT-01 Fix: Regenerate session ID BEFORE setting OAuth state to prevent session fixation
        // Attackers could set a known session ID before the user initiates OAuth, then hijack after callback
        $this->session->regenerate(true);
        
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        
        // HIGH-H-15 Fix: State signing with IP and Session ID binding to prevent state tampering/forgery
        $ip = $this->clientIp();
        $sessionId = $this->session->getId();
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, secure_key());

        // 🛡️ Security Improvement: Storing cryptographic state with creation timestamp for TTL enforcement.
        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $sessionId,
            'ip'         => $ip
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }



    public function getFacebookAuthUrl(): string
    {
        $redirectUri = $this->buildRedirectUri('/auth/callback/facebook');
        
        // CRIT-01 Fix: Regenerate session ID BEFORE setting OAuth state to prevent session fixation
        $this->session->regenerate(true);
        
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $ip = $this->clientIp();
        $sessionId = $this->session->getId();
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, secure_key());

        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $sessionId,
            'ip'         => $ip
        ]);

        return "https://www.facebook.com/v18.0/dialog/oauth?" . http_build_query([
            'client_id'    => $this->facebookClientId,
            'redirect_uri' => $redirectUri,
            'scope'        => 'email,public_profile',
            'state'        => $state,
        ]);
    }



    /** @return list<\stdClass> */
    public function getLinkedAccounts(int $userId): array
    {
        return $this->db->table('social_accounts')->where('user_id', '=', $userId)->get();
    }



    public function getAuthUrlForLinking(string $provider, int $userId): string
    {
        // Store the fact that we are linking to an existing account
        $this->session->set(SessionKeys::OAUTH_LINKING_USER_ID, $userId);
        
        if ($provider === 'google') {
            return $this->getGoogleAuthUrl();
        } elseif ($provider === 'facebook') {
            return $this->getFacebookAuthUrl();
        }
        
        throw new \InvalidArgumentException("Unsupported provider: {$provider}");
    }

    // clientIp() و userAgent() از ClientInfoTrait تأمین میشن

    private function buildRedirectUri(string $path): string
    {
        if ($path === '/auth/callback/google' && $this->googleRedirectUri !== '') {
            return $this->googleRedirectUri;
        }

        if ($path === '/auth/callback/facebook' && $this->facebookRedirectUri !== '') {
            return $this->facebookRedirectUri;
        }

        return $this->urlGenerator->to($path);
    }









}
