<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Logger;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;
use Monolog\ResettableInterface;
use OpenSwooleBundle\Swoole\CoroutineHelper;
use OpenSwooleBundle\Swoole\Mutex\MutexFactory;
use OpenSwooleBundle\Swoole\Mutex\MutexInterface;

final class LoggerMutexDecorator implements HandlerInterface, ProcessableHandlerInterface, ResettableInterface, FormattableHandlerInterface
{
    private MutexInterface $mutex;

    public function __construct(
        private readonly HandlerInterface&ProcessableHandlerInterface&ResettableInterface&FormattableHandlerInterface $handler,
    ) {
    }

    /**
     * @codeCoverageIgnore
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->handler->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        return $this->once(fn () => $this->handler->handle($record));
    }

    public function handleBatch(array $records): void
    {
        $this->once(fn () => $this->handler->handleBatch($records));
    }

    public function close(): void
    {
        $this->once(fn () => $this->handler->close());
    }

    /**
     * @codeCoverageIgnore
     */
    public function pushProcessor(callable $callback): HandlerInterface
    {
        return $this->handler->pushProcessor($callback);
    }

    /**
     * @codeCoverageIgnore
     */
    public function popProcessor(): callable
    {
        return $this->handler->popProcessor();
    }

    public function reset(): void
    {
        $this->once(fn () => $this->handler->reset());
    }

    /**
     * @codeCoverageIgnore
     */
    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        $this->handler->setFormatter($formatter);

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getFormatter(): FormatterInterface
    {
        return $this->handler->getFormatter();
    }

    private function getMutex(): MutexInterface|null
    {
        if (!CoroutineHelper::inCoroutine()) {
            return null;
        }

        return $this->mutex ??= MutexFactory::createByCoroutineContext();
    }

    private function once(callable $cb): mixed
    {
        $mutex = $this->getMutex();
        if ($mutex === null) {
            return $cb();
        }

        $this->mutex->lock();
        try {
            $res = $cb();
        } finally {
            $this->mutex->unlock();
        }

        return $res;
    }
}
