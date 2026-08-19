<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Settings\AppSettings;
use App\Services\UploadService;
use Core\Database;
use Core\PathResolver;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class UploadServicePathIntegrationTest extends TestCase
{
    private string $root;
    private UploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phase20-upload-paths-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/uploads/site-images', 0700, true);
        mkdir($this->root . '/public/uploads/avatars', 0700, true);
        mkdir($this->root . '/storage/captcha', 0700, true);

        $this->service = new UploadService(
            m::mock(Database::class),
            m::mock(AppSettings::class),
            new PathResolver($this->root)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/storage/uploads/site-images/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->root . '/public/uploads/avatars/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/storage/uploads/site-images');
        @rmdir($this->root . '/storage/uploads');
        @rmdir($this->root . '/storage/captcha');
        @rmdir($this->root . '/storage');
        @rmdir($this->root . '/public/uploads/avatars');
        @rmdir($this->root . '/public/uploads');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
        m::close();
        parent::tearDown();
    }

    public function test_private_setting_image_is_deleted_from_canonical_storage_root(): void
    {
        $relativePath = 'site-images/' . str_repeat('a', 24) . '.jpg';
        $fullPath = $this->root . '/storage/uploads/' . $relativePath;
        file_put_contents($fullPath, 'private-image');

        $this->assertTrue($this->service->delete($relativePath));
        $this->assertFileNotExists($fullPath);
    }

    public function test_public_avatar_is_deleted_from_canonical_public_root(): void
    {
        $relativePath = 'avatars/' . str_repeat('b', 24) . '.png';
        $fullPath = $this->root . '/public/uploads/' . $relativePath;
        file_put_contents($fullPath, 'public-image');

        $this->assertTrue($this->service->delete($relativePath));
        $this->assertFileNotExists($fullPath);
    }

    public function test_traversal_path_cannot_delete_file_outside_upload_roots(): void
    {
        $sentinel = $this->root . '/storage/sentinel.txt';
        file_put_contents($sentinel, 'keep');

        $this->assertFalse($this->service->delete('site-images/../../sentinel.txt'));
        $this->assertFileExists($sentinel);
        $this->assertSame('keep', file_get_contents($sentinel));
        @unlink($sentinel);
    }
}
