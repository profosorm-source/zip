<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\PathResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PathResolverBehaviorTest extends TestCase
{
    private string $root;
    private PathResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phase20-path-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/public', 0700, true);
        $this->resolver = new PathResolver($this->root);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/storage');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_filesystem_roots_are_resolved_from_one_canonical_base(): void
    {
        $this->assertSame($this->root, $this->resolver->base());
        $this->assertSame($this->root . '/app/config.php', $this->resolver->base('app/config.php'));
        $this->assertSame($this->root . '/storage/uploads/image.jpg', $this->resolver->storage('uploads/image.jpg'));
        $this->assertSame($this->root . '/public/assets/app.css', $this->resolver->public('assets/app.css'));
    }

    /**
     * @dataProvider unsafeRelativePathProvider
     */
    public function test_unsafe_relative_path_is_rejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->storage($path);
    }

    /** @return array<string, array{0: string}> */
    public function unsafeRelativePathProvider(): array
    {
        return [
            'parent traversal' => ['uploads/../secret'],
            'unix absolute' => ['/etc/passwd'],
            'windows absolute' => ['C:\\Windows\\system.ini'],
            'null byte' => ["uploads/file.jpg\0.php"],
        ];
    }

    public function test_missing_base_directory_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PathResolver($this->root . '/missing');
    }
}
