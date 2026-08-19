<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\VerificationService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$verification = $c->make(VerificationService::class);

function vrow($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }
function make_user(Database $db, string $suffix): int {
    return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["inf_verify_{$suffix}", "inf_verify_{$suffix}@example.test", "Inf Verify {$suffix}", 'active', 'user', 'verified']);
}
function make_profile(Database $db, int $userId, string $username): int {
    return (int)$db->insert("INSERT INTO influencer_profiles (user_id,username,platform,follower_count,followers_count,status,is_active,story_price_24h,currency,created_at,updated_at) VALUES (?,?,?,?,?,'pending',1,100000,'irt',NOW(),NOW())", [$userId, $username, 'instagram', 12000, 12000]);
}

try {
    $db->query("DELETE FROM influencer_verifications WHERE profile_id IN (SELECT id FROM influencer_profiles WHERE username LIKE 'inf_verify_%')");
    $db->query("DELETE FROM influencer_profiles WHERE username LIKE 'inf_verify_%'");
    $db->query("DELETE FROM users WHERE email LIKE 'inf_verify_%@example.test'");

    $userAuto = make_user($db, 'auto_' . time());
    $profileAuto = make_profile($db, $userAuto, 'inf_verify_auto_' . time());
    $codeAuto = $verification->generateVerificationCode($profileAuto);
    if (empty($codeAuto['ok'])) throw new RuntimeException('code auto failed');

    $auto = $verification->submitVerificationProof(
        $profileAuto,
        $userAuto,
        'https://www.instagram.com/p/INFVERIFYAUTO/',
        'uploads/influencer-verification/auto-proof.png',
        ['visible_code' => $codeAuto['code'], 'source' => 'db_test']
    );
    $profileAutoRow = $db->fetch("SELECT id,status,verified_by,verification_post_url FROM influencer_profiles WHERE id=?", [$profileAuto]);
    $verificationAutoRow = $db->fetch("SELECT id,status,admin_id,proof_url,proof_data,approved_at,submitted_at FROM influencer_verifications WHERE profile_id=? ORDER BY id DESC LIMIT 1", [$profileAuto]);

    $userFallback = make_user($db, 'fallback_' . time());
    $profileFallback = make_profile($db, $userFallback, 'inf_verify_fallback_' . time());
    $codeFallback = $verification->generateVerificationCode($profileFallback);
    if (empty($codeFallback['ok'])) throw new RuntimeException('code fallback failed');

    $fallback = $verification->submitVerificationProof(
        $profileFallback,
        $userFallback,
        'https://www.instagram.com/p/INFVERIFYFALLBACK/',
        'uploads/influencer-verification/fallback-proof.png',
        ['visible_code' => 'WRONGCODE', 'source' => 'db_test']
    );
    $profileFallbackRow = $db->fetch("SELECT id,status,verified_by,verification_post_url FROM influencer_profiles WHERE id=?", [$profileFallback]);
    $verificationFallbackRow = $db->fetch("SELECT id,status,admin_id,proof_url,proof_data,approved_at,submitted_at FROM influencer_verifications WHERE profile_id=? ORDER BY id DESC LIMIT 1", [$profileFallback]);

    $ok = !empty($auto['ok'])
        && !empty($auto['auto_verified'])
        && ($profileAutoRow->status ?? '') === 'verified'
        && ($verificationAutoRow->status ?? '') === 'approved'
        && !empty($verificationAutoRow->approved_at)
        && !empty($fallback['ok'])
        && empty($fallback['auto_verified'])
        && ($profileFallbackRow->status ?? '') === 'pending_admin_review'
        && ($verificationFallbackRow->status ?? '') === 'submitted'
        && empty($verificationFallbackRow->approved_at);

    echo json_encode([
        'ok' => $ok,
        'auto' => $auto,
        'profile_auto' => vrow($profileAutoRow),
        'verification_auto' => vrow($verificationAutoRow),
        'fallback' => $fallback,
        'profile_fallback' => vrow($profileFallbackRow),
        'verification_fallback' => vrow($verificationFallbackRow),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
