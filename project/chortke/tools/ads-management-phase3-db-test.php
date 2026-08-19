<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\AdSystemManager;
$c=Container::getInstance(); $db=$c->make(Database::class); $manager=$c->make(AdSystemManager::class);
function mu(Database $db,string $n):int{return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",[$n,$n.'@example.test',$n]);}
function mw(Database $db,int $u,float $b):void{$db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",[$u,$b]);}
try{
 $db->query("DELETE FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADSMGMT:%')");
 $db->query("DELETE FROM ads WHERE title LIKE 'ADSMGMT:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'adsmgmt_%@example.test'");
 $u=mu($db,'adsmgmt_user'); mw($db,$u,10000000);
 $payloads=[
  'social_task'=>['title'=>'ADSMGMT: social','platform'=>'instagram','task_type'=>'follow','target_link'=>'https://instagram.com/example','price_per_task'=>1000,'total_count'=>5,'currency'=>'irt'],
  'adtube'=>['title'=>'ADSMGMT: adtube','target_link'=>'https://youtube.com/watch?v=abc123','price_per_task'=>1200,'total_count'=>4,'currency'=>'irt'],
  'seo'=>['title'=>'ADSMGMT: seo','target_link'=>'https://example.com','keyword'=>'ads','budget'=>20000,'min_payout'=>1000,'max_payout'=>3000,'currency'=>'irt'],
  'custom_task'=>['title'=>'ADSMGMT: custom','description'=>'ثبت نام در سایت و ارسال کد کاربری برای بررسی','link'=>'https://example.com/signup','task_type'=>'signup','proof_type'=>'code','proof_description'=>'کد کاربری را ارسال کنید','price_per_task'=>5000,'total_count'=>6,'currency'=>'irt'],
  'banner'=>['title'=>'ADSMGMT: banner','placement'=>'header','link'=>'https://example.com','budget'=>30000,'image_path'=>'banners/test-placeholder.jpg','currency'=>'irt'],
  'notification'=>['title'=>'ADSMGMT: notification','body'=>'متن پیام تبلیغاتی تستی برای کاربران','target_link'=>'https://example.com','budget'=>25000,'currency'=>'irt'],
 ];
 $created=[]; foreach($payloads as $type=>$payload){$created[$type]=$manager->create($type,$u,$payload);} 
 $toggleAd=(int)$created['custom_task']['ad_id']; $pause=$manager->toggleAdStatus($toggleAd,$u); $resume=$manager->toggleAdStatus($toggleAd,$u);
 $cancel=[]; foreach($created as $type=>$res){$cancel[$type]=$manager->cancelAd((int)$res['ad_id'],$u,'phase3 cancel test');}
 $ads=$db->fetchAll("SELECT id,type,status,remaining_budget,is_active FROM ads WHERE title LIKE 'ADSMGMT:%' ORDER BY type")?:[];
 $escrows=$db->fetchAll("SELECT order_type,status,amount FROM escrow_transactions WHERE order_id IN (SELECT CAST(id AS CHAR) FROM ads WHERE title LIKE 'ADSMGMT:%') ORDER BY order_type")?:[];
 $wallet=$db->fetch("SELECT balance_irt,locked_irt FROM wallets WHERE user_id=?",[$u]);
 $allCancelled=count($ads)===6 && count(array_filter($ads,fn($a)=>(string)$a->status==='cancelled'&&(float)$a->remaining_budget===0.0))===6;
 $allRefunded=count($escrows)===6 && count(array_filter($escrows,fn($e)=>(string)$e->status==='refunded'&&(float)$e->amount===0.0))===6;
 echo json_encode(['ok'=>$allCancelled&&$allRefunded&&(float)$wallet->locked_irt===0.0&&!empty($pause['success'])&&!empty($resume['success']),'pause'=>$pause,'resume'=>$resume,'cancel'=>$cancel,'ads'=>$ads,'escrows'=>$escrows,'wallet'=>$wallet],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){try{if($db->inTransaction())$db->rollBack();}catch(Throwable){} echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}