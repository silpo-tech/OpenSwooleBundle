<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Swoole\Handler;

use OpenSwoole\Http\Server;
use OpenSwoole\Server\Task;
use OpenSwooleBundle\Swoole\Handler\MessengerSendTaskHandler;
use OpenSwooleBundle\Tests\Messenger\TestMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\TraceableMessageBus;

final class MessengerSendTaskHandlerTest extends TestCase
{
    public function testHandle(): void
    {
        $bus = new TraceableMessageBus(new MessageBus());

        $server = $this->createMock(Server::class);
        $task = new Task();
        $task->data = (new Envelope(new TestMessage('test')))->with(new ReceivedStamp('test'));

        $handler = new MessengerSendTaskHandler($bus);
        $handler->handle($server, $task);

        self::assertCount(1, $bus->getDispatchedMessages());
    }

    public function testHandleException(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('test'));

        $bus = new TraceableMessageBus($bus);

        $server = $this->createMock(Server::class);
        $task = new Task();
        $task->data = (new Envelope(new TestMessage('test')))->with(new ReceivedStamp('test'));

        $handler = new MessengerSendTaskHandler($bus, null, new NullLogger());
        $handler->handle($server, $task);

        self::assertCount(1, $bus->getDispatchedMessages());
    }
}
