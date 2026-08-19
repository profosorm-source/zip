<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\PredictionService;
use App\Jobs\PredictionGameSettlementJob;

$c = Container::getInstance();
$db = $c->make(Database::class);
$service = $c->make(PredictionService::class);
$job = $c->make(PredictionGameSettlementJob::class);

function p2_user(Database $db, string $suffix): int {
    return (int)$db->insert(
        "INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())",
        ["pred_phase2_$suffix", "pred_phase2_$suffix@example.test", "Prediction Phase2 $suffix", 'active', 'user', 'verified']
    );
}
function p2_wallet(Database $db, int $userId, float $usdt): void {
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,?,0,0,NOW(),NOW())", [$userId, $usdt]);
}
function p2_game(Database $db, string $suffix, float $commission = 10.0): int {
    return (int)$db->insert(
        "INSERT INTO prediction_games (title,team_home,team_away,sport_type,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,status,created_by,created_at,updated_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),1,10000,?,'open',1,NOW(),NOW())",
        ["PRED-PHASE2: $suffix", 'A', 'B', 'football', $commission]
    );
}
function p2_wallet_row(Database $db, int $userId): object {
    return $db->fetch("SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=?", [$userId]);
}
function p2_arr($o): ?array { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }

try {
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%PRED-PHASE2%' OR metadata LIKE '%prediction_%' OR description LIKE '%پیش‌بینی%'");
    $db->query("DELETE FROM prediction_bets WHERE game_id IN (SELECT id FROM prediction_games WHERE title LIKE 'PRED-PHASE2:%')");
    $db->query("DELETE FROM prediction_games WHERE title LIKE 'PRED-PHASE2:%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'pred_phase2_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'pred_phase2_%@example.test'");
    $db->query("INSERT INTO system_settings (`key`,`value`,`group`,`type`,`description`,is_public,created_at,updated_at) VALUES ('prediction_rollover_reserve_usdt','0','prediction','numeric','test',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`='0', updated_at=NOW()");

    $winner = p2_user($db, 'job_winner_' . time());
    $loser  = p2_user($db, 'job_loser_' . time());
    p2_wallet($db, $winner, 1000);
    p2_wallet($db, $loser, 1000);
    $game = p2_game($db, 'scheduled-job-flow', 10);

    $betWinner = $service->placeBet($winner, $game, 'home', 100, 'PRED2_JOB_WIN_' . $game);
    $betLoser  = $service->placeBet($loser, $game, 'away', 100, 'PRED2_JOB_LOSE_' . $game);

    // LEGACY/INTERNAL scenario: result was written on a closed game, but primary settlement was not called yet.
    $db->execute("UPDATE prediction_games SET status='closed', result='home', winners_paid=0, finished_at=NULL, updated_at=NOW() WHERE id=?", [$game]);

    $jobResult = $job->handle();
    $gameAfter = $db->fetch("SELECT status,result,winners_paid,site_fee_usdt,rollover_amount_usdt,settlement_summary FROM prediction_games WHERE id=?", [$game]);
    $winnerWallet = p2_wallet_row($db, $winner);
    $loserWallet = p2_wallet_row($db, $loser);

    // A second run must not settle/pay again because status is now finished.
    $jobResultAgain = $job->handle();
    $winnerWalletAgain = p2_wallet_row($db, $winner);

    $ok = !empty($betWinner['success'])
        && !empty($betLoser['success'])
        && !empty($jobResult['success'])
        && (int)($jobResult['settled'] ?? 0) >= 1
        && (string)$gameAfter->status === 'finished'
        && (int)$gameAfter->winners_paid === 1
        && abs((float)$winnerWallet->balance_usdt - 1090.0) < 0.0001
        && abs((float)$winnerWallet->locked_usdt) < 0.0001
        && abs((float)$loserWallet->balance_usdt - 900.0) < 0.0001
        && abs((float)$loserWallet->locked_usdt) < 0.0001
        && abs((float)$gameAfter->site_fee_usdt - 10.0) < 0.0001
        && (int)($jobResultAgain['settled'] ?? 0) === 0
        && abs((float)$winnerWalletAgain->balance_usdt - 1090.0) < 0.0001;

    echo json_encode([
        'ok' => $ok,
        'job_result' => $jobResult,
        'job_result_again' => $jobResultAgain,
        'game' => p2_arr($gameAfter),
        'winner_wallet' => p2_arr($winnerWallet),
        'loser_wallet' => p2_arr($loserWallet),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
