<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\Messenger;

final class TestMessage
{
    public function __construct(
        public readonly string $message,
    ) {
    }
}
