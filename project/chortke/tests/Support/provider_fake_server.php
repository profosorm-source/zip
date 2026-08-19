<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $v): bool => $v !== ''));
$scenario = $segments[0] ?? 'success';
$provider = $segments[1] ?? 'unknown';
$operation = implode('/', array_slice($segments, 2));
$stateDir = getenv('PROVIDER_FAKE_STATE_DIR') ?: sys_get_temp_dir() . '/chortke-provider-state';
@mkdir($stateDir, 0777, true);
$key = preg_replace('/[^a-z0-9_.-]/i', '_', $scenario . '_' . $provider . '_' . $operation);
$counterFile = rtrim($stateDir, '/') . '/' . $key . '.count';
$count = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
$count++;
file_put_contents($counterFile, (string) $count, LOCK_EX);

$body = file_get_contents('php://input') ?: '';
$record = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => $path,
    'query' => $_GET,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => $body,
    'post' => $_POST,
    'files' => array_map(static fn(array $f): array => ['name' => $f['name'] ?? '', 'size' => $f['size'] ?? 0], $_FILES),
];
file_put_contents(rtrim($stateDir, '/') . '/' . $key . '.last.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

header('Content-Type: application/json');
if ($scenario === 'malformed') {
    http_response_code(200);
    echo '{malformed-json';
    return;
}
if ($scenario === 'permanent') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    return;
}
if ($scenario === 'retry' && $count < 3) {
    http_response_code(503);
    echo json_encode(['error' => 'temporary_unavailable', 'attempt' => $count]);
    return;
}
if ($scenario === 'timeout') {
    usleep(2_500_000);
}

if ($provider === 'idpay') {
    if (str_ends_with($operation, 'payment/verify')) {
        http_response_code(200);
        echo json_encode(['status' => 100, 'track_id' => 'FAKE-IDPAY-REF', 'amount' => 125000]);
        return;
    }
    http_response_code(201);
    echo json_encode(['id' => 'FAKE-IDPAY-AUTHORITY-000000000001', 'link' => 'https://gateway.example.test/pay']);
    return;
}

if ($provider === 'zarinpal') {
    $payload = json_decode($body, true);
    $payload = is_array($payload) ? $payload : [];
    if (str_ends_with($operation, 'verify.json') || str_ends_with($operation, 'PaymentVerification.json')) {
        $amount = $scenario === 'mismatch' ? ((int)($payload['amount'] ?? 0) + 1) : (int)($payload['amount'] ?? 0);
        http_response_code(200);
        echo json_encode(['data'=>['code'=>100,'ref_id'=>'ZP-FAKE-REF-100','amount'=>$amount]]);
        return;
    }
    http_response_code(200);
    echo $scenario === 'schema'
        ? json_encode(['data'=>['code'=>100]])
        : json_encode(['data'=>['code'=>100,'authority'=>'ZP-FAKE-AUTHORITY-000000000000000001']]);
    return;
}

if ($provider === 'nextpay') {
    parse_str($body, $form);
    if (str_ends_with($operation, 'verify')) {
        $amount = $scenario === 'mismatch' ? ((int)($form['amount'] ?? 0) + 1) : (int)($form['amount'] ?? 0);
        http_response_code(200);
        echo $scenario === 'schema'
            ? json_encode(['code'=>0,'Shaparak_Ref_Id'=>'NP-FAKE-REF'])
            : json_encode(['code'=>0,'Shaparak_Ref_Id'=>'NP-FAKE-REF','amount'=>$amount]);
        return;
    }
    http_response_code(200);
    echo $scenario === 'schema'
        ? json_encode(['code'=>-1])
        : json_encode(['code'=>-1,'trans_id'=>'NP-FAKE-TRANS-000000000001']);
    return;
}

if ($provider === 'dgpay') {
    $payload = json_decode($body, true);
    $payload = is_array($payload) ? $payload : [];
    if (str_ends_with($operation, 'verify')) {
        $amount = $scenario === 'mismatch' ? ((int)($payload['amount'] ?? 0) + 10) : (int)($payload['amount'] ?? 0);
        http_response_code(200);
        echo $scenario === 'schema'
            ? json_encode(['status'=>'success','ref_id'=>'DG-FAKE-REF'])
            : json_encode(['status'=>'success','ref_id'=>'DG-FAKE-REF','amount'=>$amount]);
        return;
    }
    http_response_code(200);
    echo $scenario === 'schema'
        ? json_encode(['status'=>'success'])
        : json_encode(['status'=>'success','token'=>'DG-FAKE-TOKEN-000000000001']);
    return;
}

if ($provider === 'vandar') {
    http_response_code(200);
    echo $scenario === 'schema'
        ? json_encode(['bank_name'=>'Runtime Bank','account_owners'=>[]])
        : json_encode(['iban'=>'IR820540102680020817909002','bank_name'=>'Runtime Bank','account_owners'=>[['firstName'=>'Test','lastName'=>'Owner']]]);
    return;
}

if ($provider === 'crypto') {
    $request = json_decode($body, true);
    $request = is_array($request) ? $request : [];
    if (str_contains($operation, 'tron_status')) {
        http_response_code(200); echo json_encode(['database'=>['block'=>130]]); return;
    }
    if (str_contains($operation, 'tron_tx')) {
        http_response_code(200); echo json_encode([
            'confirmed'=>true,'contractRet'=>'SUCCESS','block'=>100,
            'contractData'=>[
                'owner_address'=>'TFromRuntimeWallet','to_address'=>$scenario==='mismatch'?'TWrongWallet':'TToRuntimeWallet',
                'contract_address'=>'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t','amount'=>'12500000',
            ],
        ]); return;
    }
    if (str_contains($operation, 'explorer')) {
        header('Content-Type: text/html'); http_response_code(200); echo '<html><body>Runtime explorer page</body></html>'; return;
    }
    if (str_contains($operation, 'bsc')) {
        if ($scenario === 'schema') { http_response_code(200); echo json_encode(['status'=>'1','result'=>[]]); return; }
        http_response_code(200); echo json_encode(['status'=>'1','result'=>[array_merge([
            'blockNumber'=>'100','confirmations'=>'20','from'=>'0x1111111111111111111111111111111111111111',
            'to'=>'0x2222222222222222222222222222222222222222','contractAddress'=>'0x55d398326f99059ff775485246999027b3197955',
            'tokenDecimal'=>'6','value'=>'12500000',
        ], $scenario==='mismatch'?['to'=>'0x3333333333333333333333333333333333333333']:[])]]); return;
    }
    if (str_contains($operation, 'ton')) {
        http_response_code(200); echo json_encode(['ok'=>true,'result'=>[[
            'transaction_id'=>['hash'=>str_repeat('b',64)],
            'in_msg'=>['source'=>'EQFromRuntimeWallet','value'=>'12500000'],
        ]]]); return;
    }
    if (str_contains($operation, 'solana')) {
        http_response_code(200); echo json_encode(['jsonrpc'=>'2.0','id'=>1,'result'=>[
            'meta'=>[
                'err'=>null,
                'preTokenBalances'=>[['accountIndex'=>1,'mint'=>'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB','uiTokenAmount'=>['amount'=>'1000000','decimals'=>6]]],
                'postTokenBalances'=>[['accountIndex'=>1,'mint'=>'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB','owner'=>$scenario==='mismatch'?'WrongSolWallet':'SolToRuntimeWallet','uiTokenAmount'=>['amount'=>'13500000','decimals'=>6]]],
            ],
            'transaction'=>['message'=>['accountKeys'=>[['pubkey'=>'SolFromRuntimeWallet','signer'=>true]]]],
        ]]); return;
    }
}

if ($provider === 'recaptcha') {
    http_response_code(200); echo json_encode(['success'=>true,'score'=>0.9,'action'=>'login']); return;
}

if ($provider === 'google') {
    $fixture = rtrim($stateDir, '/') . '/google_jwks_fixture.json';
    if (is_file($fixture)) {
        http_response_code(200); echo (string)file_get_contents($fixture); return;
    }
    http_response_code(200); echo json_encode(['keys'=>[]]); return;
}

if ($provider === 'jibit') {
    if (str_ends_with($operation, 'tokens/generate')) {
        http_response_code(200);
        echo json_encode(['accessToken' => 'fake-jibit-token']);
        return;
    }
    if (str_contains($operation, 'services/iban')) {
        http_response_code(200);
        echo json_encode(['name' => 'Test', 'familyName' => 'Owner', 'bank' => 'Runtime Bank']);
        return;
    }
}

if ($provider === 'kavenegar') {
    http_response_code(200);
    echo json_encode(['return' => ['status' => 200, 'message' => 'accepted'], 'entries' => [['messageid' => 123]]]);
    return;
}

if ($provider === 'melipayamak') {
    http_response_code(200);
    echo json_encode(['RetStatus'=>1,'Value'=>'MP-FAKE-MESSAGE-1']);
    return;
}

if ($provider === 'idehpayam') {
    http_response_code(200);
    echo json_encode(['status'=>'success','messageId'=>'IP-FAKE-MESSAGE-1']);
    return;
}

if ($provider === 'fcm') {
    if (str_ends_with($operation, 'oauth')) {
        http_response_code(200);
        echo json_encode(['access_token'=>'fake-fcm-oauth-token','expires_in'=>3600,'token_type'=>'Bearer']);
        return;
    }
    if ($scenario === 'unregistered') {
        http_response_code(404);
        echo json_encode(['error'=>['status'=>'NOT_FOUND','details'=>[['errorCode'=>'UNREGISTERED']]]]);
        return;
    }
    http_response_code(200);
    echo json_encode(['name'=>'projects/runtime-project/messages/fake-message-id']);
    return;
}

if ($provider === 'telegram') {
    http_response_code(200); echo json_encode(['ok'=>true,'result'=>['message_id'=>123]]); return;
}

if ($provider === 'webhook') {
    http_response_code(202); echo json_encode(['accepted'=>true]); return;
}

if ($provider === 'deepface') {
    http_response_code(200);
    echo json_encode(['verified' => true, 'confidence' => 0.987, 'has_face' => true, 'notes' => 'runtime contract accepted']);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'unknown_fake_route', 'path' => $path]);
