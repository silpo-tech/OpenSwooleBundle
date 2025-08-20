<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\EventSubscriber;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use OpenSwooleBundle\EventSubscriber\DoctrineCleaner;
use PHPUnit\Framework\TestCase;

final class DoctrineCleanerTest extends TestCase
{
    public function testClear(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())->method('clear');

        $closedEntityManager = $this->createMock(EntityManagerInterface::class);
        $closedEntityManager->expects(self::once())->method('isOpen')->willReturn(false);
        $closedEntityManager->expects(self::never())->method('clear');

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->expects(self::once())->method('isOpen')->willReturn(true);
        $documentManager->expects(self::once())->method('clear');

        $closedDocumentManager = $this->createMock(DocumentManager::class);
        $closedDocumentManager->expects(self::once())->method('isOpen')->willReturn(false);
        $closedDocumentManager->expects(self::never())->method('clear');

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())->method('getManagers')->willReturn([
            'default' => $entityManager,
            'document' => $documentManager,
            'closed' => $closedEntityManager,
            'closed_document' => $closedDocumentManager,
        ]);

        $subscriber = new DoctrineCleaner($managerRegistry);
        $subscriber->clear();
    }
}
