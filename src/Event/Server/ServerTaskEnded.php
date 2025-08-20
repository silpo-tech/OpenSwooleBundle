<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Event\Server;

use OpenSwoole\Server\Task;
use OpenSwooleBundle\Event\OpenSwooleEvent;

final class ServerTaskEnded extends OpenSwooleEvent
{
    public function __construct(
        private Task $task,
    ) {
    }

    public function getTask(): Task
    {
        return $this->task;
    }
}
