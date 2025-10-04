<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Handler;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use OpenSwoole\Server;
use OpenSwoole\Server\Task;
use OpenSwooleBundle\Event\Server\ServerTaskEnded;
use OpenSwooleBundle\Event\Server\ServerTaskStarted;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerSendTaskHandler implements TaskHandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private MessageBusInterface $messenger,
        private EventDispatcherInterface|null $eventDispatcher = null,
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? self::createPlainLogger();
    }

    public function handle(Server $server, Task $task): void
    {
        $this->eventDispatcher?->dispatch(new ServerTaskStarted($task));
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
        } finally {
            $this->eventDispatcher?->dispatch(new ServerTaskEnded($task));
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
