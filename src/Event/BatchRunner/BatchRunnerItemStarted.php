<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Event\BatchRunner;

use OpenSwooleBundle\Batch\BatchRunner;
use OpenSwooleBundle\Event\OpenSwooleEvent;

final class BatchRunnerItemStarted extends OpenSwooleEvent
{
    public function __construct(
        public readonly BatchRunner $batchRunner,
        public readonly string $key,
    ) {
    }
}
