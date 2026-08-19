<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\CacheInterface;
use App\Contracts\CircuitBreakerInterface;
use App\Contracts\LoggerInterface;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;

/** Vandar IBAN inquiry provider. */
class VandarInquiryAdapter implements BankInquiryAdapter
{
    use ExternalCallTrait;
    use ValidatesExternalUrl;

    private LoggerInterface $logger;
    private CacheInterface $cache;
    private CircuitBreakerInterface $circuitBreaker;
    private string $apiToken;
    private string $business;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        LoggerInterface $logger,
        CacheInterface $cache,
        CircuitBreakerInterface $circuitBreaker
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->circuitBreaker = $circuitBreaker;
        $this->apiToken = str_value(config('services.vandar.api_token', ''));
        $this->business = str_value(config('services.vandar.business', ''));
        $this->baseUrl = rtrim(str_value(config('services.vandar.base_url', 'https://api.vandar.io')), '/');
        $timeout = int_value(config('services.vandar.timeout', 10));
        $this->timeout = max(2, min(30, $timeout));
    }

    public function inquireIban(string $iban): array
    {
        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        if (!$this->isValidIranianIban($iban)) {
            return ['success'=>false,'message'=>'شماره شبا نامعتبر است.'];
        }
        if (!$this->isConfigured()) {
            return ['success'=>false,'message'=>'پیکربندی Vandar انجام نشده است.'];
        }

        $cacheKey = 'vandar:iban:' . hash('sha256', $iban);
        try {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) return $cached;
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) return $decoded;
            }
        } catch (\Throwable) {
        }

        $url = $this->baseUrl . '/v3/business/' . rawurlencode($this->business) . '/customers/inquiry/iban';
        if (!$this->isExternalUrlSafe($url)) {
            $this->logger->critical('vandar.unsafe_url_blocked', ['host'=>parse_url($url, PHP_URL_HOST)]);
            return ['success'=>false,'message'=>'آدرس سرویس وندار مجاز نیست.'];
        }

        try {
            $result = $this->circuitBreaker->call('vandar_iban', function () use ($url, $iban): array {
                return $this->retryTransient(function () use ($url, $iban): array {
                    $payload = json_encode(['iban'=>$iban,'track_id'=>bin2hex(random_bytes(12))], JSON_THROW_ON_ERROR);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER=>true,
                        CURLOPT_POST=>true,
                        CURLOPT_POSTFIELDS=>$payload,
                        CURLOPT_HTTPHEADER=>[
                            'Accept: application/json',
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $this->apiToken,
                        ],
                        CURLOPT_TIMEOUT=>$this->timeout,
                        CURLOPT_CONNECTTIMEOUT=>max(1, min(5, $this->timeout - 1)),
                        CURLOPT_SSL_VERIFYPEER=>true,
                        CURLOPT_FOLLOWLOCATION=>false,
                    ]);
                    $body = curl_exec($ch);
                    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno = (int)curl_errno($ch);
                    curl_close($ch);
                    if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
                        throw $this->classifyHttpFailure($code, $errno, (string)$body, ['provider'=>'vandar']);
                    }
                    $decoded = json_decode($body, true);
                    if (!is_array($decoded)) {
                        throw new \Core\Exceptions\PermanentFailure('Vandar returned malformed JSON');
                    }
                    return $decoded;
                }, 3, 300, 3000);
            });

            if (!is_array($result)) {
                return ['success'=>false,'message'=>'پاسخ نامعتبر از سرویس وندار.'];
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
            $owners = is_array($data['account_owners'] ?? null) ? $data['account_owners'] : [];
            $ownerNames = [];
            foreach ($owners as $owner) {
                if (!is_array($owner)) continue;
                $name = trim(str_value($owner['firstName'] ?? $owner['first_name'] ?? '') . ' ' . str_value($owner['lastName'] ?? $owner['last_name'] ?? ''));
                if ($name !== '') $ownerNames[] = $name;
            }
            $bank = $data['bank_name'] ?? $data['bank'] ?? null;
            if ($ownerNames === [] || !is_string($bank) || trim($bank) === '') {
                return ['success'=>false,'message'=>'پاسخ استعلام شبا وندار ناقص است.'];
            }

            $normalized = [
                'success'=>true,
                'owner_name'=>implode('، ', $ownerNames),
                'bank'=>trim($bank),
                'iban'=>$iban,
                'message'=>'استعلام با موفقیت انجام شد.',
            ];
            try { $this->cache->put($cacheKey, $normalized, 1440); } catch (\Throwable) {}
            return $normalized;
        } catch (\Throwable $e) {
            $this->logger->error('vandar.inquiry.failed', ['iban'=>$iban,'error'=>$e->getMessage()]);
            return ['success'=>false,'message'=>'عدم برقراری ارتباط با سرویس استعلام شبا: ' . $e->getMessage()];
        }
    }

    public function isConfigured(): bool
    {
        return $this->apiToken !== '' && $this->business !== '' && $this->baseUrl !== '';
    }

    private function isValidIranianIban(string $iban): bool
    {
        if (preg_match('/^IR\d{24}$/', $iban) !== 1) return false;
        $rearranged = substr($iban, 4) . '1827' . substr($iban, 2, 2);
        $remainder = 0;
        foreach (str_split($rearranged) as $digit) {
            $remainder = ($remainder * 10 + (int)$digit) % 97;
        }
        return $remainder === 1;
    }
}
