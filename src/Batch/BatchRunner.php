<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Batch;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Scheduler;
use OpenSwoole\Runtime;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerEnded;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemEndedSuccessfully;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemEndedWithException;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemStarted;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerStarted;
use OpenSwooleBundle\Exception\BatchRunException;
use OpenSwooleBundle\Exception\BatchRunnerStartedException;
use OpenSwooleBundle\Swoole\CoroutineHelper;
use OpenSwooleBundle\ValueObject\Result;
use Psr\EventDispatcher\EventDispatcherInterface;

final class BatchRunner
{
    private static ?bool $forceSequential = null;

    private int $prevHookFlags;
    private int $callablesCount;
    private array $results = [];
    private array $throwables = [];
    private bool $started = false;
    private bool $sequential;

    /**
     * @param array<array-key, callable> $callables
     */
    public function __construct(
        private Channel $resultsChannel,
        private array $callables,
        private int $hookFlags = Runtime::HOOK_ALL,
        private bool $setRuntimeHooks = true,
        private EventDispatcherInterface|null $eventDispatcher = null,
    ) {
        $this->callablesCount = count($callables);
        $this->prevHookFlags = Runtime::getHookFlags();
        $this->sequential = self::shouldRunSequentially();
    }

    /**
     * Force sequential mode on/off globally. Pass null to restore auto-detection.
     */
    public static function forceSequential(?bool $force): void
    {
        self::$forceSequential = $force;
    }

    private static function shouldRunSequentially(): bool
    {
        if (self::$forceSequential !== null) {
            return self::$forceSequential;
        }

        return getenv('OPENSWOOLE_SEQUENTIAL') === '1'
            || ($_ENV['OPENSWOOLE_SEQUENTIAL'] ?? '') === '1'
            || getenv('APP_ENV') === 'test'
            || ($_ENV['APP_ENV'] ?? '') === 'test';
    }

    /**
     * @param array<array-key, callable> $callables
     */
    public static function fromCallables(array $callables): self
    {
        return new self(new Channel(count($callables)), $callables);
    }

    /**
     * Each item of arguments array must contain array of arguments to callable.
     *
     * @see OpenSwooleBundle\Tests\TestCase\BatchRunner\BatchRunnerTest::testConcurrently()
     */
    public static function concurrently(callable $callable, array $arguments): self
    {
        $callables = [];

        foreach ($arguments as $key => $callableArguments) {
            $callables[$key] = static fn () => $callable(...$callableArguments);
        }

        return self::fromCallables($callables);
    }

    public function withDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->ensureNotStarted();
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    public function withHookFlags(int $hookFlags): self
    {
        $this->ensureNotStarted();
        $this->hookFlags = $hookFlags;

        return $this;
    }

    public function withSetRuntimeHooks(bool $set): self
    {
        $this->ensureNotStarted();
        $this->setRuntimeHooks = $set;

        return $this;
    }

    public function withSequential(bool $sequential = true): self
    {
        $this->ensureNotStarted();
        $this->sequential = $sequential;

        return $this;
    }

    /**
     * Blocks until all callables have been finished.
     * Throws BatchRunException if any of the callables threw an exception.
     *
     * @throws BatchRunException
     */
    public function runAll(): array
    {
        $this->start();

        if ([] !== $this->throwables) {
            throw BatchRunException::fromThrowables($this->throwables);
        }

        return $this->results;
    }

    /**
     * Blocks until all callables have been finished.
     * Returns results and throwables.
     *
     * @return array{array<array-key, mixed>, array<array-key, \Throwable>}
     */
    public function runAny(): array
    {
        $this->start();

        return [$this->results, $this->throwables];
    }

    private function start(): void
    {
        $this->ensureNotStarted();
        $this->eventDispatcher?->dispatch(new BatchRunnerStarted($this));
        $this->started = true;

        if ($this->sequential) {
            $this->startSequentially();

            return;
        }

        $previousErrorHandler = $this->captureCurrentErrorHandler();
        set_error_handler($previousErrorHandler ?? static fn () => false);
        $this->setHookFlags();

        try {
            if (CoroutineHelper::inCoroutine()) {
                $this->startWaitGroup();

                return;
            }

            $this->startScheduler();
        } finally {
            $this->setPrevHookFlags();
            restore_error_handler();
            $this->eventDispatcher?->dispatch(new BatchRunnerEnded($this));
        }
    }

    private function startSequentially(): void
    {
        foreach ($this->callables as $key => $callable) {
            $this->eventDispatcher?->dispatch(new BatchRunnerItemStarted($this, (string) $key));

            try {
                $this->results[$key] = $callable();
                $this->eventDispatcher?->dispatch(new BatchRunnerItemEndedSuccessfully($this, (string) $key));
            } catch (\Throwable $e) {
                $this->throwables[$key] = $e;
                $this->eventDispatcher?->dispatch(new BatchRunnerItemEndedWithException($this, (string) $key, $e));
            }
        }

        $this->eventDispatcher?->dispatch(new BatchRunnerEnded($this));
    }

    private function startWaitGroup(): void
    {
        foreach ($this->callables as $key => $callable) {
            Coroutine::create($this->wrapCallable($this->resultsChannel, $key, $callable));
        }

        $this->waitResults()();
    }

    private function startScheduler(): void
    {
        $scheduler = new Scheduler();
        foreach ($this->callables as $key => $callable) {
            $scheduler->add($this->wrapCallable($this->resultsChannel, $key, $callable));
        }
        $scheduler->add($this->waitResults());
        $scheduler->start();
    }

    private function waitResults(): callable
    {
        return function (): void {
            while ($this->callablesCount > 0) {
                /** @var Result $result */
                [$key, $result] = $this->resultsChannel->pop(-1);

                if (!$result->isOk()) {
                    $this->throwables[$key] = $result->throwable;
                } else {
                    $this->results[$key] = $result->value;
                }
                --$this->callablesCount;
            }
        };
    }

    /**
     * @param array-key $key
     */
    private function wrapCallable(Channel $resultChannel, string|int $key, callable $callable): callable
    {
        $eventDispatcher = $this->eventDispatcher;
        $batchRunner = $this;

        return static function () use ($resultChannel, $key, $callable, $eventDispatcher, $batchRunner): void {
            $eventDispatcher?->dispatch(new BatchRunnerItemStarted($batchRunner, (string) $key));

            try {
                $result = Result::fromValue($callable());

                $eventDispatcher?->dispatch(
                    new BatchRunnerItemEndedSuccessfully($batchRunner, (string) $key),
                );
            } catch (\Throwable $e) {
                $result = Result::fromThrowable($e);

                $eventDispatcher?->dispatch(
                    new BatchRunnerItemEndedWithException($batchRunner, (string) $key, $e),
                );
            }
            $resultChannel->push([$key, $result]);
        };
    }

    private function setHookFlags(): void
    {
        if ($this->setRuntimeHooks) {
            Runtime::setHookFlags($this->hookFlags);
        }
    }

    private function setPrevHookFlags(): void
    {
        if ($this->setRuntimeHooks) {
            Runtime::setHookFlags($this->prevHookFlags);
        }
    }

    /**
     * Captures the currently active error handler without replacing it.
     *
     * PHPUnit registers its own error handler that traverses the call stack via
     * debug_backtrace() to find the TestCase object. Inside an OpenSwoole coroutine
     * the call stack no longer contains the TestCase, which causes
     * NoTestCaseObjectOnCallStackException. By temporarily replacing the error
     * handler with the one active before PHPUnit's, we avoid the crash.
     */
    private function captureCurrentErrorHandler(): callable|null
    {
        $handler = set_error_handler(static fn () => false);
        restore_error_handler();

        return $handler;
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new BatchRunnerStartedException();
        }
    }
}
