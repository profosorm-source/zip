<?php

declare(strict_types=1);

namespace Tests\Support;

use Mockery;
use Mockery\MockInterface;

trait CreatesTypedMockeryMocks
{
    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T&MockInterface
     */
    private function lenientMock(string $className): object
    {
        /** @var T&MockInterface $mock */
        $mock = Mockery::mock($className);
        $mock->shouldIgnoreMissing();
        return $mock;
    }
}
