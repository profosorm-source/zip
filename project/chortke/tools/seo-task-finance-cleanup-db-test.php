<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\AdSystemManager; use App\Services\Seo\SeoService; use App\Services\Seo\AdsSeoService;
$c=Container::getInstance(); $db=$c->make(Database::class); $manager=$c->make(AdSystemManager::class); $seo=$c->make(SeoService::class); $adsSeo=$c->make(AdsSeoService::class);
function su(Database $db,string $n):int{return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",[$n,$n.'@example.test',$n]);}
function sw(Database $db,int $u,float $b):void{$db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",[$u,$b]);}
function ar($o){return $o?json_decode(json_encode($o,JSON_UNESCAPED_UNICODE),true):null;}
try{
 $db->query("DELETE FROM seo_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'SEOFIN:%')");
 $db->query("DELETE FROM escrow_transactions WHERE order_type IN ('seo_ad_budget','ad_creation_seo') AND (order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'SEOFIN:%') OR order_id IN (SELECT id FROM saga_executions WHERE saga_name='ad_creation_seo'))");
 $db->query("DELETE FROM ads WHERE title LIKE 'SEOFIN:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'seofin_%@example.test'");
 $adv=su($db,'seofin_adv'); $worker=su($db,'seofin_worker'); sw($db,$adv,1000000); sw($db,$worker,0);
 $create=$manager->create('seo',$adv,['title'=>'SEOFIN: refund cleanup','target_link'=>'https://example.com','keyword'=>'finance','budget'=>100000,'min_payout'=>1000,'max_payout'=>5000,'target_duration'=>60,'min_score'=>40,'max_per_day'=>10,'currency'=>'irt']);
 $ad=(int)$create['ad_id'];
 $st=$seo->startTask($ad,$worker); $eid=(int)$st['execution_id'];
 $comp=$seo->completeTask($eid,$worker,['duration'=>180,'scroll_depth'=>85,'interactions'=>8,'scroll_speed'=>300,'mouse_pattern'=>'normal','pause_count'=>5,'interaction_types'=>['external_open','return_to_task','scroll','click'],'target_opened'=>1,'behavior'=>['scroll_speed'=>300,'mouse_pattern'=>'normal','pause_count'=>5,'interaction_types'=>['external_open','return_to_task','scroll','click'],'target_opened'=>1]]);
 $beforeClose=['ad'=>$db->fetch("SELECT id,status,remaining_budget,executions_count FROM ads WHERE id=?",[$ad]),'escrow'=>$db->fetch("SELECT id,amount,partial_released,status FROM escrow_transactions WHERE order_id=? AND order_type='seo_ad_budget'",[(string)$ad]),'adv_wallet'=>$db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?",[$adv]),'worker_wallet'=>$db->fetch("SELECT balance_irt FROM wallets WHERE user_id=?",[$worker])];
 $close=$adsSeo->closeAndRefundBudget($ad,'cancelled','تست بستن و آزادسازی بودجه باقی‌مانده',999);
 $afterClose=['ad'=>$db->fetch("SELECT id,status,remaining_budget,executions_count FROM ads WHERE id=?",[$ad]),'escrow'=>$db->fetch("SELECT id,amount,partial_released,status,refund_reason FROM escrow_transactions WHERE order_id=? AND order_type='seo_ad_budget'",[(string)$ad]),'adv_wallet'=>$db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?",[$adv]),'worker_wallet'=>$db->fetch("SELECT balance_irt FROM wallets WHERE user_id=?",[$worker])];
 echo json_encode(['ok'=>!empty($comp['success'])&&!empty($close['success'])&&($afterClose['ad']->status??'')==='cancelled'&&(float)$afterClose['ad']->remaining_budget===0.0&&(float)$afterClose['adv_wallet']->locked_irt===0.0&&(float)$afterClose['worker_wallet']->balance_irt>0,'create'=>$create,'complete'=>$comp,'before_close'=>array_map('ar',$beforeClose),'close'=>$close,'after_close'=>array_map('ar',$afterClose)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){try{if($db->inTransaction())$db->rollBack();}catch(Throwable){} echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}