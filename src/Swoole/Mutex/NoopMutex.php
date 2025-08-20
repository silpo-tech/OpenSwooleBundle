<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Mutex;

final class NoopMutex implements MutexInterface
{
    public function lock(): void
    {
    }

    public function unlock(): void
    {
    }
}
