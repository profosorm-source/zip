<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Services\Auth\TwoFactorService;
use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResetsConfiguredRedis;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TwoFactorRuntimeTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private TwoFactorService $service;
    private int $userId = 1;
    private \stdClass $original;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputBufferLevel = ob_get_level();
        ob_start();
        $container = Application::getInstance()->container;
        \App\Providers\AppServiceProvider::register($container);
        $this->db = $container->make(Database::class);
        $this->service = $container->make(TwoFactorService::class);
        $original = $this->db->fetch('SELECT two_factor_enabled,two_factor_secret,last_2fa_timeslice,remember_token,remember_expires_at,status FROM users WHERE id=?', [$this->userId]);
        $this->assertInstanceOf(\stdClass::class, $original);
        $this->original = $original;
        config_set('app.key', $this->runtimeApplicationKey());
        $this->db->query('DELETE FROM two_factor_codes WHERE user_id=?', [$this->userId]);
        $this->db->query("UPDATE users SET two_factor_enabled=0,two_factor_secret=NULL,last_2fa_timeslice=NULL,status='active',remember_token='pre-2fa-token',remember_expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=?", [$this->userId]);
        $this->flushRedis();
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM two_factor_codes WHERE user_id=?', [$this->userId]);
        $this->db->query(
            'UPDATE users SET two_factor_enabled=?,two_factor_secret=?,last_2fa_timeslice=?,remember_token=?,remember_expires_at=?,status=? WHERE id=?',
            [$this->original->two_factor_enabled,$this->original->two_factor_secret,$this->original->last_2fa_timeslice,$this->original->remember_token,$this->original->remember_expires_at,$this->original->status,$this->userId]
        );
        config_set('app.key', 'testing-app-key-32-characters-long!!');
        $this->flushRedis();
        while (ob_get_level() > $this->outputBufferLevel) ob_end_clean();
        parent::tearDown();
    }

    public function test_enable_totp_replay_and_one_time_recovery_code_contract(): void
    {
        $plainSecret = $this->service->generateSecret();
        $encryptedSecret = $this->service->encryptSecret($plainSecret);
        $this->assertNotSame($plainSecret, $encryptedSecret);
        $this->db->query('UPDATE users SET two_factor_secret=? WHERE id=?', [$encryptedSecret, $this->userId]);

        $code = $this->totp($plainSecret, (int)floor(time()/30));
        $enabled = $this->service->enable($this->userId, $code);
        $this->assertTrue((bool)($enabled['success'] ?? false), (json_encode($enabled) ?: ''));
        $recoveryCodes = $enabled['recovery_codes'] ?? null;
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
        $this->assertSame(1, (int)$this->db->fetchColumn('SELECT two_factor_enabled FROM users WHERE id=?', [$this->userId]));
        $this->assertNull($this->db->fetchColumn('SELECT remember_token FROM users WHERE id=?', [$this->userId]));
        $this->assertSame(8, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM two_factor_codes WHERE user_id=? AND used=0', [$this->userId]));

        // enable() consumed the current timeslice; the same TOTP must never replay.
        $this->assertFalse($this->service->verifyCode($encryptedSecret, $code, $this->userId));

        $recovery = str_value($recoveryCodes[0] ?? '');
        $this->assertRegExp('/^[A-F0-9]{24}$/', $recovery);
        $this->assertTrue($this->service->verifyCode($encryptedSecret, $recovery, $this->userId));
        $this->assertFalse($this->service->verifyCode($encryptedSecret, $recovery, $this->userId));
        $this->assertSame(7, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM two_factor_codes WHERE user_id=? AND used=0', [$this->userId]));
    }

    private function runtimeApplicationKey(): string
    {
        $env = parse_ini_file(base_path('.env'), false, INI_SCANNER_RAW);
        $key = is_array($env) && is_string($env['APP_KEY'] ?? null) ? trim($env['APP_KEY'], " \t\n\r\0\x0B\"'") : '';
        if (strlen($key) < 32) throw new \RuntimeException('Runtime APP_KEY missing');
        return $key;
    }

    private function totp(string $base32, int $slice): string
    {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
        foreach(str_split(strtoupper($base32)) as $char){$pos=strpos($alphabet,$char);if($pos===false)throw new \RuntimeException('bad base32');$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);}
        $secret='';for($i=0;$i+8<=strlen($bits);$i+=8)$secret.=chr(int_value(bindec(substr($bits,$i,8))));
        $counter=pack('N2',0,$slice);$hash=hash_hmac('sha1',$counter,$secret,true);$offset=ord($hash[19])&0x0f;
        $value=((ord($hash[$offset])&0x7f)<<24)|((ord($hash[$offset+1])&0xff)<<16)|((ord($hash[$offset+2])&0xff)<<8)|(ord($hash[$offset+3])&0xff);
        return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
    }

    private function flushRedis(): void
    {
        $this->resetConfiguredRedis();
    }
}
