<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\UnifiedTaskService;
$c=Container::getInstance(); $db=$c->make(Database::class); $feed=$c->make(UnifiedTaskService::class);
function tu(Database $db,string $n):int{return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active','user','verified',NOW(),NOW())",[$n,$n.'@example.test',$n]);}
function ad(Database $db,int $uid,string $title,string $type,array $extra=[]):int{return (int)$db->insert("INSERT INTO ads (user_id,title,description,type,platform,task_type,price_per_task,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,site_url,target_url,keyword,min_payout,max_payout,target_duration,min_score,max_per_day,currency,proof_type,created_at,updated_at) VALUES (?, ?, 'marketplace test', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, ?, ?, ?, ?, ?, ?, ?, 'irt', ?, NOW(), NOW())",[$uid,$title,$type,$extra['platform']??null,$extra['task_type']??null,$extra['price']??1000,$extra['budget']??10000,$extra['remaining_budget']??10000,$extra['count']??5,$extra['remaining_count']??5,$extra['site_url']??null,$extra['target_url']??null,$extra['keyword']??null,$extra['min_payout']??0,$extra['max_payout']??0,$extra['target_duration']??60,$extra['min_score']??40,$extra['max_per_day']??10,$extra['proof_type']??null]);}
try{
 $db->query("DELETE FROM social_task_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'MARKET:%')");
 $db->query("DELETE FROM seo_executions WHERE ad_id IN (SELECT id FROM ads WHERE title LIKE 'MARKET:%')");
 $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'MARKET:%')");
 $db->query("DELETE FROM ads WHERE title LIKE 'MARKET:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'market_%@example.test'");
 $adv=tu($db,'market_adv'); $worker=tu($db,'market_worker');
 $social=ad($db,$adv,'MARKET: social instagram','social_task',['platform'=>'instagram','task_type'=>'follow','price'=>12000,'budget'=>120000,'count'=>10,'remaining_count'=>10]);
 $seo=ad($db,$adv,'MARKET: seo google','seo',['platform'=>'google','price'=>0,'budget'=>100000,'remaining_budget'=>100000,'count'=>0,'remaining_count'=>0,'site_url'=>'https://example.com','target_url'=>'https://example.com','keyword'=>'چرتکه','min_payout'=>1000,'max_payout'=>5000]);
 $custom=ad($db,$adv,'MARKET: custom signup','custom_task',['task_type'=>'signup','proof_type'=>'code','price'=>20000,'budget'=>200000,'count'=>10,'remaining_count'=>10]);
 $self=ad($db,$worker,'MARKET: self hidden','custom_task',['price'=>1000,'budget'=>10000,'count'=>5,'remaining_count'=>5]);
 $items=$feed->getTasksForExecutor($worker, [], 20, 0); $ids=array_map(fn($x)=>(int)$x->id,$items);
 $initialOk=in_array($social,$ids,true)&&in_array($seo,$ids,true)&&in_array($custom,$ids,true)&&!in_array($self,$ids,true);
 $socialFiltered=$feed->getTasksForExecutor($worker, ['type'=>'social'], 20, 0); $socialOk=count($socialFiltered)===1 && (int)$socialFiltered[0]->id===$social;
 $db->insert("INSERT INTO custom_task_submissions (task_id,worker_id,user_id,status,reward_amount,reward_currency,deadline_at,created_at,updated_at) VALUES (?, ?, ?, 'in_progress', 20000, 'irt', DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW(), NOW())",[$custom,$worker,$worker]);
 $afterCustom=$feed->getTasksForExecutor($worker, [], 20, 0); $ids2=array_map(fn($x)=>(int)$x->id,$afterCustom); $customExcluded=!in_array($custom,$ids2,true);
 $db->insert("INSERT INTO seo_executions (ad_id,user_id,status,ip_address,device_fingerprint,started_at,execution_date,created_at) VALUES (?, ?, 'rejected', '127.0.0.1','fp',NOW(),CURDATE(),NOW())",[$seo,$worker]);
 $afterSeo=$feed->getTasksForExecutor($worker, [], 20, 0); $ids3=array_map(fn($x)=>(int)$x->id,$afterSeo); $seoExcluded=!in_array($seo,$ids3,true);
 $db->insert("INSERT INTO social_task_executions (ad_id,executor_id,status,created_at,updated_at) VALUES (?, ?, 'rejected', NOW(), NOW())",[$social,$worker]);
 $socialRetry=$feed->getTasksForExecutor($worker, ['type'=>'social'], 20, 0); $socialRetryOk=count($socialRetry)===1 && (int)$socialRetry[0]->id===$social;
 echo json_encode(['ok'=>$initialOk&&$socialOk&&$customExcluded&&$seoExcluded&&$socialRetryOk,'initial_ids'=>$ids,'after_custom_ids'=>$ids2,'after_seo_ids'=>$ids3,'social_retry_ids'=>array_map(fn($x)=>(int)$x->id,$socialRetry),'assertions'=>compact('initialOk','socialOk','customExcluded','seoExcluded','socialRetryOk')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}