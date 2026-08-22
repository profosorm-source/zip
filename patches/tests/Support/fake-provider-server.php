<?php

declare(strict_types=1);

/**
 * fake-provider-server.php — سرور HTTP جعلی برای سوئیت Contract
 *
 * این فایل، پیاده‌سازیِ گمشده‌ای است که ExternalProviderHttpContractTest به آن
 * وابسته بود ولی هرگز در مخزن commit نشده بود. بدون آن، کل سوئیت contract
 * غیرقابل بازتولید بود.
 *
 * ── اجرا ─────────────────────────────────────────────────────────────
 *   php -S 0.0.0.0:8092 tests/Support/fake-provider-server.php
 * یا از طریق اسکریپت راه‌انداز:
 *   tests/Support/run-contract-suite.sh
 * ─────────────────────────────────────────────────────────────────────
 *
 * ── قرارداد مسیر ─────────────────────────────────────────────────────
 *   /{scenario}/{provider}/{operation...}
 *
 *   scenario ها:
 *     success      — پاسخ معتبر ۲۰۰
 *     retry        — دو بار 503 سپس ۲۰۰ (سیاست retry را می‌سنجد)
 *     permanent    — 400 دائمی (نباید retry شود)
 *     malformed    — ۲۰۰ با بدنهٔ غیر-JSON
 *     schema       — ۲۰۰ با JSON معتبر ولی ناقص از نظر schema
 *     mismatch     — ۲۰۰ با مبلغ/مقدار ناهمخوان
 *     unregistered — پاسخ UNREGISTERED مخصوص FCM
 * ─────────────────────────────────────────────────────────────────────
 *
 * ── قرارداد state ────────────────────────────────────────────────────
 *   دایرکتوری از PROVIDER_FAKE_STATE_DIR خوانده می‌شود (پیش‌فرض:
 *   sys_get_temp_dir()/chortke-provider-state) و این فایل‌ها نوشته می‌شود:
 *
 *     {scenario}_{provider}_{opSlug}.count      — شمارندهٔ تعداد درخواست
 *     {scenario}_{provider}_{opSlug}.last.json  — آخرین درخواست ضبط‌شده
 *
 *   شکل .last.json دقیقاً مطابق ProviderRequest در تست است:
 *     {method, path, query, headers, body, post, files}
 * ─────────────────────────────────────────────────────────────────────
 */

// ───────────────────────── ابزار state ─────────────────────────

function fake_state_dir(): string
{
    $configured = getenv('PROVIDER_FAKE_STATE_DIR');
    $dir = is_string($configured) && $configured !== ''
        ? $configured
        : sys_get_temp_dir() . '/chortke-provider-state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/** اسلاگ امن برای نام فایل از روی بقیهٔ مسیر عملیات. */
function fake_op_slug(array $segments): string
{
    $slug = implode('_', $segments);
    $slug = preg_replace('/[^A-Za-z0-9_]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    return $slug === '' ? 'root' : substr($slug, 0, 100);
}

/** درخواست جاری را ضبط می‌کند و شمارندهٔ تجمعی را برمی‌گرداند. */
function fake_record(string $scenario, string $provider, array $opSegments): int
{
    $dir  = fake_state_dir();
    $base = $dir . '/' . $scenario . '_' . $provider . '_' . fake_op_slug($opSegments);

    $count = 0;
    if (is_file($base . '.count')) {
        $count = (int) file_get_contents($base . '.count');
    }
    $count++;
    file_put_contents($base . '.count', (string) $count, LOCK_EX);

    // getallheaders() در سرور توکار PHP، نامِ هدر را دقیقاً با همان حروفی که
    // کلاینت فرستاده برمی‌گرداند. این برای تست‌هایی که روی «X-API-KEY» یا
    // «ApiKey» ادعا می‌کنند حیاتی است؛ بازسازی از $_SERVER['HTTP_*'] حروف را
    // نابود می‌کند (X-API-KEY → X-Api-Key) و ادعا را به‌غلط رد می‌کند.
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        // فقط به‌عنوان جایگزین (fallback) اضافه می‌شود تا نام اصلی بازنویسی نشود.
        if (!isset($headers[$name])) {
            $headers[$name] = (string) $value;
        }
    }
    // این دو هرگز پیشوند HTTP_ نمی‌گیرند.
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
    }

    $files = [];
    foreach ($_FILES as $field => $info) {
        $files[$field] = [
            'name' => (string) ($info['name'] ?? ''),
            'size' => (int) ($info['size'] ?? 0),
        ];
    }

    $body = (string) file_get_contents('php://input');
    // در multipart، php://input خالی است؛ اندازهٔ واقعی از $_FILES می‌آید.
    file_put_contents($base . '.last.json', json_encode([
        'method'  => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'path'    => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        'query'   => $_GET,
        'headers' => $headers,
        'body'    => $body,
        'post'    => $_POST,
        'files'   => $files,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $count;
}

function fake_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fake_raw(string $body, int $status = 200, string $type = 'text/html; charset=utf-8'): void
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo $body;
}

/** بدنهٔ درخواست را به آرایه تبدیل می‌کند (JSON یا form). */
function fake_input(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($raw, $form);
        if ($form !== []) {
            return $form;
        }
    }
    return $_POST;
}

// ───────────────────────── مسیریابی ─────────────────────────

$path     = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));

if (count($segments) < 2) {
    fake_json(['error' => 'usage: /{scenario}/{provider}/{operation}'], 404);
    return true;
}

$scenario = array_shift($segments);
$provider = array_shift($segments);
$op       = $segments;                 // بقیهٔ مسیر = عملیات
$opJoined = implode('/', $op);

$attempt = fake_record($scenario, $provider, $op);

// ── رفتار عمومی سناریوها (پیش از منطق اختصاصی هر provider) ──

// retry: دو تلاش اول 503، سومی موفق.
if ($scenario === 'retry' && $attempt < 3) {
    fake_json(['error' => 'service unavailable', 'attempt' => $attempt], 503);
    return true;
}

// permanent: همیشه 400 — نباید هرگز retry شود.
if ($scenario === 'permanent') {
    // استثنا: crypto با fallback کار می‌کند و تست انتظار ۲ درخواست دارد.
    fake_json(['status' => 'error', 'error' => 'permanent failure'], 400);
    return true;
}

// malformed: ۲۰۰ ولی بدنهٔ غیرقابل parse.
if ($scenario === 'malformed') {
    fake_raw('<html><body>not json at all</body></html>');
    return true;
}

// ───────────── پاسخ‌های موفق/اختصاصی به تفکیک provider ─────────────

switch ($provider) {

    // ─────────────── درگاه‌های پرداخت ───────────────

    case 'idpay':
        // POST /{scenario}/idpay/v1.1[/payment/verify]
        if (str_contains($opJoined, 'verify')) {
            fake_json(['status' => 100, 'track_id' => 'FAKE-IDPAY-TRACK-1', 'amount' => 125000]);
            return true;
        }
        // آداپتور واقعی برای ایجاد پرداخت، دقیقاً HTTP 201 می‌خواهد.
        fake_json(['id' => 'FAKE-IDPAY-AUTHORITY-000000000001', 'link' => 'https://idpay.example.test/pay/1'], 201);
        return true;

    case 'zarinpal':
        if ($scenario === 'schema') {
            fake_json(['data' => []]);   // JSON معتبر، ولی بدون authority
            return true;
        }
        if (str_contains($opJoined, 'verify')) {
            $amount = $scenario === 'mismatch' ? 99999 : 12500;
            fake_json(['data' => ['code' => 100, 'ref_id' => 'ZP-FAKE-REF-100', 'amount' => $amount], 'errors' => []]);
            return true;
        }
        fake_json(['data' => [
            'code'      => 100,
            'authority' => 'ZP-FAKE-AUTHORITY-000000000000000001',
            'fee'       => 0,
        ], 'errors' => []]);
        return true;

    case 'nextpay':
        if (str_contains($opJoined, 'verify')) {
            if ($scenario === 'schema') {
                fake_json(['code' => 0]);           // بدون amount/ref → ناقص
                return true;
            }
            $amount = $scenario === 'mismatch' ? 99999 : 12500;
            fake_json(['code' => 0, 'amount' => $amount, 'Shaparak_Ref_Id' => 'NP-FAKE-REF']);
            return true;
        }
        if ($scenario === 'schema') {
            fake_json(['code' => -1]);              // بدون trans_id
            return true;
        }
        // در NextPay، کد موفقیتِ «ایجاد تراکنش» برابر -1 است (نه 0).
        fake_json(['code' => -1, 'trans_id' => 'NP-FAKE-TRANS-000000000001']);
        return true;

    case 'dgpay':
        if (str_contains($opJoined, 'verify')) {
            $amount = $scenario === 'mismatch' ? 999990 : 125000;
            fake_json(['status' => 'success', 'amount' => $amount, 'ref_id' => 'DG-FAKE-REF']);
            return true;
        }
        if ($scenario === 'schema') {
            fake_json(['status' => 'success']);     // بدون token
            return true;
        }
        fake_json(['status' => 'success', 'token' => 'DG-FAKE-TOKEN-000000000001']);
        return true;

    // ─────────────── استعلام بانکی ───────────────

    case 'jibit':
        // ابتدا احراز هویت، سپس استعلام شبا
        if (str_contains($opJoined, 'tokens')) {
            fake_json(['accessToken' => 'fake-jibit-token', 'refreshToken' => 'fake-jibit-refresh']);
            return true;
        }
        fake_json([
            'name'       => 'Test',
            'familyName' => 'Owner',
            'bank'       => 'Runtime Bank',
            'status'     => 'ACTIVE',
        ]);
        return true;

    case 'vandar':
        if ($scenario === 'schema') {
            fake_json(['status' => 1]);             // بدون اطلاعات مالک
            return true;
        }
        fake_json([
            'status' => 1,
            'data'   => [
                'account_owners' => [['firstName' => 'Test', 'lastName' => 'Owner']],
                'bank_name'      => 'Runtime Bank',
            ],
        ]);
        return true;

    // ─────────────── هویت / احراز ───────────────

    case 'google':
        // JWKS از fixture ای که خود تست نوشته خوانده می‌شود.
        $fixture = fake_state_dir() . '/google_jwks_fixture.json';
        if (is_file($fixture)) {
            header('Content-Type: application/json; charset=utf-8');
            echo (string) file_get_contents($fixture);
            return true;
        }
        fake_json(['keys' => []]);
        return true;

    case 'recaptcha':
        fake_json(['success' => true, 'score' => 0.9, 'action' => 'submit',
                   'challenge_ts' => gmdate('c'), 'hostname' => 'contract.example.test']);
        return true;

    case 'deepface':
        if ($scenario === 'schema') {
            fake_json(['success' => true]);         // بدون confidence
            return true;
        }
        fake_json([
            'verified'   => true,
            'confidence' => 0.987,
            'has_face'   => true,
            'error_code' => 0,
        ]);
        return true;

    // ─────────────── پیامک ───────────────

    case 'kavenegar':
        fake_json(['return' => ['status' => 200, 'message' => 'ok'],
                   'entries' => [['messageid' => 1001, 'status' => 5, 'statustext' => 'ارسال شد']]]);
        return true;

    case 'melipayamak':
        fake_json(['Value' => '1001', 'RetStatus' => 1, 'StrRetStatus' => 'Ok']);
        return true;

    case 'idehpayam':
        fake_json(['status' => 'success', 'code' => 200, 'messageid' => 1001]);
        return true;

    // ─────────────── اعلان‌ها ───────────────

    case 'fcm':
        if (str_contains($opJoined, 'oauth')) {
            fake_json(['access_token' => 'fake-fcm-oauth-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
            return true;
        }
        if ($scenario === 'unregistered') {
            fake_json(['error' => [
                'code'    => 404,
                'status'  => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
                'details' => [[
                    '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ]], 404);
            return true;
        }
        fake_json(['name' => 'projects/runtime-project/messages/fake-message-0001']);
        return true;

    case 'telegram':
        fake_json(['ok' => true, 'result' => ['message_id' => 4242]]);
        return true;

    case 'webhook':
        fake_json(['ok' => true, 'received' => true]);
        return true;

    // ─────────────── رمزارز ───────────────

    case 'crypto':
        $first = $op[0] ?? '';

        if ($scenario === 'schema') {
            fake_json(['result' => []]);            // بدون فیلدهای تراکنش
            return true;
        }
        $mismatch = $scenario === 'mismatch';

        // TRON — tronscan transaction
        if (str_starts_with($first, 'tron_tx')) {
            // پارسر واقعی TronScan روی کلید «contractData» کار می‌کند (نه
            // tokenTransferInfo)؛ نبودِ این کلید مسیر را به شاخهٔ
            // «پاسخ نامعتبر از TronScan» می‌برد.
            fake_json([
                'confirmed'     => true,
                'confirmations' => 42,
                'contractRet'   => 'SUCCESS',
                'block'         => 0,
                'contractData'  => [
                    'owner_address'    => 'TFromRuntimeWallet',
                    'from_address'     => 'TFromRuntimeWallet',
                    'to_address'       => $mismatch ? 'TOtherWallet' : 'TToRuntimeWallet',
                    'amount'           => $mismatch ? '1' : '12500000',
                    'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                ],
            ]);
            return true;
        }
        if (str_starts_with($first, 'tron_events')) {
            fake_json(['data' => [[
                'result' => [
                    'from'  => 'TFromRuntimeWallet',
                    'to'    => $mismatch ? 'TOtherWallet' : 'TToRuntimeWallet',
                    'value' => $mismatch ? '1' : '12500000',
                ],
                'block_timestamp' => (int) (microtime(true) * 1000),
            ]]]);
            return true;
        }
        if (str_starts_with($first, 'tron_status') || str_starts_with($first, 'tron_block')) {
            fake_json(['blockID' => 'fake-block', 'block_header' => ['raw_data' => ['number' => 60000000]]]);
            return true;
        }

        // BSC — bscscan و fallback و RPC
        if (str_starts_with($first, 'bsc')) {
            // bsc_rpc یک نقطهٔ JSON-RPC است، نه BscScan؛ پاسخِ receipt می‌خواهد.
            if (str_starts_with($first, 'bsc_rpc')) {
                fake_json(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'status'      => '0x1',
                    'blockNumber' => '0x2540be3f',
                    'from'        => '0x1111111111111111111111111111111111111111',
                    'to'          => '0x55d398326f99059ff775485246999027b3197955',
                    'logs'        => [[
                        'address' => '0x55d398326f99059ff775485246999027b3197955',
                        'topics'  => [
                            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                            '0x000000000000000000000000' . str_repeat('1', 40),
                            '0x000000000000000000000000' . str_repeat('2', 40),
                        ],
                        'data'    => '0x' . str_pad(dechex((int) 12500000000000000000), 64, '0', STR_PAD_LEFT),
                    ]],
                ]]);
                return true;
            }
            // BscScan خروجی «result» را به‌صورت فهرست برمی‌گرداند؛ آداپتور
            // صریحاً $data['result'][0] را می‌خواند. آرایهٔ انجمنیِ ساده باعث
            // می‌شود به مسیر fallback مبتنی بر RPC بیفتد.
            fake_json([
                'status'  => '1',
                'message' => 'OK',
                'result'  => [[
                    'blockNumber'       => '625000000',
                    'confirmations'     => '42',
                    'from'              => '0x1111111111111111111111111111111111111111',
                    'to'                => $mismatch
                        ? '0x9999999999999999999999999999999999999999'
                        : '0x2222222222222222222222222222222222222222',
                    'contractAddress'   => '0x55d398326f99059ff775485246999027b3197955',
                    'tokenDecimal'      => '18',
                    'value'             => $mismatch ? '1' : '12500000000000000000',
                    'isError'           => '0',
                    'txreceipt_status'  => '1',
                ]],
            ]);
            return true;
        }

        // TON
        if (str_starts_with($first, 'ton')) {
            fake_json(['ok' => true, 'result' => [[
                'transaction_id' => ['hash' => str_repeat('b', 64)],
                // آداپتور TON مبلغ را با ضریب 1e6 می‌سنجد (نه 1e9 نانوتونی)؛
                // 12.5 → 12500000.
                'in_msg' => [
                    'source'      => 'EQFromRuntimeWallet',
                    'destination' => $mismatch ? 'EQOtherWallet' : 'EQToRuntimeWallet',
                    'value'       => $mismatch ? '1' : '12500000',
                ],
                'utime' => time(),
            ]]]);
            return true;
        }

        // Solana JSON-RPC
        if (str_starts_with($first, 'solana')) {
            // پارسر سولانا دلتای موجودیِ توکن را می‌سنجد: به mint دقیق USDT،
            // «amount» خام (نه uiAmountString)، accountIndex منطبق بین
            // pre/post و accountKeys شیءگونه با pubkey+signer نیاز دارد.
            $solMint = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';
            fake_json(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                'slot' => 250000000,
                'meta' => [
                    'err'          => null,
                    'preBalances'  => [1000000000, 0],
                    'postBalances' => [987500000, 12500000],
                    'preTokenBalances'  => [[
                        'accountIndex'  => 1,
                        'mint'          => $solMint,
                        'owner'         => $mismatch ? 'SolOtherWallet' : 'SolToRuntimeWallet',
                        'uiTokenAmount' => ['amount' => '0', 'decimals' => 6, 'uiAmountString' => '0'],
                    ]],
                    'postTokenBalances' => [[
                        'accountIndex'  => 1,
                        'mint'          => $solMint,
                        'owner'         => $mismatch ? 'SolOtherWallet' : 'SolToRuntimeWallet',
                        'uiTokenAmount' => [
                            'amount'         => $mismatch ? '100000' : '12500000',
                            'decimals'       => 6,
                            'uiAmountString' => $mismatch ? '0.1' : '12.5',
                        ],
                    ]],
                ],
                'transaction' => ['message' => ['accountKeys' => [
                    ['pubkey' => 'SolFromRuntimeWallet', 'signer' => true,  'writable' => true],
                    ['pubkey' => 'SolToRuntimeWallet',   'signer' => false, 'writable' => true],
                ]]],
                'blockTime'   => time(),
            ]]);
            return true;
        }

        // explorer — عمداً HTML برمی‌گرداند: نباید هرگز «verified» تلقی شود.
        if (str_starts_with($first, 'explorer')) {
            fake_raw('<html><body><div class="tx">Success</div></body></html>');
            return true;
        }

        fake_json(['result' => null]);
        return true;
}

fake_json(['error' => 'unknown provider', 'provider' => $provider, 'scenario' => $scenario], 404);
return true;
