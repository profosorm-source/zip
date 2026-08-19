<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\PredictionService;

$c = Container::getInstance();
$db = $c->make(Database::class);
$service = $c->make(PredictionService::class);

function pr_row($o) { return $o ? json_decode(json_encode($o, JSON_UNESCAPED_UNICODE), true) : null; }
function pr_user(Database $db, string $suffix): int {
    return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())", ["pred_phase1_$suffix", "pred_phase1_$suffix@example.test", "Prediction Phase1 $suffix", 'active', 'user', 'verified']);
}
function pr_wallet(Database $db, int $userId, float $usdt): void {
    $db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?,0,?,0,0,NOW(),NOW())", [$userId, $usdt]);
}
function pr_game(Database $db, string $suffix, float $commission = 10.0, float $bonus = 0.0): int {
    return (int)$db->insert(
        "INSERT INTO prediction_games (title,team_home,team_away,sport_type,match_date,bet_deadline,min_bet_usdt,max_bet_usdt,commission_percent,bonus_pool_usdt,status,created_by,created_at,updated_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),1,10000,?,?,'open',1,NOW(),NOW())",
        ["PRED-PHASE1: $suffix", 'A', 'B', 'football', $commission, $bonus]
    );
}
function wallet(Database $db, int $userId): object { return $db->fetch("SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=?", [$userId]); }

try {
    $db->query("DELETE FROM outbox_events WHERE payload LIKE '%PRED-PHASE1%' OR aggregate_type LIKE 'prediction%'");
    $db->query("DELETE FROM transactions WHERE metadata LIKE '%prediction_%' OR description LIKE '%پیش‌بینی%' OR description LIKE '%شرط بازی%'");
    $db->query("DELETE FROM prediction_bets WHERE game_id IN (SELECT id FROM prediction_games WHERE title LIKE 'PRED-PHASE1:%')");
    $db->query("DELETE FROM prediction_games WHERE title LIKE 'PRED-PHASE1:%'");
    $db->query("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'pred_phase1_%@example.test')");
    $db->query("DELETE FROM users WHERE email LIKE 'pred_phase1_%@example.test'");
    $db->query("INSERT INTO system_settings (`key`,`value`,`group`,`type`,`description`,is_public,created_at,updated_at) VALUES ('prediction_rollover_reserve_usdt','0','prediction','numeric','test',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`='0', updated_at=NOW()");

    // Case 1: one winner, one loser. Commission only from loser pool.
    $w1 = pr_user($db, 'winner_' . time());
    $l1 = pr_user($db, 'loser_' . time());
    pr_wallet($db, $w1, 1000); pr_wallet($db, $l1, 1000);
    $g1 = pr_game($db, 'winner-loser', 10);
    $b1 = $service->placeBet($w1, $g1, 'home', 100, 'PRED1_W_' . $g1);
    $b2 = $service->placeBet($l1, $g1, 'away', 100, 'PRED1_L_' . $g1);
    $settle1 = $service->settleGame($g1, 'home', 1);
    $w1Wallet = wallet($db, $w1); $l1Wallet = wallet($db, $l1);
    $g1Row = $db->fetch("SELECT site_fee_usdt,rollover_amount_usdt,settlement_summary FROM prediction_games WHERE id=?", [$g1]);

    // Case 2: all winners, no loser pool => no fee, stake only returned.
    $aw1 = pr_user($db, 'allwin1_' . time()); $aw2 = pr_user($db, 'allwin2_' . time());
    pr_wallet($db, $aw1, 1000); pr_wallet($db, $aw2, 1000);
    $g2 = pr_game($db, 'all-winners', 10);
    $service->placeBet($aw1, $g2, 'draw', 100, 'PRED2_A_' . $g2);
    $service->placeBet($aw2, $g2, 'draw', 100, 'PRED2_B_' . $g2);
    $settle2 = $service->settleGame($g2, 'draw', 1);
    $aw1Wallet = wallet($db, $aw1); $aw2Wallet = wallet($db, $aw2);
    $g2Row = $db->fetch("SELECT site_fee_usdt,rollover_amount_usdt,settlement_summary FROM prediction_games WHERE id=?", [$g2]);

    // Case 3: no winners => all lost, 50% rollover, 50% site.
    $n1 = pr_user($db, 'nowin1_' . time()); $n2 = pr_user($db, 'nowin2_' . time());
    pr_wallet($db, $n1, 1000); pr_wallet($db, $n2, 1000);
    $g3 = pr_game($db, 'no-winners', 10);
    $service->placeBet($n1, $g3, 'home', 100, 'PRED3_A_' . $g3);
    $service->placeBet($n2, $g3, 'away', 100, 'PRED3_B_' . $g3);
    $settle3 = $service->settleGame($g3, 'draw', 1);
    $n1Wallet = wallet($db, $n1); $n2Wallet = wallet($db, $n2);
    $g3Row = $db->fetch("SELECT site_fee_usdt,rollover_amount_usdt,settlement_summary FROM prediction_games WHERE id=?", [$g3]);
    $reserveAfterNoWinner = (float)$db->fetchColumn("SELECT `value` FROM system_settings WHERE `key`='prediction_rollover_reserve_usdt'");

    // Case 4: cancel => refund/cancel hold, not a real loss.
    $c1 = pr_user($db, 'cancel_' . time()); pr_wallet($db, $c1, 1000);
    $g4 = pr_game($db, 'cancel', 10);
    $service->placeBet($c1, $g4, 'home', 100, 'PRED4_A_' . $g4);
    $cancel4 = $service->cancelGame($g4, 1);
    $c1Wallet = wallet($db, $c1);

    // Double settle should not pay twice.
    $settleAgain = $service->settleGame($g1, 'home', 1);
    $w1WalletAfterSecond = wallet($db, $w1);

    $ok = !empty($b1['success']) && !empty($b2['success']) && !empty($settle1['success'])
        && abs((float)$w1Wallet->balance_usdt - 1090.0) < 0.0001
        && abs((float)$w1Wallet->locked_usdt) < 0.0001
        && abs((float)$l1Wallet->balance_usdt - 900.0) < 0.0001
        && abs((float)$l1Wallet->locked_usdt) < 0.0001
        && abs((float)$g1Row->site_fee_usdt - 10.0) < 0.0001
        && abs((float)$g1Row->rollover_amount_usdt) < 0.0001
        && !empty($settle2['success'])
        && abs((float)$aw1Wallet->balance_usdt - 1000.0) < 0.0001
        && abs((float)$aw2Wallet->balance_usdt - 1000.0) < 0.0001
        && abs((float)$g2Row->site_fee_usdt) < 0.0001
        && !empty($settle3['success'])
        && !empty($settle3['summary']['no_winners'])
        && abs((float)$n1Wallet->balance_usdt - 900.0) < 0.0001
        && abs((float)$n2Wallet->balance_usdt - 900.0) < 0.0001
        && abs((float)$g3Row->site_fee_usdt - 100.0) < 0.0001
        && abs((float)$g3Row->rollover_amount_usdt - 100.0) < 0.0001
        && abs($reserveAfterNoWinner - 100.0) < 0.0001
        && !empty($cancel4['success'])
        && abs((float)$c1Wallet->balance_usdt - 1000.0) < 0.0001
        && abs((float)$c1Wallet->locked_usdt) < 0.0001
        && empty($settleAgain['success'])
        && abs((float)$w1WalletAfterSecond->balance_usdt - 1090.0) < 0.0001;

    echo json_encode([
        'ok' => $ok,
        'winner_loser' => ['settle' => $settle1, 'winner_wallet' => pr_row($w1Wallet), 'loser_wallet' => pr_row($l1Wallet), 'game' => pr_row($g1Row)],
        'all_winners' => ['settle' => $settle2, 'wallet1' => pr_row($aw1Wallet), 'wallet2' => pr_row($aw2Wallet), 'game' => pr_row($g2Row)],
        'no_winners' => ['settle' => $settle3, 'wallet1' => pr_row($n1Wallet), 'wallet2' => pr_row($n2Wallet), 'game' => pr_row($g3Row), 'reserve' => $reserveAfterNoWinner],
        'cancel' => ['result' => $cancel4, 'wallet' => pr_row($c1Wallet)],
        'double_settle' => ['result' => $settleAgain, 'winner_wallet_after_second' => pr_row($w1WalletAfterSecond)],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
