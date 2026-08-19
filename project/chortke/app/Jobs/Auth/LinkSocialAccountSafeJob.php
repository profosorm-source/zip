<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class LinkSocialAccountSafeJob
{
    use OAuthHelperTrait;
    private string $googleClientId;
    private string $googleClientSecret;
    private string $googleRedirectUri;
    private string $facebookClientId;
    private string $facebookClientSecret;
    private string $facebookRedirectUri;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $oAuthConfig;
    private LinkSocialAccountJob $linkJob;
    /** @param array<string, mixed> $oAuthConfig */
public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        LinkSocialAccountJob $linkJob,
        array $oAuthConfig = []
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->linkJob = $linkJob;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = str_value($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = str_value($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = str_value($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = str_value($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = str_value($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = str_value($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

/**
 * @param array<string, mixed> $userData
 * @return array<string, mixed>
 */
public function handle(int $userId, string $provider, array $userData): array
    {
        $this->db->beginTransaction();
        try {
            $result = $this->linkJob->handle($userId, $provider, $userData);
            if ($result['success']) {
                $this->db->commit();
            } else {
                $this->db->rollback();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('oauth.linkSocialAccountSafe_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی در اتصال حساب'];
        }
    }
}
