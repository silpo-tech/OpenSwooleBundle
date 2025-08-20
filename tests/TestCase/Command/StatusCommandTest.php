<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Command;

use OpenSwooleBundle\Command\StatusCommand;
use OpenSwooleBundle\Swoole\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class StatusCommandTest extends TestCase
{
    public function testExecuteServerNotRunning(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willReturn(false);
        $server->expects(self::once())->method('getHost')->willReturn('0.0.0.0');
        $server->expects(self::once())->method('getPort')->willReturn(8888);

        $command = new StatusCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(0, $result);
        $out = $output->fetch();
        self::assertStringContainsString('0.0.0.0', $out);
        self::assertStringContainsString('8888', $out);
        self::assertStringContainsString('Stopped', $out);
    }

    public function testExecuteAlreadyRunning(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willReturn(true);
        $server->expects(self::once())->method('getHost')->willReturn('0.0.0.0');
        $server->expects(self::once())->method('getPort')->willReturn(8888);

        $command = new StatusCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(0, $result);
        $out = $output->fetch();
        self::assertStringContainsString('0.0.0.0', $out);
        self::assertStringContainsString('8888', $out);
        self::assertStringContainsString('Running', $out);
    }

    public function testExecuteExceptionHandled(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willThrowException(new \RuntimeException('test message'));
        $server->expects(self::once())->method('getHost')->willReturn('0.0.0.0');
        $server->expects(self::once())->method('getPort')->willReturn(8888);

        $command = new StatusCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(1, $result);
        self::assertStringContainsString('test message', $output->fetch());
    }
}
