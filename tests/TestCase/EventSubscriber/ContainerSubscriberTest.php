<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\EventSubscriber;

use OpenSwooleBundle\EventSubscriber\ContainerSubscriber;
use OpenSwooleBundle\Tests\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;

final class ContainerSubscriberTest extends TestCase
{
    public function testClear(): void
    {
        $resetableService = $this->createMock(ResetInterface::class);
        $resetableService->expects(self::once())->method('reset');
        $services = [
            $resetableService,
            new \stdClass(),
            'string',
            123,
            [],
        ];

        $event = new TerminateEvent(
            new Kernel(),
            new Request(),
            new Response(),
        );

        $subscriber = new ContainerSubscriber($services);

        $subscriber->clear();
    }
}
