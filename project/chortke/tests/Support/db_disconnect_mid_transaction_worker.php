<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap/testing.php';
[$script,$readyFile,$goFile,$resultFile]=$argv;
$db=\Core\Application::getInstance()->container->make(\Core\Database::class);
$result=['failed_as_expected'=>false,'recovered'=>false,'exception'=>null,'message'=>null];
try{
    $db->transactional(function(\Core\Database $tx)use($readyFile,$goFile):void{
        $tx->execute('UPDATE chaos_disconnect_rows SET value=1 WHERE id=1');
        $connectionId=(int)$tx->fetchColumn('SELECT CONNECTION_ID()');
        file_put_contents($readyFile,json_encode(['connection_id'=>$connectionId],JSON_THROW_ON_ERROR));
        $deadline=microtime(true)+10;
        while(!is_file($goFile)){
            if(microtime(true)>$deadline)throw new \RuntimeException('chaos barrier timeout');
            usleep(1000);
        }
        // This statement must never reconnect/autocommit after the original
        // transaction connection has been killed.
        $tx->execute('UPDATE chaos_disconnect_rows SET value=2 WHERE id=1');
    },1);
}catch(\Throwable $e){
    $result['failed_as_expected']=true;$result['exception']=get_class($e);$result['message']=$e->getMessage();
}
try{$result['recovered']=(int)$db->fetchColumn('SELECT 1')===1;}catch(\Throwable $e){$result['recovery_error']=$e->getMessage();}
$result['in_transaction']=$db->inTransaction();
file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR));
