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
    private const string PID_FILE = '/tmp/openswoole_server.pid';
    private const int PORT = 8899;
    private const int START_TIMEOUT_SECONDS = 10;

    public function testExecute(): void
    {
        @unlink(self::PID_FILE);
        $serverExit = new Atomic(0);
        $kernel = new Kernel();

        $server = new Server(
            '0.0.0.0',
            self::PORT,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => self::PID_FILE,
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

        // StartCommand blocks this process, so a child stops the server. It waits for an HTTP response: the pid file
        // and the bound port appear before the master handles SIGTERM, and an early signal kills or hangs PHPUnit.
        $process = new Process(
            static function () use ($server) {
                $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
                while (!self::isServerResponding() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $server->stop();
            },
        );
        $process->start();

        $command = new StartCommand($server);

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => self::PORT,
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
            '--port' => self::PORT,
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
            self::PORT,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => self::PID_FILE,
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
                usleep(10000);
                $server->stop();
            },
        );

        $process->start();
        $processStop->start();

        $serverReady->wait();

        $command = new StartCommand($server);

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => self::PORT,
        ]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        self::assertStringContainsString('Server is running! Please stop the server before starting.', $output->fetch());

        $serverExit->wait();
    }

    private static function isServerResponding(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errorCode, $errorMessage, 0.1);
        if (false === $socket) {
            return false;
        }

        stream_set_timeout($socket, 1);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $statusLine = fgets($socket);
        fclose($socket);

        return \is_string($statusLine) && str_starts_with($statusLine, 'HTTP/');
    }
}
