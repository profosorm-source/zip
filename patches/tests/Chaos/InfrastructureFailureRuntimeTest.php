<?php

declare(strict_types=1);

namespace Tests\Chaos;

use PHPUnit\Framework\TestCase;

final class InfrastructureFailureRuntimeTest extends TestCase
{
    public function test_real_mariadb_deadlock_is_retried_and_both_transactions_commit(): void
    {
        $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
        $database->execute('CREATE TABLE IF NOT EXISTS chaos_deadlock_rows (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        $database->execute('DELETE FROM chaos_deadlock_rows');
        $database->execute('INSERT INTO chaos_deadlock_rows (id,value) VALUES (1,0),(2,0)');

        $barrierDir = sys_get_temp_dir() . '/chortke-deadlock-' . bin2hex(random_bytes(6));
        mkdir($barrierDir, 0777, true);
        $resultFiles = [tempnam(sys_get_temp_dir(), 'deadlock-a-'), tempnam(sys_get_temp_dir(), 'deadlock-b-')];
        $logFiles = [tempnam(sys_get_temp_dir(), 'deadlock-log-a-'), tempnam(sys_get_temp_dir(), 'deadlock-log-b-')];
        $orders = [[1, 2, 'a'], [2, 1, 'b']];
        $processes = [];

        foreach ($orders as $index => [$first, $second, $name]) {
            $process = proc_open([
                PHP_BINARY,
                base_path('tests/Support/deadlock_retry_worker.php'),
                (string)$first,
                (string)$second,
                $name,
                $barrierDir,
                $resultFiles[$index],
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFiles[$index], 'a'],
                2 => ['file', $logFiles[$index], 'a'],
            ], $pipes, base_path());
            if (!is_resource($process)) $this->fail('Unable to start deadlock worker.');
            $processes[] = $process;
        }

        $exitCodes = array_map(static fn($process): int => proc_close($process), $processes);
        try {
            $results = array_map(
                fn(string $f): array => $this->decodeArray((string)file_get_contents($f)),
                $resultFiles
            );
            $diagnostics = json_encode($results, JSON_UNESCAPED_SLASHES) . "\n"
                . implode("\n", array_map(static fn(string $f): string => (string)file_get_contents($f), $logFiles));
            $this->assertSame([0, 0], $exitCodes, $diagnostics);
            foreach ($results as $result) {
                $this->assertTrue((bool)($result['ok'] ?? false), (json_encode($result) ?: ''));
                $this->assertSame('committed', $result['result'] ?? null);
                $this->assertLessThanOrEqual(3, (int)($result['attempts'] ?? 99));
            }
            $attemptCounts = array_map(static fn(array $r): int => (int)$r['attempts'], $results);
            $this->assertSame(3, array_sum($attemptCounts), 'Exactly one deadlock victim must retry once.');
            $rows = $database->fetchAll('SELECT id,value FROM chaos_deadlock_rows ORDER BY id');
            $this->assertSame([2, 2], array_map(static fn(object $r): int => (int)$r->value, $rows));
        } finally {
            $database->execute('DROP TABLE IF EXISTS chaos_deadlock_rows');
            foreach (array_merge($resultFiles, $logFiles, glob($barrierDir . '/*') ?: []) as $file) {
                if (is_string($file) && is_file($file)) @unlink($file);
            }
            @rmdir($barrierDir);
        }
    }

    public function test_stalled_redis_socket_times_out_and_is_marked_unavailable(): void
    {
        $port = 8093;
        $serverLog = tempnam(sys_get_temp_dir(), 'redis-hang-log-');
        $resultFile = tempnam(sys_get_temp_dir(), 'redis-timeout-result-');
        $workerLog = tempnam(sys_get_temp_dir(), 'redis-timeout-log-');
        $server = proc_open([
            PHP_BINARY,
            base_path('tests/Support/hanging_redis_server.php'),
            (string)$port,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $serverLog, 'a'],
            2 => ['file', $serverLog, 'a'],
        ], $pipes, base_path());
        $this->assertIsResource($server);
        usleep(150_000);

        $worker = proc_open([
            PHP_BINARY,
            base_path('tests/Support/redis_timeout_worker.php'),
            (string)$port,
            $resultFile,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $workerLog, 'a'],
            2 => ['file', $workerLog, 'a'],
        ], $pipes, base_path());
        $this->assertIsResource($worker);
        $workerExit = proc_close($worker);
        proc_terminate($server, SIGTERM);
        proc_close($server);

        try {
            $this->assertSame(0, $workerExit, (string)file_get_contents($workerLog));
            $result = $this->decodeArray((string)file_get_contents($resultFile));
            $this->assertFalse((bool)($result['available'] ?? true));
            $this->assertLessThan(2.0, (float)($result['elapsed'] ?? 99), 'Redis read timeout was not enforced: ' . (json_encode($result) ?: '') . ' server=' . (string)file_get_contents($serverLog));
        } finally {
            foreach ([$serverLog, $resultFile, $workerLog] as $file) {
                if (is_string($file) && is_file($file)) @unlink($file);
            }
        }
    }

    public function test_killed_queue_worker_leaves_reservation_and_next_worker_recovers_once(): void
    {
        $container = \Core\Application::getInstance()->container;
        $database = $container->make(\Core\Database::class);
        $queue = $container->make(\Core\Queue::class);
        $queueName = 'chaos_worker_crash';
        $marker = 'chaos_crash_' . bin2hex(random_bytes(6));
        $reservedFile = tempnam(sys_get_temp_dir(), 'queue-crash-reserved-');
        $logFile = tempnam(sys_get_temp_dir(), 'queue-crash-log-');
        $this->assertTrue($queue->push(\Tests\Fixtures\RuntimeProbeJob::class, ['marker' => $marker], $queueName));

        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/queue_crash_worker.php'),
            $queueName,
            $reservedFile,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ], $pipes, base_path());
        $this->assertIsResource($process);

        $deadline = microtime(true) + 5.0;
        while ((!is_file($reservedFile) || filesize($reservedFile) === 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $reserved = $this->decodeArray((string)file_get_contents($reservedFile));
        $this->assertTrue((bool)($reserved['ok'] ?? false), (json_encode($reserved) ?: ''));
        $jobId = (int)($reserved['job']['id'] ?? 0);
        $this->assertGreaterThan(0, $jobId);

        $status = proc_get_status($process);
        if (!empty($status['running'])) {
            posix_kill((int)$status['pid'], SIGKILL);
        }
        proc_close($process);

        try {
            $row = $database->fetch('SELECT reserved_at,attempts FROM queues WHERE id=?', [$jobId]);
            $this->assertNotNull($row, 'Killed worker must not delete the reserved job.');
            $this->assertNotNull($row->reserved_at);
            $this->assertSame(1, (int)$row->attempts);

            // Advance the persisted lease instead of sleeping for visibility_timeout.
            $database->execute('UPDATE queues SET reserved_at=DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id=?', [$jobId]);
            $recoveryResultFile = tempnam(sys_get_temp_dir(), 'queue-recovery-result-');
            $recoveryLogFile = tempnam(sys_get_temp_dir(), 'queue-recovery-log-');
            $recovery = proc_open([
                PHP_BINARY,
                base_path('tests/Support/queue_runtime_worker.php'),
                $queueName,
                $recoveryResultFile,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $recoveryLogFile, 'a'],
                2 => ['file', $recoveryLogFile, 'a'],
            ], $recoveryPipes, base_path());
            $this->assertIsResource($recovery);
            $recoveryExit = proc_close($recovery);
            $this->assertSame(0, $recoveryExit, (string)file_get_contents($recoveryLogFile));
            $recoveryPayload = $this->decodeArray((string)file_get_contents($recoveryResultFile));
            $this->assertTrue((bool)($recoveryPayload['ok'] ?? false), (json_encode($recoveryPayload) ?: ''));
            $result = is_array($recoveryPayload['result'] ?? null) ? $recoveryPayload['result'] : [];

            $this->assertSame(1, (int)($result['processed_jobs'] ?? 0));
            $this->assertSame(0, (int)($result['failed_jobs'] ?? 0));
            $this->assertSame(0, (int)$database->fetchColumn('SELECT COUNT(*) FROM queues WHERE id=?', [$jobId]));
            @unlink($recoveryResultFile);
            @unlink($recoveryLogFile);
            $this->assertSame('1', (string)$database->fetchColumn('SELECT `value` FROM system_settings WHERE `key`=?', [$marker]));
        } finally {
            $database->execute('DELETE FROM queues WHERE payload LIKE ?', ['%' . $marker . '%']);
            $database->execute('DELETE FROM system_settings WHERE `key`=?', [$marker]);
            @unlink($reservedFile);
            @unlink($logFile);
            if (isset($recoveryResultFile) && is_string($recoveryResultFile)) @unlink($recoveryResultFile);
            if (isset($recoveryLogFile) && is_string($recoveryLogFile)) @unlink($recoveryLogFile);
        }
    }

    public function test_database_disconnect_mid_transaction_rolls_back_without_partial_reconnect_commit(): void
    {
        $db=\Core\Application::getInstance()->container->make(\Core\Database::class);
        $db->execute('CREATE TABLE IF NOT EXISTS chaos_disconnect_rows (id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB');
        $db->execute('DELETE FROM chaos_disconnect_rows');$db->execute('INSERT INTO chaos_disconnect_rows (id,value) VALUES (1,0)');
        $ready=tempnam(sys_get_temp_dir(),'db-disconnect-ready-');$go=tempnam(sys_get_temp_dir(),'db-disconnect-go-');$result=tempnam(sys_get_temp_dir(),'db-disconnect-result-');$log=tempnam(sys_get_temp_dir(),'db-disconnect-log-');
        @unlink($ready);@unlink($go);
        $process=proc_open([PHP_BINARY,base_path('tests/Support/db_disconnect_mid_transaction_worker.php'),$ready,$go,$result],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,base_path());
        $this->assertIsResource($process);
        try{
            $this->waitForNonEmptyFile($ready,8.0);
            $readyData=$this->decodeArray((string)file_get_contents($ready));
            $connectionId=int_value($readyData['connection_id']??0);$this->assertGreaterThan(0,$connectionId);
            $db->execute('KILL CONNECTION '.$connectionId);
            file_put_contents($go,'continue');
            $exit=proc_close($process);$process=null;
            $this->assertSame(0,$exit,(string)file_get_contents($log));
            $payload=$this->decodeArray((string)file_get_contents($result));
            $this->assertTrue((bool)($payload['failed_as_expected']??false),(json_encode($payload) ?: ''));
            $this->assertTrue((bool)($payload['recovered']??false),(json_encode($payload) ?: ''));
            $this->assertFalse((bool)($payload['in_transaction']??true));
            $this->assertSame(0,(int)$db->fetchColumn('SELECT value FROM chaos_disconnect_rows WHERE id=1'),'Killed transaction partially committed after reconnect.');
        }finally{
            if(is_resource($process))proc_terminate($process);
            $db->execute('DROP TABLE IF EXISTS chaos_disconnect_rows');
            foreach([$ready,$go,$result,$log] as $f)if(is_string($f))@unlink($f);
        }
    }

    public function test_redis_restart_while_lock_held_rejects_stale_fence_and_new_owner_recovers(): void
    {
        $db=\Core\Application::getInstance()->container->make(\Core\Database::class);
        $resource='chaos:redis-restart:'.bin2hex(random_bytes(6));$hash=hash('sha256',$resource);
        $db->execute('CREATE TABLE IF NOT EXISTS chaos_redis_lock_rows (id INT PRIMARY KEY,value BIGINT NOT NULL,applied_fence BIGINT NOT NULL) ENGINE=InnoDB');
        $db->execute('CREATE TABLE IF NOT EXISTS chaos_redis_lock_control (id INT PRIMARY KEY,release_allowed TINYINT NOT NULL) ENGINE=InnoDB');
        $db->execute('DELETE FROM chaos_redis_lock_rows');$db->execute('INSERT INTO chaos_redis_lock_rows VALUES (1,0,0)');
        $db->execute('DELETE FROM chaos_redis_lock_control');$db->execute('INSERT INTO chaos_redis_lock_control VALUES (1,0)');
        $files=[];$processes=[];
        try{
            $a=$this->startLockWorker($resource,100,$files,$processes,false);
            $this->waitForNonEmptyFile($a['ready'],8.0);$aReady=$this->decodeArray((string)file_get_contents($a['ready']));
            $this->assertTrue((bool)($aReady['acquired']??false));
            // ری‌استارت از طریق یک لایهٔ قابل‌تزریق انجام می‌شود، نه فراخوانی
            // مستقیم systemd. رفتاری که این تست می‌سنجد (شمارندهٔ fence پایدار
            // و رد شدن fence کهنه) به سازوکار ری‌استارت وابسته نیست؛ فقط به
            // «قطع واقعی سرویس» وابسته است. اگر محیط هیچ سازوکاری نداشته باشد،
            // تست به‌صورت صریح و مستند skip می‌شود — نه سبزِ جعلی.
            $restarter=new \Tests\Support\ServiceRestarter();
            if($restarter->availableStrategy('redis-server')===null){
                $this->markTestSkipped($restarter->skipReason('redis-server'));
            }
            $restart=$restarter->restart('redis-server');
            $this->assertTrue($restart['ok'],'ری‌استارت Redis با استراتژی «'.$restart['strategy'].'» ناموفق بود: '.$restart['output']);
            // Simulate volatile lock loss after restart even when the local Redis
            // daemon happens to persist its dataset across service restarts.
            $redisConfig=config('redis',[]);$this->assertIsArray($redisConfig);$freshRedis=new \Redis();
            $freshRedis->connect(str_value($redisConfig['host']??'127.0.0.1'),int_value($redisConfig['port']??6379),2.0);
            $password=str_value($redisConfig['password']??'');if($password!=='')$freshRedis->auth($password);
            $freshRedis->select(int_value($redisConfig['database']??0));$freshRedis->flushDB();$freshRedis->close();
            $b=$this->startLockWorker($resource,200,$files,$processes,true);
            $this->waitForNonEmptyFile($b['ready'],10.0);
            file_put_contents($b['go'],'go-after-acquire');
            $this->waitForNonEmptyFile($b['result'],10.0);$bExit=proc_close($b['process']);
            $this->assertSame(0,$bExit,(string)file_get_contents($b['log']));
            $bResult=$this->decodeArray((string)file_get_contents($b['result']));
            $this->assertTrue((bool)($bResult['acquired']??false),(json_encode($bResult) ?: ''));
            $this->assertGreaterThan((int)$aReady['fence'],(int)$bResult['fence'],'Fence counter reset after Redis restart.');
            $parentLock=\Core\Application::getInstance()->container->make(\App\Services\DistributedLockService::class);
            $this->assertTrue($parentLock->guardFence($resource,(int)$bResult['fence']));
            $db->execute('UPDATE chaos_redis_lock_rows SET value=?, applied_fence=? WHERE id=1',[200+(int)$bResult['fence'],(int)$bResult['fence']]);
            $aStatus=proc_get_status($a['process']);
            $this->assertTrue((bool)$aStatus['running'],'Original lock holder did not survive until the stale-fence check.');
            $this->assertFalse($parentLock->guardFence($resource,(int)$aReady['fence']),'Stale pre-restart fence passed durable guard.');
            $row=$db->fetch('SELECT value,applied_fence FROM chaos_redis_lock_rows WHERE id=1');
            $this->assertSame(200+(int)$bResult['fence'],(int)$this->requireRow($row)->value);
            $this->assertSame((int)$bResult['fence'],(int)$this->requireRow($row)->applied_fence);
        }finally{
            foreach($processes as $p)if(is_resource($p))proc_terminate($p);
            foreach($files as $f)@unlink($f);
            $db->execute('DELETE FROM distributed_lock_fences WHERE resource_hash=?',[$hash]);
            $db->execute('DROP TABLE IF EXISTS chaos_redis_lock_rows');
            $db->execute('DROP TABLE IF EXISTS chaos_redis_lock_control');
        }
    }

    public function test_provider_partition_between_retry_attempts_recovers_without_false_success(): void
    {
        $port=8094;$signal=tempnam(sys_get_temp_dir(),'provider-partition-signal-');$result=tempnam(sys_get_temp_dir(),'provider-partition-result-');$workerLog=tempnam(sys_get_temp_dir(),'provider-partition-worker-');$serverLog=tempnam(sys_get_temp_dir(),'provider-partition-server-');@unlink($signal);
        exec('sudo ip addr add 8.8.8.8/32 dev lo 2>/dev/null || true');
        $server=$this->startPhpServer($port,'tests/Support/provider_partition_initial_server.php',$serverLog,['CHAOS_PROVIDER_SIGNAL'=>$signal]);
        if (!is_resource($server)) $this->fail('Initial provider server did not start.');
        $worker=null;$recovered=null;
        try{
            usleep(120000);
            $worker=proc_open([PHP_BINARY,base_path('tests/Support/provider_partition_worker.php'),'http://8.8.8.8:'.$port,$result],[0=>['file','/dev/null','r'],1=>['file',$workerLog,'a'],2=>['file',$workerLog,'a']],$pipes,base_path());
            $this->assertIsResource($worker);
            $this->waitForNonEmptyFile($signal,5.0);
            proc_terminate($server,SIGKILL);proc_close($server);$server=null;
            $recovered=$this->startPhpServer($port,'tests/Support/provider_partition_recovered_server.php',$serverLog);
            $exit=proc_close($worker);$worker=null;
            $this->assertSame(0,$exit,(string)file_get_contents($workerLog));
            $payload=$this->decodeArray((string)file_get_contents($result));
            $this->assertTrue((bool)($payload['success']??false),(json_encode($payload) ?: ''));
            $this->assertSame('CHAOS-ZP-AUTHORITY-RECOVERED-000001',$payload['authority']??null);
            $this->assertGreaterThan(0.0,(float)($payload['elapsed']??0));
        }finally{
            foreach([$server,$worker,$recovered] as $p)if(is_resource($p)){proc_terminate($p,SIGKILL);proc_close($p);}
            exec('sudo ip addr del 8.8.8.8/32 dev lo 2>/dev/null || true');
            foreach([$signal,$result,$workerLog,$serverLog] as $f)if(is_string($f))@unlink($f);
        }
    }

    public function test_storage_path_collision_fails_cleanly_and_recovers_after_disk_condition_clears(): void
    {
        $service=\Core\Application::getInstance()->container->make(\App\Services\UploadService::class);
        $name='chaos_blocker_'.bin2hex(random_bytes(5));$filename=str_repeat('a',24).'.png';$blocker=base_path('storage/uploads/'.$name);$child=$blocker.'/'.$filename;
        if(!is_dir(dirname($blocker)))mkdir(dirname($blocker),0750,true);
        file_put_contents($blocker,'sentinel');
        try{
            $this->assertFalse($service->write($name.'/'.$filename,'must-not-persist'));
            $this->assertSame('sentinel',(string)file_get_contents($blocker));
            $this->assertFalse(is_file($child));
            unlink($blocker);mkdir($blocker,0750,true);
            $this->assertTrue($service->write($name.'/'.$filename,'recovered'));
            $this->assertSame('recovered',$service->read($name.'/'.$filename));
        }finally{
            @unlink($child);@rmdir($blocker);if(is_file($blocker))@unlink($blocker);
        }
    }

    public function test_unreachable_database_fails_fast_without_leaking_credentials(): void
    {
        $resultFile = tempnam(sys_get_temp_dir(), 'db-chaos-result-');
        $logFile = tempnam(sys_get_temp_dir(), 'db-chaos-log-');
        if ($resultFile === false || $logFile === false) {
            $this->fail('Unable to allocate DB chaos files.');
        }

        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/db_unavailable_worker.php'),
            '3399',
            $resultFile,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        $exit = proc_close($process);

        try {
            $this->assertSame(0, $exit, (string) file_get_contents($logFile));
            $result = $this->decodeArray((string) file_get_contents($resultFile));
            $this->assertTrue((bool) ($result['failed_as_expected'] ?? false));
            $this->assertLessThan(5.0, (float) ($result['elapsed'] ?? 999));
            $this->assertStringNotContainsString('unreachable_password', (string) ($result['message'] ?? ''));
            $this->assertContains($result['exception'] ?? '', [\PDOException::class, \RuntimeException::class]);
        } finally {
            @unlink($resultFile);
            @unlink($logFile);
        }
    }

    /**
     * @param list<string> $files
     * @param list<resource> $processes
     * @return array{ready:string,go:string,result:string,log:string,process:resource}
     */
    private function startLockWorker(string $resource,int $baseValue,array &$files,array &$processes,bool $goImmediately): array
    {
        $ready=tempnam(sys_get_temp_dir(),'redis-lock-ready-');$go=tempnam(sys_get_temp_dir(),'redis-lock-go-');$result=tempnam(sys_get_temp_dir(),'redis-lock-result-');$log=tempnam(sys_get_temp_dir(),'redis-lock-log-');
        @unlink($ready);if(!$goImmediately)@unlink($go);else file_put_contents($go,'go');
        $process=proc_open([PHP_BINARY,base_path('tests/Support/redis_restart_lock_worker.php'),$resource,$ready,$go,$result,(string)$baseValue],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,base_path());
        $this->assertIsResource($process);$files=array_merge($files,[$ready,$go,$result,$log]);$processes[]=$process;
        return compact('ready','go','result','log','process');
    }

    /** @param array<string,string> $extraEnv @return resource */
    private function startPhpServer(int $port,string $router,string $logFile,array $extraEnv=[]): mixed
    {
        $env=getenv();if(!is_array($env))$env=[];$env=array_merge($env,$extraEnv);
        $process=proc_open([PHP_BINARY,'-S','8.8.8.8:'.$port,base_path($router)],[0=>['file','/dev/null','r'],1=>['file',$logFile,'a'],2=>['file',$logFile,'a']],$pipes,base_path(),$env);
        $this->assertIsResource($process);usleep(100000);return $process;
    }

    /** @return array<int|string, mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function requireRow(?\stdClass $row): \stdClass
    {
        $this->assertInstanceOf(\stdClass::class, $row);
        return $row;
    }

    private function waitForNonEmptyFile(string $file,float $seconds): void
    {
        $deadline=microtime(true)+$seconds;
        while((!is_file($file)||filesize($file)===0)&&microtime(true)<$deadline)usleep(1000);
        $this->assertFileExists($file);
        $this->assertGreaterThan(0,(int)filesize($file),'Chaos worker did not reach barrier: '.$file);
    }
}
