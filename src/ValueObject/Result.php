<?php

declare(strict_types=1);

namespace OpenSwooleBundle\ValueObject;

/**
 * @template T
 */
final class Result
{
    /**
     * @param T $value
     */
    public function __construct(public readonly mixed $value, public readonly \Throwable|null $throwable = null)
    {
    }

    public function isOk(): bool
    {
        return null === $this->throwable;
    }

    public static function fromThrowable(\Throwable $throwable): self
    {
        return new self(null, $throwable);
    }

    public static function fromValue(mixed $value): self
    {
        return new self($value);
    }
}
