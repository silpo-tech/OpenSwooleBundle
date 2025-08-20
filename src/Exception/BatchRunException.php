<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Exception;

use RuntimeException;
use Throwable;

final class BatchRunException extends RuntimeException
{
    /**
     * @param array<array-key, Throwable> $throwables
     */
    private function __construct(
        public readonly array $throwables,
    ) {
        assert(!empty($throwables), '$throwables are empty.');
        $first = $throwables[array_key_first($throwables)];
        $message = sprintf(
            'Batch run resulted with %d exception(s). The first is: %s',
            count($throwables),
            $first->getMessage(),
        );
        parent::__construct($message, $first->getCode(), $first->getPrevious());
    }

    /**
     * @param non-empty-array<array-key, Throwable> $throwables
     */
    public static function fromThrowables(array $throwables): self
    {
        return new self($throwables);
    }

    /**
     * @return non-empty-array<array-key,Throwable>
     */
    public function getOriginalThrowables(): array
    {
        return $this->throwables;
    }
}
