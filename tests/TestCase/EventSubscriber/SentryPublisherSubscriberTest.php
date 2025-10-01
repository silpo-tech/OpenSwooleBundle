<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\EventSubscriber;

use OpenSwooleBundle\EventSubscriber\SentryPublisherSubscriber;
use OpenSwooleBundle\Tests\Kernel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

final class SentryPublisherSubscriberTest extends TestCase
{
    public function testFlush(): void
    {
        /** @var ClientInterface&MockObject */
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('flush');

        $subscriber = new SentryPublisherSubscriber($client);

        $event = new TerminateEvent(
            new Kernel(),
            new Request(),
            new Response(),
        );

        $subscriber->flush();
    }
}
