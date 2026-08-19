<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * @group architecture
 */
final class ViewDataBoundaryArchitectureTest extends TestCase
{
    public function test_views_do_not_coerce_controller_or_service_dtos(): void
    {
        $files = glob(base_path('views/**/*.php'), GLOB_BRACE) ?: [];
        // glob ** is not recursive on every libc; use an iterator as the source of truth.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('views'), \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $this->assertNotEmpty($files);

        $forbidden = [
            '/\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*\((?:object|array)\)\s*\$[A-Za-z_][A-Za-z0-9_]*/',
            '/is_object\s*\([^)]*\).*?\(array\)/s',
            '/is_array\s*\([^)]*\).*?\(object\)/s',
        ];
        $violations = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                $violations[] = $file . ': unreadable';
                continue;
            }
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $offset = int_value($match[0][1]);
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $violations[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ':' . $line;
                }
            }
        }

        $this->assertSame([], $violations, "View-data coercion belongs in a typed Controller/Presenter boundary, never in a View:\n" . implode("\n", $violations));
    }
}
