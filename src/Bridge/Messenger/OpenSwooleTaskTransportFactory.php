<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Bridge\Messenger;

use OpenSwooleBundle\Swoole\Server;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class OpenSwooleTaskTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly Server $server,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new OpenSwooleTaskTransport($this->server, $this->messageBus);
    }

    public function supports(string $dsn, array $options): bool
    {
        return 0 === mb_strpos($dsn, 'openswoole://server-task');
    }
}
