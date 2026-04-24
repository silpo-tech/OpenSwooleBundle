<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\BatchRunner;

use OpenSwooleBundle\Batch\BatchRunner;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that PHPUnit mock objects can be used inside BatchRunner coroutines.
 *
 * PHPUnit (>=11.5.49) registers a custom error handler that traverses the call stack
 * via debug_backtrace() to locate the TestCase instance. Inside an OpenSwoole coroutine
 * the call stack is separate, so the TestCase is not found and
 * NoTestCaseObjectOnCallStackException is thrown.
 *
 * BatchRunner now captures the previous error handler and restores it inside each
 * coroutine, preventing the crash.
 */
final class BatchRunnerMockIntegrationTest extends TestCase
{
    public function testMockCanBeInvokedInsideCoroutine(): void
    {
        $mock = $this->createMock(SomeServiceInterface::class);
        $mock->expects(self::once())
            ->method('fetch')
            ->willReturn(['item1', 'item2'])
        ;

        $results = BatchRunner::fromCallables([
            static fn () => $mock->fetch(),
        ])->runAll();

        self::assertSame([['item1', 'item2']], $results);
    }

    public function testMultipleMocksInParallelCoroutines(): void
    {
        $mockA = $this->createMock(SomeServiceInterface::class);
        $mockA->expects(self::once())
            ->method('fetch')
            ->willReturn('result-a')
        ;

        $mockB = $this->createMock(SomeServiceInterface::class);
        $mockB->expects(self::once())
            ->method('fetch')
            ->willReturn('result-b')
        ;

        $results = BatchRunner::fromCallables([
            'a' => static fn () => $mockA->fetch(),
            'b' => static fn () => $mockB->fetch(),
        ])->runAll();

        self::assertSame('result-a', $results['a']);
        self::assertSame('result-b', $results['b']);
    }
}

interface SomeServiceInterface
{
    public function fetch(): mixed;
}
