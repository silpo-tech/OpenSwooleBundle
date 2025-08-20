<?php

declare(strict_types=1);

namespace OpenSwooleBundle\EventSubscriber;

use Sentry\ClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SentryPublisherSubscriber implements EventSubscriberInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * @codeCoverageIgnore
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => [
                ['flush', 10],
            ],
        ];
    }

    public function flush(): void
    {
        $this->client->flush();
    }
}
