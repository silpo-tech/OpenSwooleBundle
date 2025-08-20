<?php

declare(strict_types=1);

namespace OpenSwooleBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

class ContainerSubscriber implements EventSubscriberInterface
{
    /**
     * @var array|iterable|ResetInterface[]
     */
    private array $services;

    public function __construct(iterable $services)
    {
        $this->services = $services instanceof \Traversable ? iterator_to_array($services) : $services;
    }

    /**
     * @codeCoverageIgnore
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => [
                ['clear', 10],
            ],
        ];
    }

    public function clear(): void
    {
        foreach ($this->services as $service) {
            if ($service instanceof ResetInterface) {
                $service->reset();
            }
        }
    }
}
