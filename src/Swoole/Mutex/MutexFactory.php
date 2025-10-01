<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Mutex;

use OpenSwooleBundle\Swoole\CoroutineHelper;

final class MutexFactory
{
    public static function createByCoroutineContext(): MutexInterface
    {
        if (CoroutineHelper::inCoroutine()) {
            return new ChannelMutex();
        }

        return new NoopMutex();
    }

    public static function createBetweenProcesses(): AtomicMutex
    {
        return new AtomicMutex();
    }
}
