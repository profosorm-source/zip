<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Adapters\Notification\FcmNotificationAdapter;
use App\Services\Notification\Channels\FcmChannel;
use App\Services\Notification\FcmService;
use Core\Application;
use Core\Database;
use PHPUnit\Framework\TestCase;

final class FcmRuntimeContractTest extends TestCase
{
    private Database $db;
    /** @var list<\stdClass> */
    private array $originalDevices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Application::getInstance()->container->make(Database::class);
        $this->originalDevices = $this->db->fetchAll('SELECT * FROM user_devices WHERE user_id=1');
        $this->db->query('DELETE FROM user_devices WHERE user_id=1');
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM user_devices WHERE user_id=1');
        foreach ($this->originalDevices as $row) {
            $this->db->query(
                'INSERT INTO user_devices (id,user_id,fcm_token,platform,last_activity,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                [$row->id,$row->user_id,$row->fcm_token,$row->platform,$row->last_activity,$row->created_at,$row->updated_at]
            );
        }
        parent::tearDown();
    }

    public function test_container_resolves_without_cycle_and_token_upsert_is_validated_and_unique(): void
    {
        $container = Application::getInstance()->container;
        $adapter = $container->make(FcmNotificationAdapter::class);
        $service = $container->make(FcmService::class);
        $channel = $container->make(FcmChannel::class);
        $this->assertInstanceOf(FcmNotificationAdapter::class, $adapter);
        $this->assertInstanceOf(FcmService::class, $service);
        $this->assertInstanceOf(FcmChannel::class, $channel);

        $this->assertSame(1, (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='user_devices' AND index_name='uq_user_devices_user_platform'"
        ));
        $tokenOne = 'fcm-runtime-token-' . str_repeat('a', 64);
        $tokenTwo = 'fcm-runtime-token-' . str_repeat('b', 64);
        $this->assertTrue($service->saveUserToken(1, $tokenOne, 'web'));
        $this->assertSame(1, (int)$this->db->fetchColumn("SELECT COUNT(*) FROM user_devices WHERE user_id=1 AND platform='web'"));
        $this->assertSame($tokenOne, $service->getUserToken(1, 'web'));

        $this->assertTrue($service->saveUserToken(1, $tokenTwo, 'web'));
        $this->assertSame(1, (int)$this->db->fetchColumn("SELECT COUNT(*) FROM user_devices WHERE user_id=1 AND platform='web'"));
        $this->assertSame($tokenTwo, (string)$this->db->fetchColumn("SELECT fcm_token FROM user_devices WHERE user_id=1 AND platform='web'"));
        $this->assertSame($tokenTwo, $service->getUserToken(1, 'web'));

        $this->expectOutputRegex('/(?s)fcm\.invalid_token_registration.*cache\.fcm_token_invalidated/');
        $this->assertFalse($service->saveUserToken(1, 'short', 'web'));
        $this->assertFalse($service->saveUserToken(1, $tokenOne, 'desktop'));
        $this->assertFalse($service->saveUserToken(0, $tokenOne, 'web'));
        $this->assertSame(1, (int)$this->db->fetchColumn('SELECT COUNT(*) FROM user_devices WHERE user_id=1'));

        $service->removeUserToken(1, 'web');
        $this->assertSame(0, (int)$this->db->fetchColumn("SELECT COUNT(*) FROM user_devices WHERE user_id=1 AND platform='web'"));
        $this->assertNull($service->getUserToken(1, 'web'));
    }
}
