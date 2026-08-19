<?php

namespace App\Adapters;

use App\Services\Settings\AppSettings;
use App\Contracts\LoggerInterface;
use Core\CircuitBreaker;
use Core\RateLimiter;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;

class CryptoApiAdapter implements CryptoVerificationAdapter
{
    use \App\Traits\ValidatesExternalUrl;
    use ExternalCallTrait;

    private LoggerInterface $logger;
    private AppSettings $appSettings;

    private CircuitBreaker $circuit;
    private ?RateLimiter $rateLimiter;

    public function __construct(
        LoggerInterface $logger,
        AppSettings $appSettings,
        CircuitBreaker $circuit,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->circuit = $circuit;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Verify a crypto transaction using API calls to blockchain explorers
     */
    protected function circuitBreakerForExternalCalls(): ?CircuitBreaker
    {
        return $this->circuit;
    }

    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        $network = strtoupper(trim($network));
        $txHash = trim($txHash);
        $toWallet = trim($toWallet);
        $expectedAmount = trim($expectedAmount);
        if (!in_array($network, ['TRC20','BNB20','TON','SOL'], true)) {
            return ['status'=>'error','reason'=>'شبکه پشتیبانی نمی‌شود'];
        }
        if (!$this->isValidTxHashForNetwork($network, $txHash)) {
            return ['status'=>'error','reason'=>'هش تراکنش نامعتبر است'];
        }
        if ($toWallet === '' || preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $expectedAmount) !== 1 || bccomp($expectedAmount, '0', 8) <= 0) {
            return ['status'=>'error','reason'=>'مقصد یا مبلغ مورد انتظار نامعتبر است'];
        }
        return $this->verifyTransaction($network, $txHash, trim($fromWallet), $toWallet, $expectedAmount);
    }

    private function isValidTxHashForNetwork(string $network, string $txHash): bool
    {
        return match ($network) {
            'BNB20' => preg_match('/^0x[a-f0-9]{64}$/i', $txHash) === 1,
            'TRC20' => preg_match('/^[a-f0-9]{64}$/i', $txHash) === 1,
            'SOL' => preg_match('/^[1-9A-HJ-NP-Za-km-z]{88}$/', $txHash) === 1,
            'TON' => preg_match('/^[a-f0-9]{64}$/i', $txHash) === 1
                || preg_match('/^[A-Za-z0-9\/+]{43}=$/', $txHash) === 1,
            default => false,
        };
    }

    private function providerUrl(string $key, string $default): string
    {
        $value = config('services.crypto.' . $key, $default);
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Verify transaction based on network
     */
    /** @return array<string, mixed> */
    private function verifyTransaction(string $network, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        switch ($network) {
            case 'TRC20':
                $result = $this->verifyTronTransaction($txHash, $fromWallet, $toWallet, $expectedAmount);
                break;
            case 'BNB20':
                $result = $this->verifyBscTransaction($txHash, $fromWallet, $toWallet, $expectedAmount);
                break;
            case 'TON':
                $result = $this->verifyTonTransaction($txHash, $fromWallet, $toWallet, $expectedAmount);
                break;
            case 'SOL':
                $result = $this->verifySolanaTransaction($txHash, $fromWallet, $toWallet, $expectedAmount);
                break;
            default:
                $result = ['status' => 'error', 'reason' => 'شبکه پشتیبانی نمی‌شود'];
        }

        return $result;
    }

    /**
     * Section 8.3/8.4 — single source of truth for circuit-breaker + retry +
     * failure classification via App\Traits\ExternalCallTrait.
     * Supports multiple URLs for Provider Chain / Fallback Strategy.
     *
     * Returns the response body on success, or null on (Permanent/CB-open) across all URLs.
     */
    /** @param list<string> $urls */
    private function executeWithRetry(array $urls): ?string
    {
        $timeout = (is_numeric($this->appSettings->get('crypto_api_timeout', 15)) ? (int)$this->appSettings->get('crypto_api_timeout', 15) : 15);
        $lastError = null;

        // L-25 Fix: allowlist دامنه برای مسیر خارجیِ ورودی منطق مالی؛ در صورت خالی بودن
        // رفتار قبلی (فقط SSRF) حفظ می‌شود تا سازگاری به عقب حفظ گردد.
        $allowedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', str_value($this->appSettings->get('crypto_api_allowed_hosts', '')))
        ), static fn($h) => $h !== ''));

        foreach ((array)$urls as $index => $url) {
            // ✅ SSRF check + L-25 allowlist دامنه — جلوگیری از تماس با شبکهٔ داخلی و دامنهٔ غیرمجاز
            if (!$this->isExternalUrlSafe($url, $allowedHosts)) {
                $this->logger->warning('crypto.api.ssrf_blocked', ['url' => $url]);
                continue;
            }
            // Every external verification call is rate-limited per provider,
            // regardless of whether the URL contains an API key. The key only
            // changes provider quota, never our own request budget.
            if ($this->rateLimiter !== null) {
                $host = parse_url($url, PHP_URL_HOST);
                $provider = is_string($host) && $host !== '' ? strtolower($host) : 'unknown';
                $maxPerMinute = max(1, min(120, (is_numeric($this->appSettings->get('crypto_provider_requests_per_minute', 30)) ? (int)$this->appSettings->get('crypto_provider_requests_per_minute', 30) : 30)));
                if (!$this->rateLimiter->attempt('crypto_provider:' . $provider, $maxPerMinute, 1, true)) {
                    $this->logger->warning('crypto.api.rate_limited_locally', ['provider' => $provider]);
                    continue;
                }
            }

            try {
                // Use a separate breaker for each endpoint to isolate failures
                $breakerName = 'crypto_api_' . parse_url($url, PHP_URL_HOST);
                
                $response = $this->callWithBreaker($breakerName, function () use ($url, $timeout): string {
                    return $this->retryTransient(function () use ($url, $timeout): string {
                        $ch = \curl_init($url);
                        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, max(2, (int)floor($timeout / 2))));
                        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        \curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
                            'User-Agent: ChortkeSecureApp/1.0 (+https://chortke.com)',
                            'Accept: application/json',
                        ], trace_headers()));
                        $response = \curl_exec($ch);
                        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno    = (int) \curl_errno($ch);
                        $error    = \curl_error($ch);
                        \curl_close($ch);

                        if ($httpCode === 200 && is_string($response) && $response !== '') {
                            return $response;
                        }

                        $this->logger->warning('crypto.api.attempt_failed', [
                            'url'       => $url,
                            'http_code' => $httpCode,
                            'errno'     => $errno,
                            'error'     => $error ?: 'HTTP Status ' . $httpCode,
                        ]);
                        throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'crypto_api']);
                    }, 3, 500, 4000);
                });

                if ($response !== null) {
                    return $response;
                }
            } catch (\Core\Exceptions\PermanentFailure $e) {
                $lastError = $e->getMessage();
                $this->logger->warning('crypto.api.permanent_failure', ['url' => $url, 'error' => $lastError]);
                // If permanent failure (e.g., 400 Bad Request), it's usually bad input, not a node issue.
                // However, we still try the next node just in case it's a proxy error.
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('crypto.api.unavailable', [
                    'url'   => $url,
                    'class' => get_class($e),
                    'error' => $lastError,
                ]);
            }
        }
        
        $this->logger->critical('crypto.api.all_nodes_failed', [
            'urls' => $urls,
            'last_error' => $lastError
        ]);
        
        return null;
    }

    /**
     * Normalize Tron/BSC addresses to lower-case / hexadecimal representation for safe matches
     */
    private function normalizeAddress(string $address, string $network): string
    {
        $address = trim((string)$address);
        if (strtolower((string)$network) === 'tron') {
            $base58Contract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
            $hexContract = '41a614f803b6c4804147c4e8e89f8113730e11a252';
            $addrLower = strtolower((string)$address);
            if ($addrLower === strtolower((string)$base58Contract) || $addrLower === strtolower((string)$hexContract) || $addrLower === 'tr7nhqjekqxgwtci8q8zy4pl8otszgjlj6t') {
                return 'tr7nhqjekqxgwtci8q8zy4pl8otszgjlj6t';
            }
        }
        return strtolower((string)$address);
    }

    /**
     * Verify TRON transaction
     */
    /** @return array<string, mixed> */
    private function verifyTronTransaction(string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        try {
            $urls = [
                sprintf($this->providerUrl('tronscan_transaction_url', 'https://apilist.tronscan.org/api/transaction-info?hash=%s'), rawurlencode($txHash)),
                sprintf($this->providerUrl('trongrid_events_url', 'https://api.trongrid.io/v1/transactions/%s/events'), rawurlencode($txHash)),
            ];
            $response = $this->executeWithRetry($urls);

            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به TronScan API یا فعال بودن مدار قطع‌کننده (Circuit Breaker)'];
            }

            $decoded = json_decode((string)$response, true);
            if (!is_array($decoded)) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از API'];
            }
            // TronGrid event endpoint has a different response schema from
            // TronScan. Parse it separately; never force it through the
            // TronScan contractData parser.
            if (!isset($decoded['contractData']) && isset($decoded['data']) && is_array($decoded['data'])) {
                return $this->verifyTronGridEvents($decoded, $fromWallet, $toWallet, $expectedAmount);
            }
            if (!isset($decoded['contractData']) && isset($decoded['token_transfers']) && is_array($decoded['token_transfers'])) {
                return $this->verifyTronScanTransferFeed($decoded, $txHash, $fromWallet, $toWallet, $expectedAmount);
            }
            if (!isset($decoded['contractData']) || !is_array($decoded['contractData'])) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از TronScan'];
            }
            $data = $decoded;
            $contractData = $data['contractData'];

            if (trim($fromWallet) !== '') {
                $chainFrom = $contractData['owner_address'] ?? $contractData['from_address'] ?? '';
                if (!is_string($chainFrom) || $chainFrom === '') {
                    return ['status' => 'error', 'reason' => 'آدرس مبدا برای تطبیق در پاسخ TRON موجود نیست'];
                }
                if ($this->normalizeAddress($chainFrom, 'tron') !== $this->normalizeAddress($fromWallet, 'tron')) {
                    return ['status' => 'mismatch', 'reason' => 'آدرس مبدا تراکنش با آدرس ثبت‌شده مطابقت ندارد'];
                }
            }

            // Issue 1: Check status and confirmations
            if (!isset($data['confirmed']) || $data['confirmed'] !== true || !isset($data['contractRet']) || $data['contractRet'] !== 'SUCCESS') {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز تایید نهایی نشده است'];
            }

            // Get dynamic block confirmations count (C-05)
            $currentBlockUrls = [
                $this->providerUrl('tronscan_status_url', 'https://apilist.tronscan.org/api/system/status'),
                $this->providerUrl('trongrid_block_url', 'https://api.trongrid.io/wallet/getnowblock'),
            ];
            $blockResponse = $this->executeWithRetry($currentBlockUrls);
            $currentBlock = 0;
            if (is_string($blockResponse) && $blockResponse !== '') {
                $blockData = json_decode($blockResponse, true);
                if (is_array($blockData) && isset($blockData['database']) && is_array($blockData['database'])) {
                    $rawBlock = $blockData['database']['block'] ?? 0;
                    $currentBlock = is_int($rawBlock) || (is_string($rawBlock) && ctype_digit($rawBlock)) ? (int)$rawBlock : 0;
                }
            }

            $txBlock = (int)($data['block'] ?? 0);
            $confirmations = ($currentBlock > 0 && $txBlock > 0) ? ($currentBlock - $txBlock) : (isset($data['confirmations']) ? (int)$data['confirmations'] : 0);
            $minValue = $this->appSettings->get('crypto_min_confirmations_trc20', 19);
            $minConfirmations = is_numeric($minValue) ? (int)$minValue : 19;

            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش TRON کافی نیست (نیاز به حداقل $minConfirmations تایید دارد، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (Fake Token Transfer) with config support and normalization
            $validContract = $this->appSettings->get('crypto_contract_trc20_usdt', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
            $receivedContract = $contractData['contract_address'] ?? '';
            if (!is_string($validContract) || !is_string($receivedContract) || $validContract === '' || $receivedContract === '') {
                return ['status' => 'error', 'reason' => 'پاسخ یا تنظیمات قرارداد USDT نامعتبر است'];
            }

            if ($this->normalizeAddress($receivedContract, 'tron') !== $this->normalizeAddress($validContract, 'tron')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT نیست'];
            }

            // Check if transaction is to our wallet
            $to = $contractData['to_address'] ?? '';
            if (!is_string($to) || $to === '') {
                return ['status' => 'error', 'reason' => 'آدرس گیرنده در پاسخ API نامعتبر است'];
            }
            if ($this->normalizeAddress($to, 'tron') !== $this->normalizeAddress($toWallet, 'tron')) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount using integer comparisons to avoid float precision bugs (H-02)
            $rawAmount = $contractData['amount'] ?? null;
            if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
                return ['status' => 'error', 'reason' => 'مبلغ تراکنش در پاسخ API نامعتبر است'];
            }
            $amountRaw = (int)$rawAmount;
            $expectedRaw = (int)\bcadd(\bcmul($expectedAmount, '1000000', 8), '0.5', 0);
            $toleranceRaw = 10000; // 0.01 USDT tolerance in SUN units

            if (abs($amountRaw - $expectedRaw) > $toleranceRaw) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $data];

        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.tron.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش TRON'];
        }
    }

    /** Parse TronScan token transfer feed responses (different from transaction-info). */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function verifyTronScanTransferFeed(array $payload, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        $transfers = $payload['token_transfers'] ?? null;
        if (!is_array($transfers)) return ['status' => 'error', 'reason' => 'فهرست انتقال‌های TronScan نامعتبر است'];
        $tx = null;
        foreach ($transfers as $candidate) {
            if (is_array($candidate) && isset($candidate['transaction_id']) && is_string($candidate['transaction_id']) && strtolower($candidate['transaction_id']) === strtolower($txHash)) { $tx = $candidate; break; }
        }
        if (!is_array($tx)) return ['status' => 'pending', 'reason' => 'انتقال موردنظر در feed TronScan یافت نشد'];
        if (($tx['confirmed'] ?? false) !== true || ($tx['contractRet'] ?? '') !== 'SUCCESS') return ['status' => 'pending', 'reason' => 'تاییدهای TronScan کافی نیست'];
        $contract=$tx['contract_address']??''; $from=$tx['from_address']??''; $to=$tx['to_address']??''; $quant=$tx['quant']??null;
        if (!is_string($contract)||!is_string($from)||!is_string($to)||(!is_int($quant)&&!(is_string($quant)&&ctype_digit($quant)))) return ['status'=>'error','reason'=>'دادهٔ انتقال TronScan ناقص است'];
        $valid=$this->appSettings->get('crypto_contract_trc20_usdt','TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        if(!is_string($valid)||$this->normalizeAddress($contract,'tron')!==$this->normalizeAddress($valid,'tron')) return ['status'=>'mismatch','reason'=>'توکن feed TronScan USDT نیست'];
        if($this->normalizeAddress($to,'tron')!==$this->normalizeAddress($toWallet,'tron')) return ['status'=>'mismatch','reason'=>'گیرنده feed TronScan مطابقت ندارد'];
        if(trim($fromWallet)!==''&&$this->normalizeAddress($from,'tron')!==$this->normalizeAddress($fromWallet,'tron')) return ['status'=>'mismatch','reason'=>'فرستنده feed TronScan مطابقت ندارد'];
        if(abs((int)$quant-(int)\bcadd(\bcmul($expectedAmount, '1000000', 8), '0.5', 0))>10000)return ['status'=>'mismatch','reason'=>'مبلغ feed TronScan مطابقت ندارد'];
        return ['status'=>'verified','details'=>$tx];
    }

    /**
     * Parse the public TronGrid event endpoint. This endpoint intentionally has
     * a separate parser because its schema differs from TronScan's contractData.
     */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function verifyTronGridEvents(array $payload, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        $events = $payload['data'] ?? null;
        if (!is_array($events)) {
            return ['status' => 'error', 'reason' => 'فهرست رویدادهای TronGrid نامعتبر است'];
        }

        $event = null;
        foreach ($events as $candidate) {
            if (!is_array($candidate) || ($candidate['event_name'] ?? '') !== 'Transfer') {
                continue;
            }
            $result = $candidate['result'] ?? null;
            if (is_array($result)) {
                $event = $candidate;
                break;
            }
        }
        if ($event === null) {
            return ['status' => 'pending', 'reason' => 'رویداد انتقال USDT در TronGrid یافت نشد'];
        }

        $result = $event['result'];
        $contract = $event['contract_address'] ?? '';
        $from = $result['from'] ?? '';
        $to = $result['to'] ?? '';
        $value = $result['value'] ?? null;
        if (!is_string($contract) || !is_string($from) || !is_string($to) || (!is_int($value) && !(is_string($value) && ctype_digit($value)))) {
            return ['status' => 'error', 'reason' => 'دادهٔ رویداد TronGrid برای تطبیق کافی نیست'];
        }

        $validContract = $this->appSettings->get('crypto_contract_trc20_usdt', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
        if (!is_string($validContract) || $this->normalizeAddress($contract, 'tron') !== $this->normalizeAddress($validContract, 'tron')) {
            return ['status' => 'mismatch', 'reason' => 'توکن رویداد TronGrid USDT نیست'];
        }
        if ($this->normalizeAddress($to, 'tron') !== $this->normalizeAddress($toWallet, 'tron')) {
            return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده TronGrid مطابقت ندارد'];
        }
        if (trim($fromWallet) !== '' && $this->normalizeAddress($from, 'tron') !== $this->normalizeAddress($fromWallet, 'tron')) {
            return ['status' => 'mismatch', 'reason' => 'آدرس مبدا TronGrid با آدرس ثبت‌شده مطابقت ندارد'];
        }

        $amountRaw = (int)$value;
        $expectedRaw = (int)\bcadd(\bcmul($expectedAmount, '1000000', 8), '0.5', 0);
        if (abs($amountRaw - $expectedRaw) > 10000) {
            return ['status' => 'mismatch', 'reason' => 'مبلغ رویداد TronGrid مطابقت ندارد'];
        }

        $eventBlock = $event['block_number'] ?? null;
        if (!is_int($eventBlock) && !(is_string($eventBlock) && ctype_digit($eventBlock))) {
            return ['status' => 'error', 'reason' => 'شماره بلاک TronGrid برای تاییدها موجود نیست'];
        }
        $blockResponse = $this->executeWithRetry([
            $this->providerUrl('tronscan_status_url', 'https://apilist.tronscan.org/api/system/status'),
            $this->providerUrl('trongrid_block_url', 'https://api.trongrid.io/wallet/getnowblock'),
        ]);
        $blockData = is_string($blockResponse) ? json_decode($blockResponse, true) : null;
        $currentBlock = 0;
        if (is_array($blockData)) {
            $raw = $blockData['database']['block'] ?? $blockData['block_header']['raw_data']['number'] ?? 0;
            $currentBlock = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int)$raw : 0;
        }
        if ($currentBlock <= (int)$eventBlock) {
            return ['status' => 'pending', 'reason' => 'تاییدهای TronGrid هنوز کافی نیست'];
        }
        $min = (is_numeric($this->appSettings->get('crypto_min_confirmations_trc20', 19)) ? (int)$this->appSettings->get('crypto_min_confirmations_trc20', 19) : 19);
        if (($currentBlock - (int)$eventBlock) < $min) {
            return ['status' => 'pending', 'reason' => 'تعداد تاییدهای TronGrid کافی نیست'];
        }

        return ['status' => 'verified', 'details' => $event];
    }

    /**
     * Public JSON-RPC fallback for EVM chains when explorer data is unavailable.
     * @return array<string, mixed>
     */
    private function verifyEvmRpcFallback(string $network, string $settingKey, string $defaultUrl, string $txHash, string $fromWallet, string $toWallet, string $expectedAmount, string $usdtContract, int $decimals, string $confirmationSetting): array
    {
        $rpcUrl = $this->appSettings->get($settingKey, $defaultUrl);
        if (!is_string($rpcUrl) || !$this->isExternalUrlSafe($rpcUrl)) return ['status' => 'error', 'reason' => 'RPC عمومی این شبکه تنظیم یا معتبر نیست'];
        $receipt = $this->executeJsonRpc($rpcUrl, 'eth_getTransactionReceipt', [$txHash]);
        if (!is_array($receipt) || !array_key_exists('result', $receipt)) return ['status' => 'error', 'reason' => 'پاسخ RPC برای receipt کافی نیست'];
        if ($receipt['result'] === null) return ['status' => 'pending', 'reason' => 'تراکنش هنوز در RPC عمومی یافت نشده است'];
        $r = $receipt['result'];
        if (!is_array($r) || ($r['status'] ?? '') !== '0x1') return ['status' => 'mismatch', 'reason' => 'receipt تراکنش ناموفق است'];
        // receipt.from is the transaction caller and may be a router contract.
        // The relevant sender for a BEP20 USDT deposit is the Transfer topic[1].
        $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $matched = null;
        foreach (($r['logs'] ?? []) as $log) {
            if (!is_array($log) || strtolower((string)($log['address'] ?? '')) !== strtolower($usdtContract)) continue;
            $topics = $log['topics'] ?? [];
            if (!is_array($topics) || count($topics) < 3 || strtolower((string)$topics[0]) !== $transferTopic) continue;
            $recipient = '0x' . substr((string)$topics[2], -40);
            if ($this->normalizeAddress($recipient, 'evm') !== $this->normalizeAddress($toWallet, 'evm')) continue;
            $matched = $log; break;
        }
        if (!is_array($matched)) return ['status' => 'mismatch', 'reason' => 'رویداد انتقال USDT به آدرس مقصد یافت نشد'];
        if (trim($fromWallet) !== '') {
            $topics = $matched['topics'] ?? [];
            $tokenFrom = is_array($topics) && isset($topics[1]) ? '0x' . substr((string)$topics[1], -40) : '';
            if ($tokenFrom === '' || $this->normalizeAddress($tokenFrom, 'evm') !== $this->normalizeAddress($fromWallet, 'evm')) {
                return ['status' => 'mismatch', 'reason' => 'آدرس فرستنده انتقال USDT با آدرس ثبت‌شده مطابقت ندارد'];
            }
        }
        $raw = $this->hexToDecimal((string)($matched['data'] ?? ''));
        if ($raw === null) return ['status' => 'error', 'reason' => 'مبلغ raw در RPC نامعتبر است'];
        $expected = bcmul((string)$expectedAmount, bcpow('10', (string)$decimals, 0), 0);
        $tolerance = bcmul('0.01', bcpow('10', (string)$decimals, 0), 0);
        $diff = bcsub($raw, $expected, 0); if (str_starts_with($diff, '-')) $diff = substr($diff, 1);
        if (bccomp($diff, $tolerance, 0) === 1) return ['status' => 'mismatch', 'reason' => 'مبلغ انتقال RPC مطابقت ندارد'];
        $block = $this->executeJsonRpc($rpcUrl, 'eth_blockNumber', []);
        $current = is_array($block) && is_string($block['result'] ?? null) ? hexdec((string)$block['result']) : 0;
        $txBlock = isset($r['blockNumber']) && is_string($r['blockNumber']) ? hexdec($r['blockNumber']) : 0;
        $minValue = $this->appSettings->get($confirmationSetting, 12);
        $min = is_numeric($minValue) ? (int)$minValue : 12;
        if ($current <= $txBlock || ($current - $txBlock) < $min) return ['status' => 'pending', 'reason' => 'تاییدهای RPC کافی نیست'];
        return ['status' => 'verified', 'details' => $r];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function executeJsonRpc(string $url, string $method, array $params): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('crypto_provider:' . strtolower(is_string($host) ? $host : 'rpc'), max(1, min(120, (is_numeric($this->appSettings->get('crypto_provider_requests_per_minute', 30)) ? (int)$this->appSettings->get('crypto_provider_requests_per_minute', 30) : 30))), 1, true)) return null;
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        if (!is_string($payload)) return null;
        try {
            $response = $this->callWithBreaker('crypto_rpc_' . strtolower(is_string($host) ? $host : 'rpc'), function () use ($url, $payload): string {
                return $this->retryTransient(function () use ($url, $payload): string {
                    $timeout = is_numeric($this->appSettings->get('crypto_api_timeout',15)) ? (int)$this->appSettings->get('crypto_api_timeout',15) : 15;
                    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>max(2,min(8,(int)floor($timeout/2))),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json']]);
                    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $errno=curl_errno($ch); curl_close($ch);
                    if ($code===200 && is_string($body) && $body!=='') return $body;
                    throw $this->classifyHttpFailure($code,$errno,(string)$body,['provider'=>'crypto_rpc']);
                }, 2, 500, 2000);
            });
            $decoded = json_decode((string)$response, true); return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) { return null; }
    }

    private function hexToDecimal(string $hex): ?string
    {
        $hex = strtolower(preg_replace('/^0x/', '', trim($hex)) ?? '');
        if ($hex === '' || !ctype_xdigit($hex)) return null;
        $value = '0'; foreach (str_split($hex) as $digit) $value = bcadd(bcmul($value, '16', 0), (string)hexdec($digit), 0);
        return $value;
    }

    /**
     * Verify BSC transaction
     */
    /** @return array<string, mixed> */
    private function verifyBscTransaction(string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        try {
            $apiKey = $this->appSettings->get('bscscan_api_key', '');
            // API key only raises provider rate limits; public BscScan access
            // remains a valid verification path when the admin leaves it empty.
            $apiSuffix = is_string($apiKey) && trim($apiKey) !== ''
                ? '&apikey=' . urlencode($apiKey)
                : '';
            $urls = [
                sprintf($this->providerUrl('bscscan_url', 'https://api.bscscan.com/api?module=account&action=tokentx&txhash=%s'), rawurlencode($txHash)) . $apiSuffix,
                sprintf($this->providerUrl('bscscan_fallback_url', 'https://api-testnet.bscscan.com/api?module=account&action=tokentx&txhash=%s'), rawurlencode($txHash)) . $apiSuffix,
            ];
            
            $response = $this->executeWithRetry($urls);

            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به BscScan API یا فعال بودن مدار قطع‌کننده (Circuit Breaker)'];
            }

            $data = json_decode((string)$response, true);
            $tx = null;
            if (is_array($data) && isset($data['result']) && is_array($data['result']) && isset($data['result'][0]) && is_array($data['result'][0])) {
                $tx = $data['result'][0];
            }

            if (!is_array($tx)) {
                return $this->verifyEvmRpcFallback('BNB20', 'bsc_rpc_url', 'https://bsc-dataseed.binance.org/', $txHash, $fromWallet, $toWallet, $expectedAmount, '0x55d398326f99059ff775485246999027b3197955', 18, 'crypto_min_confirmations_bnb20');
            }

            if (trim($fromWallet) !== '') {
                $chainFrom = $tx['from'] ?? '';
                if (!is_string($chainFrom) || $chainFrom === '') {
                    return ['status' => 'error', 'reason' => 'آدرس مبدا برای تطبیق در پاسخ BSC موجود نیست'];
                }
                if ($this->normalizeAddress($chainFrom, 'bsc') !== $this->normalizeAddress($fromWallet, 'bsc')) {
                    return ['status' => 'mismatch', 'reason' => 'آدرس مبدا تراکنش با آدرس ثبت‌شده مطابقت ندارد'];
                }
            }

            // Issue 1: Confirmation check (C-05)
            if (!isset($tx['blockNumber']) || empty($tx['blockNumber'])) {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز در بلاک قرار نگرفته است'];
            }

            $confirmations = isset($tx['confirmations']) ? (int)$tx['confirmations'] : 0;
            $minValue = $this->appSettings->get('crypto_min_confirmations_bnb20', \App\Constants\CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_BNB20);
            $minConfirmations = is_numeric($minValue) ? (int)$minValue : \App\Constants\CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_BNB20;
            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش BSC کافی نیست (حداقل $minConfirmations تایید نیاز است، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (USDT BEP20) from Settings/Config
            $validContract = $this->appSettings->get('crypto_contract_bnb20_usdt', '0x55d398326f99059ff775485246999027b3197955');
            $contractAddress = $tx['contractAddress'] ?? '';
            if (!is_string($validContract) || !is_string($contractAddress) || $validContract === '' || $contractAddress === '') {
                return ['status' => 'error', 'reason' => 'پاسخ یا تنظیمات قرارداد BEP20 نامعتبر است'];
            }
            if ($this->normalizeAddress($contractAddress, 'bsc') !== $this->normalizeAddress($validContract, 'bsc')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT (BEP20) نیست'];
            }

            // Check receiver
            if ($this->normalizeAddress($tx['to'] ?? '', 'bsc') !== $this->normalizeAddress($toWallet, 'bsc')) {
                return ['status' => 'mismatch', 'reason' => 'آدررس گیرنده مطابقت ندارد'];
            }

            // Check amount using integer raw comparisons (H-02, M-05)
            $decimals = (int)($tx['tokenDecimal'] ?? 18);
            $amountRaw = $tx['value'] ?? '0';

            // Convert expected amount to raw token units using BCMath to avoid float issues
            $tokenUnit = bcpow('10', (string)$decimals, 0);
            $expectedRaw = bcmul((string)$expectedAmount, $tokenUnit, 0);
            $toleranceRaw = bcmul('0.01', $tokenUnit, 0);

            $diff = bcsub((string)$amountRaw, (string)$expectedRaw, 0);
            if (str_starts_with($diff, '-')) {
                $diff = substr($diff, 1);
            }

            if (bccomp($diff, $toleranceRaw, 0) === 1) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $tx];

        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.bsc.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش BSC'];
        }
    }

    /**
     * Verify TON transaction (M-03)
     */
    /** @return array<string, mixed> */
    private function verifyTonTransaction(string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        try {
            $apiKeyValue = $this->appSettings->get('toncenter_api_key', '');
            $apiKey = is_string($apiKeyValue) ? $apiKeyValue : '';
            $query = '?address=' . rawurlencode($toWallet) . '&limit=20&archival=true';
            if ($apiKey) {
                $query .= '&api_key=' . rawurlencode($apiKey);
            }
            $urls = [
                rtrim($this->providerUrl('toncenter_url', 'https://toncenter.com/api/v2/getTransactions'), '?') . $query,
                rtrim($this->providerUrl('toncenter_fallback_url', 'https://testnet.toncenter.com/api/v2/getTransactions'), '?') . $query,
            ];
            $response = $this->executeWithRetry($urls);
            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به Toncenter API'];
            }

            $data = json_decode((string)$response, true);
            if (!is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['result']) || !is_array($data['result'])) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از API TON'];
            }

            $foundTx = null;
            foreach ($data['result'] as $tx) {
                if (!is_array($tx) || !isset($tx['transaction_id']) || !is_array($tx['transaction_id'])) {
                    continue;
                }
                $hash = $tx['transaction_id']['hash'] ?? '';
                if (is_string($hash) && (strtolower($hash) === strtolower($txHash) || base64_encode(hex2bin($hash) ?: '') === $txHash)) {
                    $foundTx = $tx;
                    break;
                }
            }

            if (!$foundTx) {
                return ['status' => 'pending', 'reason' => 'تراکنش یافت نشد یا هنوز تأیید نشده است'];
            }

            $inMsg = $foundTx['in_msg'] ?? null;
            if (!is_array($inMsg)) {
                return ['status' => 'error', 'reason' => 'اطلاعات ورودی تراکنش TON ناقص است'];
            }
            if (trim($fromWallet) !== '') {
                $chainFrom = $inMsg['source'] ?? '';
                if (!is_string($chainFrom) || $chainFrom === '') {
                    return ['status' => 'error', 'reason' => 'آدرس مبدا برای تطبیق در پاسخ TON موجود نیست'];
                }
                if (strtolower($chainFrom) !== strtolower($fromWallet)) {
                    return ['status' => 'mismatch', 'reason' => 'آدرس مبدا تراکنش با آدرس ثبت‌شده مطابقت ندارد'];
                }
            }
            $value = $inMsg['value'] ?? '0';
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                return ['status' => 'error', 'reason' => 'مبلغ تراکنش TON نامعتبر است'];
            }
            
            $expectedRaw = \Core\ValueObjects\Money::fromString((string)((string)$expectedAmount))->multiply((string)('1000000'))->getAmount();
            $toleranceRaw = \Core\ValueObjects\Money::fromString((string)('0.01'))->multiply((string)('1000000'))->getAmount();
            
            $diff = \Core\ValueObjects\Money::fromString((string)($value))->subtract(\Core\ValueObjects\Money::fromString((string)($expectedRaw)))->getAmount();
            if (str_starts_with($diff, '-')) {
                $diff = substr($diff, 1);
            }
            if (\Core\ValueObjects\Money::fromString((string)($diff))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($toleranceRaw)))) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $foundTx];
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.ton.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش TON'];
        }
    }

    /**
     * Verify Solana transaction (M-03)
     */
    /** @return array<string, mixed> */
    private function verifySolanaTransaction(string $txHash, string $fromWallet, string $toWallet, string $expectedAmount): array
    {
        try {
            $rpcValue = $this->appSettings->get('solana_rpc_url', 'https://api.mainnet-beta.solana.com');
            $rpcUrl = is_string($rpcValue) ? $rpcValue : 'https://api.mainnet-beta.solana.com';
            if (!$this->isExternalUrlSafe($rpcUrl)) {
                $this->logger->warning('crypto.solana.ssrf_blocked', ['host' => parse_url($rpcUrl, PHP_URL_HOST)]);
                return ['status' => 'error', 'reason' => 'آدرس RPC سولانا از نظر امنیتی مجاز نیست'];
            }
            $data = $this->executeJsonRpc($rpcUrl, 'getTransaction', [
                $txHash,
                ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0],
            ]);
            if (!is_array($data)) {
                return ['status' => 'error', 'reason' => 'پاسخ RPC سولانا نامعتبر یا سرویس غیرقابل دسترس است'];
            }
            $result = $data['result'] ?? null;
            if ($result === null) {
                return ['status' => 'pending', 'reason' => 'تراکنش در شبکه سولانا یافت نشد'];
            }
            if (!is_array($result) || !isset($result['meta']) || !is_array($result['meta'])) {
                return ['status' => 'error', 'reason' => 'اطلاعات تراکنش سولانا ناقص است'];
            }

            $meta = $result['meta'];
            if (isset($meta['err']) && $meta['err'] !== null) {
                return ['status' => 'mismatch', 'reason' => 'تراکنش ناموفق در شبکه سولانا'];
            }

            if (trim($fromWallet) !== '') {
                $message = $result['transaction']['message'] ?? null;
                $accountKeys = is_array($message) ? ($message['accountKeys'] ?? null) : null;
                $chainFrom = '';
                if (is_array($accountKeys)) {
                    foreach ($accountKeys as $account) {
                        if (is_array($account) && ($account['signer'] ?? false) === true && isset($account['pubkey']) && is_string($account['pubkey'])) {
                            $chainFrom = $account['pubkey'];
                            break;
                        }
                    }
                }
                if ($chainFrom === '') {
                    return ['status' => 'error', 'reason' => 'آدرس امضاکننده برای تطبیق در پاسخ سولانا موجود نیست'];
                }
                if ($chainFrom !== $fromWallet) {
                    // Solana swaps and multisig transfers may use a signer that
                    // differs from the token-account owner. This is not enough
                    // evidence for an automatic rejection.
                    return ['status' => 'error', 'reason' => 'امضاکننده سولانا با آدرس ثبت‌شده متفاوت است؛ بررسی دستی لازم است'];
                }
            }

            $postBalances = $meta['postTokenBalances'] ?? [];
            $preBalances = $meta['preTokenBalances'] ?? [];
            if (!is_array($postBalances) || !is_array($preBalances)) {
                return ['status' => 'error', 'reason' => 'اطلاعات موجودی توکن سولانا ناقص است'];
            }

            $usdtMint = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';
            $receivedRaw = '0';
            $decimals = 6;
            foreach ($postBalances as $post) {
                if (!is_array($post) || ($post['mint'] ?? '') !== $usdtMint || strtolower((string)($post['owner'] ?? '')) !== strtolower($toWallet)) continue;
                $postInfo = $post['uiTokenAmount'] ?? null;
                $postRaw = is_array($postInfo) ? ($postInfo['amount'] ?? null) : null;
                $postDecimals = is_array($postInfo) ? ($postInfo['decimals'] ?? 6) : 6;
                if (!(is_string($postRaw) && ctype_digit($postRaw)) || (!is_int($postDecimals) && !(is_string($postDecimals) && ctype_digit($postDecimals)))) {
                    return ['status' => 'error', 'reason' => 'موجودی خام USDT در پاسخ سولانا نامعتبر است'];
                }
                $decimals = (int)$postDecimals;
                $preRaw = '0';
                foreach ($preBalances as $pre) {
                    if (is_array($pre) && ($pre['accountIndex'] ?? null) === ($post['accountIndex'] ?? null) && ($pre['mint'] ?? '') === $usdtMint) {
                        $info = $pre['uiTokenAmount'] ?? null;
                        $candidate = is_array($info) ? ($info['amount'] ?? '0') : '0';
                        if (is_string($candidate) && ctype_digit($candidate)) $preRaw = $candidate;
                        break;
                    }
                }
                $delta = bcsub($postRaw, $preRaw, 0);
                if (!str_starts_with($delta, '-') && bccomp($delta, '0', 0) === 1) $receivedRaw = bcadd($receivedRaw, $delta, 0);
            }

            if (bccomp($receivedRaw, '0', 0) !== 1) {
                return ['status' => 'mismatch', 'reason' => 'افزایش موجودی USDT برای آدرس گیرنده یافت نشد'];
            }

            $expectedRaw = bcmul((string)$expectedAmount, bcpow('10', (string)$decimals, 0), 0);
            $toleranceRaw = bcmul('0.01', bcpow('10', (string)$decimals, 0), 0);
            $diff = bcsub($receivedRaw, $expectedRaw, 0);
            if (str_starts_with($diff, '-')) $diff = substr($diff, 1);
            if (bccomp($diff, $toleranceRaw, 0) === 1) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش سولانا مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $result];
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.solana.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش Solana'];
        }
    }
}
