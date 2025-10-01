<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\Messenger;

use Psr\Log\LoggerInterface;

final readonly class TestMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private int $micros = 0,
    ) {
    }

    public function __invoke(TestMessage $message): void
    {
        if ($this->micros > 0) {
            usleep($this->micros);
        }

        $this->logger->info('Handled message: ' . $message->message);
    }
}
