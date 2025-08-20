<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Command;

use OpenSwooleBundle\Swoole\Server;
use Symfony\Component\Console\Command\Command;

abstract class ServerCommand extends Command
{
    /**
     * @var Server
     */
    protected $server;

    public function __construct(Server $server, ?string $name = null)
    {
        $this->server = $server;
        parent::__construct($name);
    }
}
