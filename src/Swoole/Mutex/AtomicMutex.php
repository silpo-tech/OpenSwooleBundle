<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Mutex;

use OpenSwoole\Atomic;

final class AtomicMutex implements MutexInterface
{
    private Atomic $atomic;

    public function __construct()
    {
        $this->atomic = new Atomic(0);
    }

    public function lock(): void
    {
        $this->atomic->wait();
    }

    public function unlock(): void
    {
        $this->atomic->wakeup();
    }
}
