<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Handler;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use OpenSwoole\Server;
use OpenSwoole\Server\Task;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerSendTaskHandler implements TaskHandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private MessageBusInterface $messenger,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? self::createPlainLogger();
    }

    public function handle(Server $server, Task $task): void
    {
        try {
            $this->messenger->dispatch($task->data);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    "Failed to dispatch task #%d.\nReason: %s.\nTrace: %s.",
                    $task->id,
                    $e->getMessage(),
                    $e->getTraceAsString(),
                ),
            );
        }
    }

    private static function createPlainLogger(): LoggerInterface
    {
        return new Logger(
            'messenger_send_task_handler',
            [new StreamHandler('php://stdout')],
            [new PsrLogMessageProcessor()],
        );
    }
}
