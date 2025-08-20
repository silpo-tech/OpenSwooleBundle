<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Swoole;

use OpenSwooleBundle\Swoole\WorkerMutexPool;
use PHPUnit\Framework\TestCase;

final class WorkerMutexPoolTest extends TestCase
{
    public function testGetOrCreate(): void
    {
        $pool = new WorkerMutexPool();

        $mutex = $pool->getOrCreate(1);

        self::assertSame($mutex, $pool->getOrCreate(1));
    }

    public function testCreate(): void
    {
        $pool = new WorkerMutexPool();

        $mutex = $pool->create(1);

        self::assertSame($mutex, $pool->getOrCreate(1));
    }
}
