<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\AdSystemManager; use App\Services\CustomTask\CustomTaskExecutorService; use App\Services\CustomTask\CustomTaskModerationService; use App\Services\Shared\DisputeService;
$c=Container::getInstance(); $db=$c->make(Database::class); $manager=$c->make(AdSystemManager::class); $executor=$c->make(CustomTaskExecutorService::class); $moderation=$c->make(CustomTaskModerationService::class); $disputes=$c->make(DisputeService::class);
function arr2($o){return $o?json_decode(json_encode($o,JSON_UNESCAPED_UNICODE),true):null;}
try{
 $db->query("DELETE FROM disputes WHERE ref_type='custom_task_submission' AND ref_id IN (SELECT id FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTSPLITESCROW:%'))");
 $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTSPLITESCROW:%')");
 $db->query("DELETE FROM escrow_transactions WHERE order_type='custom_task_budget' AND order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'CTSPLITESCROW:%')");
 $db->query("DELETE FROM ads WHERE title LIKE 'CTSPLITESCROW:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'ctsplitescrow_%@example.test'");
 $admin=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctsplitescrow_admin','ctsplitescrow_admin@example.test','admin','active','admin','verified',NOW(),NOW())");
 $adv=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctsplitescrow_adv','ctsplitescrow_adv@example.test','adv','active','user','verified',NOW(),NOW())");
 $worker=(int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES ('ctsplitescrow_worker','ctsplitescrow_worker@example.test','worker','active','user','verified',NOW(),NOW())");
 foreach([[$admin,0],[$adv,1000000],[$worker,0]] as $w){$db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",$w);} 
 $create=$manager->create('custom_task',$adv,['title'=>'CTSPLITESCROW: split test','description'=>'تست split با escrow برای تسک سفارشی','link'=>'https://example.com','task_type'=>'signup','proof_type'=>'code','proof_description'=>'کد را بفرستید','price_per_task'=>10000,'total_count'=>3,'currency'=>'irt','deadline_hours'=>24]);
 $task=(int)$create['ad_id']; $escrow=$db->fetch("SELECT * FROM escrow_transactions WHERE order_id=? AND order_type='custom_task_budget' ORDER BY id DESC LIMIT 1",[(string)$task]);
 $st=$executor->startTask($task,$worker); $sub=(int)$st['submission_id'];
 $executor->submitProof($sub,$worker,['task_execution_id'=>$sub,'proof_code'=>'ESCROW-SPLIT','proof_text'=>'کد ESCROW-SPLIT','idempotency_key'=>'ESC_SPLIT_PROOF_'.bin2hex(random_bytes(3))]);
 $moderation->reviewSubmission($sub,$adv,'reject','رد برای تست split escrow');
 $db->beginTransaction(); $did=(int)$db->insert("INSERT INTO disputes (ref_type,ref_id,user_id,target_user_id,status,reason,role,created_at,updated_at) VALUES ('custom_task_submission', ?, ?, ?, 'open', 'درخواست split با escrow', 'worker', NOW(), NOW())",[$sub,$worker,$adv]); $db->query("UPDATE custom_task_submissions SET status='disputed', dispute_id=? WHERE id=?",[$did,$sub]); $db->commit();
 $res=$disputes->resolveByAdmin($admin,$did,'split','پرداخت 40 درصد از escrow',40);
 $subrow=$db->fetch("SELECT id,status,reward_paid,paid_amount,resolution_type,reward_transaction_id FROM custom_task_submissions WHERE id=?",[$sub]);
 $escrowAfter=$db->fetch("SELECT id,amount,partial_released,status FROM escrow_transactions WHERE id=?",[(int)$escrow->id]);
 $wallets=$db->fetch("SELECT (SELECT balance_irt FROM wallets WHERE user_id=?) AS worker_balance,(SELECT locked_irt FROM wallets WHERE user_id=?) AS adv_locked",[$worker,$adv]);
 echo json_encode(['ok'=>!empty($res['ok'])&&(float)$subrow->paid_amount===4000.0&&(float)$escrowAfter->partial_released===4000.0&&(float)$wallets->worker_balance===4000.0,'create'=>$create,'resolve'=>$res,'submission'=>arr2($subrow),'escrow_before'=>arr2($escrow),'escrow_after'=>arr2($escrowAfter),'wallets'=>arr2($wallets)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){try{if($db->inTransaction())$db->rollBack();}catch(Throwable){} echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}