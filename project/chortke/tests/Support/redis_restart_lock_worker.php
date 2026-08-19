<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap/testing.php';
[$script,$resource,$readyFile,$goFile,$resultFile,$baseValue]=$argv;
$container=\Core\Application::getInstance()->container;
$lock=$container->make(\App\Services\DistributedLockService::class);
$db=$container->make(\Core\Database::class);
$result=['acquired'=>false,'allowed'=>false,'fence'=>0];
try{
    $owned=$lock->acquire($resource,30,3,true);
    $result['acquired']=(bool)($owned['acquired']??false);
    $result['fence']=(int)($owned['fence']??0);
    $token=is_string($owned['token']??null)?$owned['token']:'';
    file_put_contents($readyFile,json_encode($result,JSON_THROW_ON_ERROR));
    if((int)$baseValue>=200){
        $result['issued_only']=true;
        file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR));
        if($token!=='')$lock->release($resource,$token);
        return;
    }
    {
        $deadline=microtime(true)+20;
        while(true){
            $statement=$db->getPdo()->query('SELECT release_allowed FROM chaos_redis_lock_control WHERE id=1');
            if(!$statement instanceof \PDOStatement)throw new \RuntimeException('redis chaos control query failed');
            if(int_value($statement->fetchColumn())===1)break;
            if(microtime(true)>$deadline)throw new \RuntimeException('redis chaos barrier timeout');
            usleep(5000);
        }
    }
    $result['allowed']=$lock->guardFence($resource,$result['fence']);
    if($result['allowed']){
        $db->execute('UPDATE chaos_redis_lock_rows SET value=?, applied_fence=? WHERE id=1',[(int)$baseValue+$result['fence'],$result['fence']]);
    }
    file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR));
    if($token!=='')$lock->release($resource,$token);
}catch(\Throwable $e){$result['exception']=get_class($e);$result['message']=$e->getMessage();file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR));}
