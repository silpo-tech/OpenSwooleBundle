<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\BatchRunner;

use OpenSwoole\Coroutine\Scheduler;
use OpenSwoole\Runtime;
use OpenSwooleBundle\Batch\BatchRunner;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerEnded;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemEndedSuccessfully;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemEndedWithException;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerItemStarted;
use OpenSwooleBundle\Event\BatchRunner\BatchRunnerStarted;
use OpenSwooleBundle\Exception\BatchRunException;
use OpenSwooleBundle\Exception\BatchRunnerStartedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Stopwatch\Stopwatch;

final class BatchRunnerTest extends TestCase
{
    public function testRunAllInCoroutine(): void
    {
        $data = [];

        $scheduler = new Scheduler();
        $scheduler->add(static function () use (&$data) {
            BatchRunner::fromCallables(self::createCallablesTimeOrdered($data))
                ->runAll()
            ;
        });
        $scheduler->start();

        self::assertSame(
            [
                'callable #3',
                'callable #2',
                'callable #1',
            ],
            $data,
        );
    }

    public function testConcurrently(): void
    {
        $data = [];

        $callable = static fn (string $param1, int $param2, array $param3 = []): string
            => sprintf('Got params: %s, %d, %s', $param1, $param2, json_encode($param3));

        $arguments = [
            'someKey1' => ['value1', 2, ['arrayItem1' => 'arrayValue1']],
            'someKey2' => ['otherValue1', 22],
        ];

        $data = BatchRunner::concurrently($callable, $arguments)->runAll();

        self::assertSame(
            [
                'someKey1' => 'Got params: value1, 2, {"arrayItem1":"arrayValue1"}',
                'someKey2' => 'Got params: otherValue1, 22, []',
            ],
            $data,
        );
    }

    public function testRunAllNotInCoroutine(): void
    {
        $data = [];

        BatchRunner::fromCallables(self::createCallablesTimeOrdered($data))
            ->runAll()
        ;

        self::assertSame(
            [
                'callable #3',
                'callable #2',
                'callable #1',
            ],
            $data,
        );
    }

    public function testRunAllWithoutHookFlags(): void
    {
        $data = [];

        BatchRunner::fromCallables(self::createCallablesTimeOrdered($data))
            ->withHookFlags(0)
            ->runAll()
        ;

        self::assertSame(
            [
                'callable #1',
                'callable #2',
                'callable #3',
            ],
            $data,
        );
    }

    public function testRunAllWithHookFlagsButWithoutSetRuntimeHooks(): void
    {
        $data = [];

        BatchRunner::fromCallables(self::createCallablesTimeOrdered($data))
            ->withHookFlags(Runtime::HOOK_ALL)
            ->withSetRuntimeHooks(false)
            ->runAll()
        ;

        self::assertSame(
            [
                'callable #1',
                'callable #2',
                'callable #3',
            ],
            $data,
        );
    }

    public function testCantStartTwice(): void
    {
        $data = [];

        $runner = BatchRunner::fromCallables(self::createCallablesTimeOrdered($data));

        $runner->runAll();

        $this->expectException(BatchRunnerStartedException::class);

        $runner->runAll();
    }

    public function testRunAllWithException(): void
    {
        $this->expectException(BatchRunException::class);
        $this->expectExceptionMessage('Batch run resulted with 1 exception(s). The first is: callable #2');

        BatchRunner::fromCallables(self::createCallablesWithException())
            ->runAll()
        ;
    }

    public function testRunAny(): void
    {
        [$results, $throwables] = BatchRunner::fromCallables(self::createCallablesWithException())
            ->runAny()
        ;

        self::assertEquals(
            [
                0 => 'callable #1',
                2 => 'callable #3',
            ],
            $results,
        );

        self::assertInstanceOf(\RuntimeException::class, $throwables[1]);
        self::assertEquals('callable #2', $throwables[1]->getMessage());
    }

    public function testRunAllWithDispatcher(): void
    {
        $data = [];

        $stopwatch = new Stopwatch();
        $dispatcher = new TraceableEventDispatcher(
            new EventDispatcher(),
            $stopwatch,
        );

        BatchRunner::fromCallables(self::createCallablesTimeOrdered($data))
            ->withDispatcher($dispatcher)
            ->runAll()
        ;

        self::assertSame(
            [
                'callable #3',
                'callable #2',
                'callable #1',
            ],
            $data,
        );

        self::assertEquals(
            [
                BatchRunnerStarted::class,
                BatchRunnerItemStarted::class,
                BatchRunnerItemStarted::class,
                BatchRunnerItemStarted::class,

                BatchRunnerItemEndedSuccessfully::class,
                BatchRunnerItemEndedSuccessfully::class,
                BatchRunnerItemEndedSuccessfully::class,

                BatchRunnerEnded::class,
            ],
            $dispatcher->getOrphanedEvents(),
        );
    }

    public function testRunAnyWithExceptionWithDispatcher(): void
    {
        $stopwatch = new Stopwatch();
        $dispatcher = new TraceableEventDispatcher(
            new EventDispatcher(),
            $stopwatch,
        );

        BatchRunner::fromCallables(self::createCallablesWithException())
            ->withDispatcher($dispatcher)
            ->runAny()
        ;

        self::assertEquals(
            [
                BatchRunnerStarted::class,

                BatchRunnerItemStarted::class,
                BatchRunnerItemEndedSuccessfully::class,

                BatchRunnerItemStarted::class,
                BatchRunnerItemEndedWithException::class,

                BatchRunnerItemStarted::class,
                BatchRunnerItemEndedSuccessfully::class,

                BatchRunnerEnded::class,
            ],
            $dispatcher->getOrphanedEvents(),
        );
    }

    private static function createCallablesTimeOrdered(array &$data): array
    {
        return [
            static function () use (&$data) {
                usleep(10000);
                $data[] = 'callable #1';
            },
            static function () use (&$data) {
                usleep(5000);
                $data[] = 'callable #2';
            },
            static function () use (&$data) {
                usleep(3000);
                $data[] = 'callable #3';
            },
        ];
    }

    public static function createCallablesWithException(): array
    {
        return [
            static fn () => 'callable #1',
            static function () {
                throw new \RuntimeException('callable #2');
            },
            static fn () => 'callable #3',
        ];
    }
}
