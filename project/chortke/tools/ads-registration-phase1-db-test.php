<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\AdSystemManager;
$c=Container::getInstance(); $db=$c->make(Database::class); $manager=$c->make(AdSystemManager::class);
function adUser(Database $db,string $n):int{return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",[$n,$n.'@example.test',$n]);}
function adWallet(Database $db,int $u,float $b):void{$db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",[$u,$b]);}
function arrA($o){return $o?json_decode(json_encode($o,JSON_UNESCAPED_UNICODE),true):null;}
try{
 $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADSREG:%') OR order_id IN (SELECT id FROM saga_executions WHERE saga_name LIKE 'ad_creation_%')");
 $db->query("DELETE FROM ads WHERE title LIKE 'ADSREG:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'adsreg_%@example.test'");
 $u=adUser($db,'adsreg_user'); adWallet($db,$u,10000000);
 $cases=[
  'social_task'=>['title'=>'ADSREG: social','platform'=>'instagram','task_type'=>'follow','target_link'=>'https://instagram.com/example','price_per_task'=>1000,'total_count'=>5,'currency'=>'irt'],
  'adtube'=>['title'=>'ADSREG: adtube','target_link'=>'https://youtube.com/watch?v=abc123','price_per_task'=>1200,'total_count'=>4,'currency'=>'irt'],
  'seo'=>['title'=>'ADSREG: seo','target_link'=>'https://example.com','keyword'=>'adsreg','budget'=>20000,'min_payout'=>1000,'max_payout'=>3000,'currency'=>'irt'],
  'custom_task'=>['title'=>'ADSREG: custom','description'=>'ثبت نام در سایت و ارسال کد کاربری برای بررسی','link'=>'https://example.com/signup','task_type'=>'signup','proof_type'=>'code','proof_description'=>'کد کاربری را ارسال کنید','price_per_task'=>5000,'total_count'=>6,'currency'=>'irt'],
  'banner'=>['title'=>'ADSREG: banner','placement'=>'header','link'=>'https://example.com','budget'=>30000,'image_path'=>'banners/test-placeholder.jpg','currency'=>'irt'],
  'notification'=>['title'=>'ADSREG: notification','body'=>'متن پیام تبلیغاتی تستی برای نوتیفیکیشن','target_link'=>'https://example.com','budget'=>25000,'currency'=>'irt'],
 ];
 $results=[];
 foreach($cases as $type=>$payload){
   try{$results[$type]=$manager->create($type,$u,$payload);}catch(Throwable $e){$results[$type]=['error'=>$e->getMessage()];}
 }
 $ads=$db->fetchAll("SELECT id,type,title,total_budget,remaining_budget,total_count,remaining_count,status,site_url,target_url,link,proof_type FROM ads WHERE title LIKE 'ADSREG:%' ORDER BY id")?:[];
 $escrows=$db->fetchAll("SELECT id,order_id,order_type,amount,status FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADSREG:%') ORDER BY id")?:[];
 $wallet=$db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?",[$u]);
 $types=array_map(fn($a)=>$a->type,$ads); sort($types);
 $escrowTypes=array_map(fn($e)=>$e->order_type,$escrows); sort($escrowTypes);
 $expected=['adtube','banner','custom_task','notification','seo','social_task'];
 $expectedEscrow=['adtube_budget','banner_budget','custom_task_budget','notification_ad_budget','seo_ad_budget','social_task_budget'];
 sort($expected); sort($expectedEscrow);
 echo json_encode(['ok'=>$types===$expected && $escrowTypes===$expectedEscrow && count(array_filter($results,fn($r)=>empty($r['error'])))===6,'results'=>$results,'ads'=>array_map('arrA',$ads),'escrows'=>array_map('arrA',$escrows),'wallet'=>arrA($wallet),'assertions'=>['types'=>$types,'expected'=>$expected,'escrow_types'=>$escrowTypes,'expected_escrow'=>$expectedEscrow]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}