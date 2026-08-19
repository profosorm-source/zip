<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Traits\ClientInfoTrait;
use App\Traits\ExternalCallTrait;

/**
 * OAuthHelperTrait — متدهای مشترک بین OAuth Jobs
 *
 * این trait متدهای کمکی مشترک (HTTP calls, IP matching, user linking) رو
 * بین HandleGoogleCallbackJob, HandleFacebookCallbackJob و غیره به اشتراک میذاره.
 *
 * clientIp() و userAgent() از ClientInfoTrait ارث‌بری میشن.
 *
 * Jobs باید پراپرتی‌های زیر رو داشته باشن:
 * - $this->session (Core\Session)
 * - $this->logger (LoggerInterface)
 * - $this->googleClientId, $this->googleClientSecret, $this->googleRedirectUri
 * - $this->facebookClientId, $this->facebookClientSecret, $this->facebookRedirectUri
 */
trait OAuthHelperTrait
{
    use ClientInfoTrait;
    use ExternalCallTrait;

    // ─── OAuth HTTP timeouts ─────────────────────────
    private const OAUTH_CONNECT_TIMEOUT = 5;  // connection timeout (s)
    private const OAUTH_READ_TIMEOUT    = 15; // read timeout (s)


    #[ \Core\Attributes\Inject ]
    private \App\Services\Auth\GoogleJwtVerifier $googleVerifier;

    #[ \Core\Attributes\Inject ]
    private \Core\Database $db;

    #[ \Core\Attributes\Inject ]
    private \App\Models\User $userModel;

    #[ \Core\Attributes\Inject ]
    private \App\Services\Auth\AuthService $authService;

    #[ \Core\Attributes\Inject ]
    private \Core\UrlGenerator $urlGenerator;

    #[ \Core\Attributes\Inject ]
    private LinkSocialAccountJob $linkSocialAccountJob;

    /**
     * مقایسه دو IP — حتی اگه پشت NAT/VPN تغییر کرده باشن
     * IPv4: مقایسه /24 subnet
     * IPv6: مقایسه /48 prefix
     */
    private function matchIpSubnet(string $ip1, string $ip2): bool
    {
        if ($ip1 === $ip2) {
            return true;
        }

        // IPv4: مقایسه 3 اکتت اول (/24)
        if (filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts1 = explode('.', $ip1);
            $parts2 = explode('.', $ip2);
            return $parts1[0] === $parts2[0] && $parts1[1] === $parts2[1] && $parts1[2] === $parts2[2];
        }

        // IPv6: مقایسه /48
        if (filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p1 = inet_pton($ip1);
            $p2 = inet_pton($ip2);
            if ($p1 === false || $p2 === false) return false;
            return substr($p1, 0, 6) === substr($p2, 0, 6);
        }

        return false;
    }

    /**
     * Google: exchange authorization code for tokens
     */
    /** @return array<string, mixed> */
private function getGoogleToken(string $code): array
    {
        $redirectUri = $this->googleRedirectUri ?: $this->buildRedirectUri('/auth/callback/google');

        // ✅ Circuit Breaker + Timeout — جلوگیری از hang در صورت کند بودن Google
        try {
            [$response, $httpCode] = $this->callWithBreaker('oauth_google', function () use ($code, $redirectUri): array {
                $ch = curl_init('https://oauth2.googleapis.com/token');
                curl_setopt_array($ch, [
                    CURLOPT_POST            => true,
                    CURLOPT_RETURNTRANSFER  => true,
                    CURLOPT_CONNECTTIMEOUT  => self::OAUTH_CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT         => self::OAUTH_READ_TIMEOUT,
                    CURLOPT_POSTFIELDS      => http_build_query([
                        'code'          => $code,
                        'client_id'     => $this->googleClientId,
                        'client_secret' => $this->googleClientSecret,
                        'redirect_uri'  => $redirectUri,
                        'grant_type'    => 'authorization_code',
                    ]),
                ]);
                $resp     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return [$resp, $httpCode];
            });
        } catch (\Throwable $cbEx) {
            $this->logger->error('oauth.google.circuit_open', ['error' => $cbEx->getMessage()]);
            return ['success' => false, 'message' => 'سرویس احراز هویت موقتاً در دسترس نیست'];
        }

        if ($response === false || $httpCode !== 200) {
            $this->logger->error('oauth.google.token_exchange_failed', ['http_code' => $httpCode]);
            return ['success' => false, 'message' => 'خطا در دریافت توکن از گوگل'];
        }

        $data = (array)(json_decode((string) $response, true) ?? []);
        if (empty($data['access_token'])) {
            return ['success' => false, 'message' => 'توکن دسترسی از گوگل دریافت نشد'];
        }

        return array_merge(['success' => true], $data);
    }

    /**
     * Google: verify ID Token using GoogleJwtVerifier
     */
    /** @return array<string, mixed> */
private function verifyGoogleIdToken(string $idToken): array
    {
        try {
            $payload = $this->googleVerifier->verifyIdToken(
                $idToken,
                $this->googleClientId,
                ['https://accounts.google.com', 'accounts.google.com']
            );

            if (!$payload || empty($payload['sub'])) {
                return ['success' => false, 'message' => 'ID Token نامعتبر است'];
            }

            return [
                'success' => true,
                'data' => [
                    'id' => $payload['sub'],
                    'email' => $payload['email'] ?? null,
                    'name' => $payload['name'] ?? null,
                    'picture' => $payload['picture'] ?? null,
                    'email_verified' => $payload['email_verified'] ?? false,
                    'nonce' => $payload['nonce'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('oauth.google.id_token_verify_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تأیید هویت گوگل'];
        }
    }

    /**
     * Facebook: exchange code for access token
     */
    /** @return array<string, mixed> */
private function getFacebookToken(string $code): array
    {
        $redirectUri = $this->facebookRedirectUri ?: $this->buildRedirectUri('/auth/callback/facebook');

        $url = 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
            'client_id' => $this->facebookClientId,
            'client_secret' => $this->facebookClientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        // ✅ Circuit Breaker برای Facebook token exchange
        try {
            [$response, $httpCode] = $this->callWithBreaker('oauth_facebook', function () use ($url): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::OAUTH_CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::OAUTH_READ_TIMEOUT,
                ]);
                $resp     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return [$resp, $httpCode];
            });
        } catch (\Throwable $cbEx) {
            $this->logger->error('oauth.facebook.circuit_open', ['error' => $cbEx->getMessage()]);
            return ['success' => false, 'message' => 'سرویس احراز هویت موقتاً در دسترس نیست'];
        }

        if ($response === false || $httpCode !== 200) {
            $this->logger->error('oauth.facebook.token_exchange_failed', ['http_code' => $httpCode]);
            return ['success' => false, 'message' => 'خطا در دریافت توکن از فیسبوک'];
        }

        $data = (array)(json_decode((string) $response, true) ?? []);
        if (empty($data['access_token'])) {
            return ['success' => false, 'message' => 'توکن دسترسی از فیسبوک دریافت نشد'];
        }

        return array_merge(['success' => true], $data);
    }

    /**
     * Facebook: verify access token validity
     */
    /** @return array<string, mixed> */
private function verifyFacebookAccessToken(string $accessToken): array
    {
        $appToken = $this->facebookClientId . '|' . $this->facebookClientSecret;
        $url = "https://graph.facebook.com/debug_token?" . http_build_query([
            'input_token' => $accessToken,
            'access_token' => $appToken,
        ]);

        // ✅ Circuit Breaker برای Facebook token verification
        try {
            $response = $this->callWithBreaker('oauth_facebook', function () use ($url): string {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::OAUTH_CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::OAUTH_READ_TIMEOUT,
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                return is_string($resp) ? $resp : '';
            });
        } catch (\Throwable $cbEx) {
            $this->logger->error('oauth.facebook.verify_circuit_open', ['error' => $cbEx->getMessage()]);
            return ['success' => false, 'message' => 'سرویس احراز هویت موقتاً در دسترس نیست'];
        }

        $data = (array)(json_decode($response ?: '', true) ?? []);

        $tokenData = is_array($data['data'] ?? null) ? $data['data'] : [];
        if (empty($tokenData['is_valid'])) {
            $this->logger->warning('oauth.facebook.token_invalid', ['response' => $data]);
            return ['success' => false, 'message' => 'توکن فیسبوک نامعتبر است'];
        }

        return ['success' => true, 'data' => $tokenData];
    }

    /**
     * Facebook: get user profile
     */
    /** @return array<string, mixed> */
private function getFacebookUserInfo(string $accessToken): array
    {
        $url = "https://graph.facebook.com/v18.0/me?" . http_build_query([
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        // ✅ Circuit Breaker برای Facebook user info
        try {
            $response = $this->callWithBreaker('oauth_facebook', function () use ($url): string {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::OAUTH_CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::OAUTH_READ_TIMEOUT,
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                return is_string($resp) ? $resp : '';
            });
        } catch (\Throwable $cbEx) {
            $this->logger->error('oauth.facebook.userinfo_circuit_open', ['error' => $cbEx->getMessage()]);
            return ['success' => false, 'message' => 'سرویس احراز هویت موقتاً در دسترس نیست'];
        }

        $data = (array)(json_decode($response ?: '', true) ?? []);

        if (empty($data['id'])) {
            return ['success' => false, 'message' => 'اطلاعات کاربر از فیسبوک دریافت نشد'];
        }

        return [
            'success' => true,
            'data' => [
                'id' => $data['id'],
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? null,
                'picture' => $data['picture']['data']['url'] ?? null,
            ],
        ];
    }

    /**
     * Link social account to existing user or create new user
     */
/**
 * @param array<string, mixed> $userData
 * @return array<string, mixed>
 */
private function linkOrCreateUser(string $provider, array $userData): array
    {
        try {
            $providerId = str_value($userData['id'] ?? '');
            $emailValue = $userData['email'] ?? null;
            $email = is_string($emailValue) && $emailValue !== '' ? $emailValue : null;

            if (empty($providerId)) {
                return ['success' => false, 'message' => 'شناسه ارائه‌دهنده نامعتبر است'];
            }

            // بررسی linking user (اگه کاربر لاگین شده و میخواد حساب رو link کنه)
            $session = \Core\Session::getInstance();
            $linkingUserId = $session->get(\App\Constants\SessionKeys::OAUTH_LINKING_USER_ID);
            $session->remove(\App\Constants\SessionKeys::OAUTH_LINKING_USER_ID);

            // آیا این social account قبلاً ثبت شده؟
            $existing = $this->db->table('social_accounts')
                ->where('provider', '=', $provider)
                ->where('provider_id', '=', $providerId)
                ->first();

            if ($existing) {
                // حساب موجوده — login
                $user = $this->userModel->find((int)$existing->user_id);
                if (!$user) {
                    return ['success' => false, 'message' => 'حساب کاربری مرتبط یافت نشد'];
                }
                return $this->authService->loginDirectly($user);
            }

            // اگه linking
            if ($linkingUserId) {
                $linkingUserIdInt = is_int($linkingUserId)
                    ? $linkingUserId
                    : (is_numeric($linkingUserId) ? (int)$linkingUserId : 0);
                if ($linkingUserIdInt <= 0) {
                    return ['success' => false, 'message' => 'شناسه کاربر برای اتصال حساب نامعتبر است'];
                }
                return $this->linkSocialAccountJob->handle($linkingUserIdInt, $provider, $userData);
            }

            // کاربر جدید — اگه email داره ببینیم قبلاً ثبت شده یا نه
            if ($email) {
                $existingUser = $this->userModel->findByEmail($email);
                if ($existingUser) {
                    // Link + login
                    $this->db->table('social_accounts')->insert([
                        'user_id' => (int)$existingUser->id,
                        'provider' => $provider,
                        'provider_id' => $providerId,
                        'avatar' => $userData['picture'] ?? null,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    return $this->authService->loginDirectly($existingUser);
                }
            }

            // ثبت‌نام جدید
            $registerData = [
                'email' => $email,
                'full_name' => $userData['name'] ?? '',
                'password' => bin2hex(random_bytes(16)),
                'oauth_provider' => $provider,
                'oauth_provider_id' => $providerId,
                'avatar' => $userData['picture'] ?? null,
                'email_verified_at' => ($userData['email_verified'] ?? false) ? date('Y-m-d H:i:s') : null,
            ];

            $result = $this->authService->register($registerData);
            if (!($result['success'] ?? false)) {
                return $result;
            }

            // link social account
            $newUser = $email !== null ? $this->userModel->findByEmail($email) : null;
            if ($newUser) {
                $this->db->table('social_accounts')->insert([
                    'user_id' => (int)$newUser->id,
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $userData['picture'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return $this->authService->loginDirectly($newUser);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("oauth.{$provider}.link_or_create_failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ایجاد یا اتصال حساب'];
        }
    }

    private function buildRedirectUri(string $path): string
    {
        return $this->urlGenerator->to($path);
    }
}
