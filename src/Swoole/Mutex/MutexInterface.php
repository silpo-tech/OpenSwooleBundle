<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Mutex;

interface MutexInterface
{
    public function lock(): void;

    public function unlock(): void;
}
