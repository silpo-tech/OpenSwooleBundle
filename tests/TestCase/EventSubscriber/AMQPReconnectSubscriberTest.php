<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\EventSubscriber;

use OpenSwooleBundle\EventSubscriber\AMQPReconnectSubscriber;
use OpenSwooleBundle\Swoole\Server;
use OpenSwooleBundle\Tests\Kernel;
use PhpAmqpLib\Exception\AMQPBasicCancelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AMQPReconnectSubscriberTest extends TestCase
{
    #[DataProvider('dataProviderOnKernelException')]
    public function testOnKernelException(\Throwable $throwable, bool $expected): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::exactly($expected ? 1 : 0))->method('stopWorker');

        $subscriber = new AMQPReconnectSubscriber($server);

        $event = new ExceptionEvent(
            new Kernel(),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $subscriber->onKernelException($event);
    }

    public static function dataProviderOnKernelException(): iterable
    {
        yield 'RuntimeException' => [
            new \RuntimeException(),
            false,
        ];

        yield 'InvalidArgumentException' => [
            new \InvalidArgumentException(),
            false,
        ];

        yield 'AMQPBasicCancelException' => [
            new AMQPBasicCancelException('test'),
            true,
        ];

        yield 'AMQPTimeoutException' => [
            new AMQPTimeoutException('test'),
            true,
        ];
    }
}
