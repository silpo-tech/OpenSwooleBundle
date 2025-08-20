<?php

declare(strict_types=1);

namespace OpenSwooleBundle\EventSubscriber;

use OpenSwooleBundle\Swoole\Server;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ExceptionSubscriber.
 */
final readonly class AMQPReconnectSubscriber implements EventSubscriberInterface
{
    public function __construct(private Server $server)
    {
    }

    /**
     * @codeCoverageIgnore
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 255],
        ];
    }

    /**
     * @throws \Exception
     */
    public function onKernelException(ExceptionEvent $event)
    {
        if ($event->getThrowable() instanceof AMQPExceptionInterface) {
            $this->server->stopWorker();
        }
    }
}
