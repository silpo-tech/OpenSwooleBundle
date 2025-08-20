<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Event\BatchRunner;

use OpenSwooleBundle\Batch\BatchRunner;

final class BatchRunnerItemEndedSuccessfully extends BatchRunnerItemEnded
{
    public function __construct(
        BatchRunner $batchRunner,
        string $key,
    ) {
        parent::__construct($batchRunner, $key, true);
    }
}
