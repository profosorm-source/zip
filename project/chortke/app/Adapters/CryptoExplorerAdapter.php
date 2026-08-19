<?php

namespace App\Adapters;

use App\Traits\ValidatesExternalUrl;

use App\Contracts\LoggerInterface;
use Core\CircuitBreaker;

class CryptoExplorerAdapter implements CryptoVerificationAdapter
{
    use \App\Traits\ValidatesExternalUrl;
    use \App\Traits\ExternalCallTrait;

    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;

    public function __construct(LoggerInterface $logger, CircuitBreaker $circuitBreaker) {
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Verify a crypto transaction using blockchain explorers
     * This is a best-effort verification that may return 'unavailable' for complex cases
     */
    /** @return array{status: string, reason: string} */
    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        $url = $this->getExplorerUrl($network, $txHash);
        if ($url === '#') {
            return ['status' => 'unavailable', 'reason' => 'Explorer ناشناخته'];
        }

        try {
            $html = $this->circuitBreaker->call('crypto_explorer_' . strtolower((string)$network), function() use ($url) {
                return $this->retryTransient(function() use ($url) {
                    $html = $this->fetchPage($url);
                    if ($html === null) {
                        throw new \Core\Exceptions\TransientException('Explorer unavailable or timed out.');
                    }
                    return $html;
                }, 3, 300, 3000);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('crypto.explorer.unavailable', [
                'network' => $network,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'unavailable', 'reason' => 'عدم دسترسی/تحریم/کلادفلر'];
        }

        if (!is_string($html)) {
            $this->logger->warning('crypto.explorer.invalid_response', ['network' => $network]);
            return ['status' => 'unavailable', 'reason' => 'پاسخ نامعتبر از مرورگر بلاکچین'];
        }

        if (stripos($html, 'Just a moment') !== false || stripos($html, 'cloudflare') !== false) {
            return ['status' => 'unavailable', 'reason' => 'محافظ ضدربات'];
        }

        // NOTE: در این نسخه چون استخراج دقیق از TronScan/BscScan بدون API تضمینی نیست،
        // اگر parser قابل اتکا نداشتیم => unavailable و مستقیم manual_review.
        // اگر در آینده امکان parse قابل اتکا فراهم شد، همینجا verified/mismatch می‌دهیم.
        return ['status' => 'unavailable', 'reason' => 'داده قابل استخراج نیست (SPA/JS)'];
    }

    /**
     * Get the blockchain explorer URL for a transaction
     */
    private function getExplorerUrl(string $network, string $txHash): string
    {
        if (!$this->isValidTxHashForNetwork($network, $txHash)) {
            return '#';
        }

        $defaults = [
            'TRC20' => 'https://tronscan.org/#/transaction/',
            'BNB20' => 'https://bscscan.com/tx/',
            'TON'   => 'https://tonscan.org/tx/',
            'SOL'   => 'https://explorer.solana.com/tx/',
        ];
        if (!isset($defaults[$network])) return '#';
        $configured = config('services.crypto.explorer_urls.' . $network, $defaults[$network]);
        $baseUrl = is_string($configured) && $configured !== '' ? $configured : $defaults[$network];
        return rtrim($baseUrl, '/') . '/' . rawurlencode($txHash);
    }

    private function isValidTxHashForNetwork(string $network, string $txHash): bool
    {
        $network = strtoupper((string)$network);
        $txHash = trim((string)$txHash);

        if ($txHash === '') {
            return false;
        }

        switch ($network) {
            case 'BNB20':
                return (bool)preg_match('/^0x[a-f0-9]{64}$/i', $txHash);
            case 'TRC20':
                return (bool)preg_match('/^[a-f0-9]{64}$/i', $txHash);
            case 'SOL':
                return (bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{88}$/', $txHash);
            case 'TON':
                return (bool)preg_match('/^[a-f0-9]{64}$/i', $txHash)
                    || (bool)preg_match('/^[A-Za-z0-9\/\+]{43}=$/', $txHash);
            default:
                return false;
        }
    }

    private function assertAllowedExplorerUrl(string $url): bool
    {
        return $this->isExternalUrlSafe($url);
    }

    /**
     * Fetch HTML content from a URL
     */
    private function fetchPage(string $url): ?string
    {
        if (!$this->assertAllowedExplorerUrl($url)) {
            return null;
        }

        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // ارتقای تقارن معماری با CryptoApiAdapter جهت جلوگیری از مسدود شدن ورکرهای وب در زمان کندی نودهای بلاکچین
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            \curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        \curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ], trace_headers()));

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return null;
        }

        return $response;
    }
}

