<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole\Handler;

use OpenSwoole\Server;

interface TaskFinishHandlerInterface
{
    public function handle(Server $server, int $taskId, mixed $data): void;
}
