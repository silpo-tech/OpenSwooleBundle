<?php

declare(strict_types=1);

namespace OpenSwooleBundle\EventSubscriber;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listen for the request finish event and clear object manager.
 */
final readonly class DoctrineCleaner implements EventSubscriberInterface
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
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
        foreach ($this->registry->getManagers() as $name => $manager) {
            $open = false;

            if ($manager instanceof EntityManagerInterface || $manager instanceof DocumentManager) {
                $open = $manager->isOpen();
            }

            $open
                ? $manager->clear()
                : $this->registry->resetManager($name);
        }
    }
}
