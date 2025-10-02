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
    private int $prevHookFlags;
    private int $callablesCount;
    private array $results = [];
    private array $throwables = [];
    private bool $started = false;

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

        $this->setHookFlags();

        if (CoroutineHelper::inCoroutine()) {
            $this->startWaitGroup();
        } else {
            $this->startScheduler();
        }

        $this->setPrevHookFlags();

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

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new BatchRunnerStartedException();
        }
    }
}
