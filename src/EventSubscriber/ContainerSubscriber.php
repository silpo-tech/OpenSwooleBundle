<?php

declare(strict_types=1);

namespace OpenSwooleBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

class ContainerSubscriber implements EventSubscriberInterface
{
    /**
     * @var ResetInterface[]
     */
    private array $services;

    public function __construct(iterable $services)
    {
        $services = $services instanceof \Traversable ? iterator_to_array($services) : $services;

        $this->services = array_filter($services, static fn ($service) => $service instanceof ResetInterface);
    }

    /**
     * @codeCoverageIgnore
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => [
                // Must be lower than SentryPublisherSubscriber (priority 4) which flushes Sentry transactions,
                // and lower than Sentry\SentryBundle\EventListener\TracingRequestListener (priority 5) which finishes them.
                // Resetting services (including Sentry hub via RuntimeContextListener::reset()) must happen last.
                ['clear', 1],
            ],
        ];
    }

    public function clear(): void
    {
        foreach ($this->services as $service) {
            $service->reset();
        }
    }
}
