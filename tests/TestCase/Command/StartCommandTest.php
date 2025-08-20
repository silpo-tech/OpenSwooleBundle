<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Command;

use OpenSwoole\Atomic;
use OpenSwoole\Process;
use OpenSwooleBundle\Command\StartCommand;
use OpenSwooleBundle\Swoole\Server;
use OpenSwooleBundle\Tests\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class StartCommandTest extends TestCase
{
    public function testExecute(): void
    {
        $serverExit = new Atomic(0);
        $kernel = new Kernel();

        $server = new Server(
            '0.0.0.0',
            8888,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => '/tmp/openswoole_server.pid',
                'dispatch_mode' => 3,
                'log_level' => 5,
            ],
            0,
            $kernel,
            new NullLogger(),
            true,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
        );
        $server->setOnShutdown(static function () use ($serverExit) {
            $serverExit->wakeup();
        });

        $process = new Process(
            static function () use ($server) {
                usleep(100000);
                $server->stop();
            },
        );
        $process->start();

        $command = new StartCommand($server);

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => 8888,
        ]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $serverExit->wait();

        self::assertSame(0, $result);
        self::assertStringContainsString('Server started!', $output->fetch());
    }

    public function testExecuteExceptionHandled(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('isRunning')->willThrowException(new \RuntimeException('test message'));

        $command = new StartCommand($server);

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => 8888,
        ]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        self::assertSame(1, $result);
        self::assertStringContainsString('test message', $output->fetch());
    }

    public function testExecuteAlreadyRunning(): void
    {
        $serverReady = new Atomic(0);
        $serverExit = new Atomic(0);
        $kernel = new Kernel();

        $server = new Server(
            '0.0.0.0',
            8888,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => '/tmp/openswoole_server.pid',
                'dispatch_mode' => 3,
                'log_level' => 5,
            ],
            0,
            $kernel,
            new NullLogger(),
            true,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
        );

        $process = new Process(
            static function () use ($server, $serverReady, $serverExit) {
                $server->start(
                    static function () use ($serverReady) {
                        $serverReady->wakeup();
                    },
                    static function () use ($serverExit) {
                        $serverExit->wakeup();
                    },
                );
            },
        );

        $processStop = new Process(
            static function () use ($server, $serverReady) {
                $serverReady->wait();
                usleep(100000);
                $server->stop();
            },
        );

        $process->start();
        $processStop->start();

        $serverReady->wait();

        $command = new StartCommand($server);

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => 8888,
        ]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        self::assertStringContainsString('Server is running! Please before stop the server.', $output->fetch());

        $serverExit->wait();
    }
}
