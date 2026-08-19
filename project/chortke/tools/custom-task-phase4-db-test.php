<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container; use Core\Database; use App\Services\CustomTask\CustomTaskExecutorService; use App\Services\CustomTask\CustomTaskModerationService; use App\Services\Shared\DisputeService;
$c=Container::getInstance(); $db=$c->make(Database::class); $executor=$c->make(CustomTaskExecutorService::class); $moderation=$c->make(CustomTaskModerationService::class); $disputes=$c->make(DisputeService::class);
function u(Database $db,string $name,string $role='user'): int {return (int)$db->insert("INSERT INTO users (username,email,full_name,status,role,kyc_status,created_at,updated_at) VALUES (?, ?, ?, 'active', ?, 'verified', NOW(), NOW())",[$name,$name.'@example.test',$name,$role]);}
function w(Database $db,int $uid,float $bal=0):void{$db->insert("INSERT INTO wallets (user_id,balance_irt,balance_usdt,locked_irt,locked_usdt,created_at,updated_at) VALUES (?, ?,0,0,0,NOW(),NOW())",[$uid,$bal]);}
function task(Database $db,int $adv,string $title,string $proof='code'):int{return (int)$db->insert("INSERT INTO ads (user_id,title,description,type,task_type,proof_type,proof_description,proof_schema,price_per_task,currency,total_budget,remaining_budget,total_count,remaining_count,pending_count,completed_count,status,deadline_hours,created_at,updated_at) VALUES (?, ?, 'تست فاز چهار', 'custom_task', 'signup', ?, 'مدرک را ارسال کنید', ?, 10000, 'irt', 50000, 50000, 5,5,0,0,'active',24,NOW(),NOW())",[$adv,$title,$proof,json_encode(['type'=>$proof],JSON_UNESCAPED_UNICODE)]);} 
function arr($o){return $o?json_decode(json_encode($o,JSON_UNESCAPED_UNICODE),true):null;}
try{
 $db->query("DELETE FROM disputes WHERE ref_type='custom_task_submission' AND ref_id IN (SELECT id FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTPHASE4:%'))");
 $db->query("DELETE FROM custom_task_submissions WHERE task_id IN (SELECT id FROM ads WHERE title LIKE 'CTPHASE4:%')");
 $db->query("DELETE FROM ads WHERE title LIKE 'CTPHASE4:%'");
 $db->query("DELETE FROM users WHERE email LIKE 'ctphase4_%@example.test'");
 $admin=u($db,'ctphase4_admin','admin'); $adv=u($db,'ctphase4_adv'); $worker=u($db,'ctphase4_worker'); $worker2=u($db,'ctphase4_worker2'); w($db,$admin);w($db,$adv);w($db,$worker);w($db,$worker2);
 // split dispute
 $t1=task($db,$adv,'CTPHASE4: split dispute','code'); $st=$executor->startTask($t1,$worker); $sub=(int)$st['submission_id'];
 $executor->submitProof($sub,$worker,['task_execution_id'=>$sub,'proof_code'=>'SPLIT1','proof_text'=>'کد SPLIT1','idempotency_key'=>'P4_SPLIT_'.bin2hex(random_bytes(3))]);
 $moderation->reviewSubmission($sub,$adv,'reject','رد اولیه برای تست split');
 $db->beginTransaction(); $did=(int)$db->insert("INSERT INTO disputes (ref_type,ref_id,user_id,target_user_id,status,reason,role,created_at,updated_at) VALUES ('custom_task_submission', ?, ?, ?, 'open', 'درخواست حل میانه دارم', 'worker', NOW(), NOW())",[$sub,$worker,$adv]); $db->query("UPDATE custom_task_submissions SET status='disputed', dispute_id=? WHERE id=?",[$did,$sub]); $db->commit();
 $split=$disputes->resolveByAdmin($admin,$did,'split','پرداخت 40 درصد به مجری',40);
 $subSplit=$db->fetch("SELECT id,status,reward_paid,paid_amount,resolution_type,reward_transaction_id FROM custom_task_submissions WHERE id=?",[$sub]); $ww=$db->fetch("SELECT balance_irt FROM wallets WHERE user_id=?",[$worker]);
 // video proof URL
 $t2=task($db,$adv,'CTPHASE4: video proof','video'); $st2=$executor->startTask($t2,$worker2); $sub2=(int)$st2['submission_id'];
 $missingVideo=$executor->submitProof($sub2,$worker2,['task_execution_id'=>$sub2,'proof_text'=>'بدون لینک یا فایل','idempotency_key'=>'P4_VMISS_'.bin2hex(random_bytes(3))]);
 $videoOk=$executor->submitProof($sub2,$worker2,['task_execution_id'=>$sub2,'proof_url'=>'https://example.com/video-proof.mp4','proof_text'=>'ویدیوی مدرک در لینک ارسال شد.','idempotency_key'=>'P4_VOK_'.bin2hex(random_bytes(3))]);
 $subVideo=$db->fetch("SELECT id,status,proof_url FROM custom_task_submissions WHERE id=?",[$sub2]);
 echo json_encode(['ok'=>!empty($split['ok']) && (float)($subSplit->paid_amount??0)==4000.0 && (float)($ww->balance_irt??0)==4000.0 && empty($missingVideo['success']) && !empty($videoOk['success']),'split'=>$split,'sub_split'=>arr($subSplit),'worker_wallet'=>arr($ww),'missing_video'=>$missingVideo,'video_ok'=>$videoOk,'sub_video'=>arr($subVideo)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){try{if($db->inTransaction())$db->rollBack();}catch(Throwable){} echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; exit(1);}