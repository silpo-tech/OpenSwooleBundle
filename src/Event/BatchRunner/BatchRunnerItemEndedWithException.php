<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Event\BatchRunner;

use OpenSwooleBundle\Batch\BatchRunner;

final class BatchRunnerItemEndedWithException extends BatchRunnerItemEnded
{
    public function __construct(
        BatchRunner $batchRunner,
        string $key,
        public readonly \Throwable $exception,
    ) {
        parent::__construct($batchRunner, $key, false);
    }
}
