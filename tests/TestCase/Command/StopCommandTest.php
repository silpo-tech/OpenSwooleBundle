<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Command;

use OpenSwooleBundle\Command\StopCommand;
use OpenSwooleBundle\Swoole\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class StopCommandTest extends TestCase
{
    public function testExecuteServerNotRunning(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willReturn(false);

        $command = new StopCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(0, $result);
        self::assertStringContainsString('Server not running! Please before start the server.', $output->fetch());
    }

    public function testExecuteExceptionHandled(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willThrowException(new RuntimeException('test message'));

        $command = new StopCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(1, $result);
        self::assertStringContainsString('test message', $output->fetch());
    }

    public function testExecuteAlreadyRunning(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willReturn(true);
        $server->expects(self::once())->method('stop');

        $command = new StopCommand($server);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(0, $result);
        self::assertStringContainsString('OpenSwoole server stopped!', $output->fetch());
    }
}
