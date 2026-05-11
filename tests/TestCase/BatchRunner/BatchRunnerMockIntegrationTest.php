<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\BatchRunner;

use OpenSwooleBundle\Batch\BatchRunner;
use OpenSwooleBundle\Exception\BatchRunException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that PHPUnit mock objects work inside BatchRunner when running sequentially.
 *
 * In test environments (APP_ENV=test or OPENSWOOLE_SEQUENTIAL=1), BatchRunner auto-detects
 * and runs callables sequentially without coroutines, avoiding the
 * NoTestCaseObjectOnCallStackException that occurs when PHPUnit's debug_backtrace()
 * cannot find the TestCase inside a coroutine's separate call stack.
 */
final class BatchRunnerMockIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        BatchRunner::forceSequential(null);
    }

    public function testMockCanBeInvokedInsideSequentialBatchRunner(): void
    {
        BatchRunner::forceSequential(true);

        $mock = $this->createMock(SomeServiceWithArgumentInterface::class);
        $mock->expects(self::once())
            ->method('fetch')
            ->with('arg')
            ->willReturn(['item1', 'item2'])
        ;

        $results = BatchRunner::fromCallables([
            static fn () => $mock->fetch('arg'),
        ])->runAll();

        self::assertSame([['item1', 'item2']], $results);
    }

    public function testMockFailsInsideCoroutineBatchRunner(): void
    {
        BatchRunner::forceSequential(false);

        $mock = $this->createMock(SomeServiceWithArgumentInterface::class);
        $mock->expects(self::once())
            ->method('fetch')
            ->with('arg')
            ->willReturn('value')
        ;

        $this->expectException(BatchRunException::class);
        $this->expectExceptionMessage('Cannot find TestCase object on call stack');

        BatchRunner::fromCallables([
            static fn () => $mock->fetch('arg'),
        ])->runAll();
    }

    public function testMultipleMocksInSequentialBatchRunner(): void
    {
        BatchRunner::forceSequential(true);

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

    public function testSequentialModeCanBeOverriddenPerInstance(): void
    {
        BatchRunner::forceSequential(true);

        $mock = $this->createMock(SomeServiceInterface::class);
        $mock->expects(self::once())
            ->method('fetch')
            ->willReturn('concurrent-result')
        ;

        // Override to run concurrently (coroutines) for this specific instance
        $results = BatchRunner::fromCallables([
            static fn () => $mock->fetch(),
        ])->withSequential(false)->runAll();

        self::assertSame(['concurrent-result'], $results);
    }
}

interface SomeServiceInterface
{
    public function fetch(): mixed;
}

interface SomeServiceWithArgumentInterface
{
    public function fetch(string $arg): mixed;
}
