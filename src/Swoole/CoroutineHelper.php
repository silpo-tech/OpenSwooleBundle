<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole;

use OpenSwoole\Coroutine;

final class CoroutineHelper
{
    public static function openswooleEnabled(): bool
    {
        return class_exists(Coroutine::class);
    }

    public static function inCoroutine(): bool
    {
        return self::openswooleEnabled() && -1 !== Coroutine::getCid();
    }
}
