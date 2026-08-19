<?php

namespace App\Controllers\User;

use App\Models\CryptoDeposit;
use App\Controllers\User\BaseUserController;

class CryptoDepositController extends BaseUserController
{
    private CryptoDeposit $depositModel;
    private \App\Services\CryptoDeposit\CryptoDepositService $depositService;

    public function __construct(
        \App\Models\CryptoDeposit $depositModel,
        \App\Services\CryptoDeposit\CryptoDepositService $depositService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->depositModel = $depositModel;
        $this->depositService = $depositService;
    }

    /**
     * لیست درخواست‌های واریز کریپتو کاربر
     */
    public function index(): void
    {
        $userId = (int)$this->userId();

        try {
            $deposits = $this->depositModel->getUserDeposits($userId, null, 50, 0);
            $this->view('user/crypto-deposit/index', [
                'deposits' => $deposits,
                'pageTitle' => 'درخواست‌های واریز رمزارزی'
            ]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('crypto_deposit.user_index.failed', [
                'channel' => 'crypto',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->session->setFlash('error', 'خطا در دریافت لیست واریزهای رمزارزی');
            redirect('/wallet');
        }
    }

    /**
     * فرم واریز کریپتو
     */
    public function create(): void
    {
        $userId = (int)$this->userId();

        try {
            // بررسی درخواست در انتظار
            if ($this->depositModel->hasPendingDeposit($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست واریز در انتظار بررسی دارید');
                redirect('/wallet');
            }

            $definitions = [
                'bnb20' => ['title' => 'BNB Smart Chain (BEP20)', 'setting' => 'site_usdt_bnb20_address', 'default_enabled' => true],
                'trc20' => ['title' => 'TRON Network (TRC20)', 'setting' => 'site_usdt_trc20_address', 'default_enabled' => true],
                'ton' => ['title' => 'TON Network', 'setting' => 'site_usdt_ton_address', 'default_enabled' => false],
                'sol' => ['title' => 'Solana Network', 'setting' => 'site_usdt_sol_address', 'default_enabled' => false],
            ];
            $cryptoNetworks = [];
            foreach ($definitions as $key => $definition) {
                $rawEnabled = setting('crypto_network_' . $key . '_enabled', $definition['default_enabled'] ? 'true' : 'false');
                $enabled = is_bool($rawEnabled) ? $rawEnabled : filter_var($rawEnabled, FILTER_VALIDATE_BOOLEAN);
                $address = str_value(setting($definition['setting'], ''));
                if ($enabled && $address !== '') {
                    $cryptoNetworks[$key] = ['title' => $definition['title'], 'address' => $address];
                }
            }
            if (empty($cryptoNetworks)) {
                $this->session->setFlash('error', 'هیچ شبکه و آدرس کیف پول فعالی توسط مدیریت تنظیم نشده است');
                redirect('/wallet');
            }

            $minDeposit = float_value(setting('min_crypto_deposit_usdt', 1));

            $this->view('user/crypto-deposit/create', [
                'cryptoNetworks' => $cryptoNetworks,
                'minDeposit' => $minDeposit,
                'pageTitle' => 'واریز USDT'
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        }
    }

    /** Server-side intent: wallet, unique expected amount and expiry are never client supplied. */
    public function createIntent(): void
    {
        $rawData = $this->request->post();
        $data = is_array($rawData) ? $rawData : [];
        $network = strtoupper(trim(str_value($data['network'] ?? '')));
        $requestedAmount = is_numeric($data['requested_amount'] ?? null) ? (string)$data['requested_amount'] : '0';
        $result = $this->depositService->createIntent(
            (int)$this->userId(),
            $network,
            $requestedAmount,
            $this->request->ip(),
            $this->request->userAgent()
        );
        $this->response->json($result, !empty($result['success']) ? 201 : 422);
    }

    /**
     * ذخیره درخواست واریز کریپتو (POST handler)
     */
    public function store(): void
    {
        $userId = (int)$this->userId();
        $rawData = $this->request->post();
        $data = is_array($rawData) ? $rawData : [];
        $txHash  = trim(str_value($data['tx_hash'] ?? ''));
        $network = strtolower(trim(str_value($data['network'] ?? '')));
        $data['tx_hash'] = $txHash;
        $hashError = null;

        $allowedNetworks = ['bnb20', 'trc20', 'sol', 'ton'];
        if (!in_array($network, $allowedNetworks, true)) {
            $hashError = 'شبکه انتخاب‌شده معتبر نیست.';
        } elseif ($network === 'bnb20') {
            if (!preg_match('/^0x[a-f0-9]{64}$/i', $txHash)) {
                $hashError = 'هش تراکنش نامعتبر است (باید با 0x شروع شده و دارای ۶۴ کاراکتر هگزادسیمال بعد از آن باشد)';
            }
        } elseif ($network === 'trc20') {
            if (!preg_match('/^[a-f0-9]{64}$/i', $txHash)) {
                $hashError = 'هش تراکنش نامعتبر است (باید دقیقاً ۶۴ کاراکتر هگزادسیمال باشد)';
            }
        } elseif ($network === 'sol') {
            if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{88}$/', $txHash)) {
                $hashError = 'هش تراکنش Solana نامعتبر است (باید ۸۸ کاراکتر Base58 باشد)';
            }
        } elseif ($network === 'ton') {
            if (!preg_match('/^[a-f0-9]{64}$/i', $txHash) && !preg_match('/^[a-zA-Z0-9\/+]{43}=$/', $txHash)) {
                $hashError = 'هش تراکنش TON نامعتبر است (باید ۶۴ کاراکتر هگزادسیمال یا ۴۴ کاراکتر Base64 باشد)';
            }
        }

        if ($hashError !== null) {
            $this->session->setFlash('error', $hashError);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
        }

        // Use shared IdempotencyService to prevent duplicate API submissions
        $explicitKey = is_string($this->request->header('Idempotency-Key')) ? (string)$this->request->header('Idempotency-Key') : null;

        try {
            // پاس دادن کلید مستقیم به لایه خدمات (Service Layer) جهت اعمال متمرکز Idempotency
            // فقط فیلدهای مورد نیاز دامنه را به Service بده؛ فیلدهای فرم مثل CSRF نباید وارد Model/DB شوند.
            $intentId = isset($data['intent_id']) ? int_value($data['intent_id']) : 0;
            if ($intentId <= 0) {
                throw new \RuntimeException('شناسه درخواست واریز معتبر نیست');
            }
            $payload = [
                'intent_id' => $intentId,
                'tx_hash' => $txHash,
                'network' => strtoupper($network),
                // from_wallet is optional; the service never trusts it for amount/destination.
                'from_wallet' => trim(str_value($data['from_wallet'] ?? '')),
            ];
            $result = $this->depositService->createDeposit($userId, $payload, $explicitKey);

            if ($result['success'] ?? false) {
                $this->session->setFlash('success', $result['message']);
                redirect('/wallet');
            } else {
                throw new \RuntimeException(is_string($result['message'] ?? null) ? $result['message'] : 'خطا در ثبت درخواست');
            }

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
    $this->logger->error('crypto_deposit.index.failed', [
        'channel' => 'crypto',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/wallet');
        }
    }
}
