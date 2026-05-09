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
    /** @var list<array{int, string, string, int}> */
    private array $collectedErrors = [];

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

        $errorHandlerReplaced = $this->replacePhpUnitErrorHandler();
        $this->setHookFlags();

        try {
            if (CoroutineHelper::inCoroutine()) {
                $this->startWaitGroup();

                return;
            }

            $this->startScheduler();
        } finally {
            $this->setPrevHookFlags();

            if ($errorHandlerReplaced) {
                restore_error_handler();
                $this->replayCollectedErrors();
            }

            $this->eventDispatcher?->dispatch(new BatchRunnerEnded($this));
        }
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
     * If PHPUnit's error handler is active, replaces it with a collecting handler.
     * PHPUnit's handler traverses the call stack to find the TestCase object,
     * which does not exist inside coroutines. In production this is a no-op.
     */
    private function replacePhpUnitErrorHandler(): bool
    {
        // Узнаём текущий error handler: ставим временный, получаем предыдущий
        $current = set_error_handler(static fn () => false);
        // Сразу убираем временный — всё как было
        restore_error_handler();

        // Если текущий handler — не PHPUnit'овский, ничего не делаем.
        // В продакшене тут выходим — поведение не меняется.
        if (!$current instanceof \PHPUnit\Runner\ErrorHandler) {
            return false;
        }

        $collectedErrors = &$this->collectedErrors;

        // Ставим свой handler вместо PHPUnit'овского.
        // Он не лезет в стек — просто складывает ошибки в массив.
        // return true означает "ошибка обработана, PHP не делай ничего дальше".
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$collectedErrors): bool {
            $collectedErrors[] = [$errno, $errstr, $errfile, $errline];

            return true;
        });

        return true;
    }

    /**
     * Replays collected errors after PHPUnit's handler is restored,
     * so PHPUnit can see them with TestCase on the call stack.
     */
    private function replayCollectedErrors(): void
    {
        $errors = $this->collectedErrors;
        $this->collectedErrors = [];

        foreach ($errors as [$errno, $errstr]) {
            // trigger_error() принимает только E_USER_* уровни.
            // Конвертируем: E_WARNING → E_USER_WARNING, E_DEPRECATED → E_USER_DEPRECATED,
            // всё остальное (E_NOTICE и т.д.) → E_USER_NOTICE.
            $userLevel = match (true) {
                ($errno & (\E_WARNING | \E_USER_WARNING)) !== 0 => \E_USER_WARNING,
                ($errno & (\E_DEPRECATED | \E_USER_DEPRECATED)) !== 0 => \E_USER_DEPRECATED,
                default => \E_USER_NOTICE,
            };

            // @ подавляет ошибку если error_reporting её не включает.
            // PHPUnit'овский handler (уже восстановлен) поймает её,
            // сделает debug_backtrace(), найдёт TestCase в стеке — всё ок.
            @trigger_error($errstr, $userLevel);
        }
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new BatchRunnerStartedException();
        }
    }
}
