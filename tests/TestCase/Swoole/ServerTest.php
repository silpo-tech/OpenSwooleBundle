<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Swoole;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenSwoole\Atomic;
use OpenSwoole\Process;
use OpenSwoole\Runtime;
use OpenSwoole\Table;
use OpenSwooleBundle\Batch\BatchRunner;
use OpenSwooleBundle\Bridge\Messenger\OpenSwooleTaskTransport;
use OpenSwooleBundle\Exception\OpenSwooleException;
use OpenSwooleBundle\Swoole\Handler\MessengerSendTaskHandler;
use OpenSwooleBundle\Swoole\Handler\NoopTaskFinishHandler;
use OpenSwooleBundle\Swoole\Server;
use OpenSwooleBundle\Tests\Kernel;
use OpenSwooleBundle\Tests\Messenger\TestMessage;
use OpenSwooleBundle\Tests\Messenger\TestMessageHandler;
use OpenSwooleBundle\Tests\Stub\ContainerStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as ContainerContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class ServerTest extends TestCase
{
    public const TEST_LOG_FILE = 'test.log';

    private const string SIGNAL_PID_FILE = '/tmp/openswoole_server_signal_test.pid';
    private const int SIGTERM = 15;
    private const int SIGUSR1 = 10;
    private const float SIGNAL_EXIT_TIMEOUT_SECONDS = 5.0;

    /** @var resource|null */
    private $signalTargetProcess;

    protected function tearDown(): void
    {
        @unlink(self::TEST_LOG_FILE);
        if (\is_resource($this->signalTargetProcess)) {
            proc_terminate($this->signalTargetProcess, 9);
            proc_close($this->signalTargetProcess);
        }
        $this->signalTargetProcess = null;
        @unlink(self::SIGNAL_PID_FILE);
        parent::tearDown();
    }

    /**
     * We need #[WithoutErrorHandler] because when using separate processes, if any deprecation occurs
     * during execution of the other process, PHPUnit will fail with an error
     * (Uncaught PHPUnit\Event\Code\NoTestCaseObjectOnCallStackException: Cannot find TestCase object on call stack).
     * This attribute disables PHPUnit's error handler to prevent this cross-process error handling issue.
     */
    #[WithoutErrorHandler]
    #[DataProvider('dataProviderEnableCoroutine')]
    public function testEnableCoroutine(bool $useSyncServer, string|callable $expectedLogContent): void
    {
        $serverReady = new Atomic(0);
        $serverExit = new Atomic(0);
        $requestExit = new Atomic(0);

        $table = new Table(1024);
        $table->column('output', Table::TYPE_STRING, 1024);
        $table->create();

        $logger = self::createLogger();

        $kernel = new Kernel(
            static function (Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true) use ($logger): Response {
                $currentReqNum = $request->query->get('req');
                $data = BatchRunner::fromCallables([
                    static function () use ($currentReqNum, $logger) {
                        usleep(1000000 - $currentReqNum * 100000);

                        $logger->debug(sprintf('callable #1 of #%d request', $currentReqNum));

                        return sprintf('callable #1 of #%d request', $currentReqNum);
                    },
                    static function () use ($currentReqNum, $logger) {
                        usleep(500000 - $currentReqNum * 100000);

                        $logger->debug(sprintf('callable #2 of #%d request', $currentReqNum));

                        return sprintf('callable #2 of #%d request', $currentReqNum);
                    },
                ])
                    ->withHookFlags(Runtime::HOOK_ALL)
                    ->runAll()
                ;

                return new JsonResponse($data);
            },
        );

        $server = new Server(
            '0.0.0.0',
            8889,
            [
                'enable_coroutine' => true,
                'hook_flags' => 0,
                'worker_num' => 1,
                'reactor_num' => 4,
                'pid_file' => '/tmp/openswoole_server.pid',
                'dispatch_mode' => 3,
                'log_level' => 5,
            ],
            0,
            $kernel,
            new NullLogger(),
            $useSyncServer,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
        );

        $process = new Process(
            function () use ($serverReady, $table, $server, $serverExit, $requestExit) {
                $serverReady->wait();

                [$data, $throwables] = BatchRunner::fromCallables([
                    function () {
                        $data = $this->makeRequest(1);

                        return json_decode($data, true);
                    },
                    function () {
                        $data = $this->makeRequest(2);

                        return json_decode($data, true);
                    },
                    function () {
                        $data = $this->makeRequest(3);

                        return json_decode($data, true);
                    },
                ])->runAny();

                $table->set('output', ['output' => json_encode($data)]);
                $table->set('throwables', ['output' => json_encode($throwables)]);

                $server->stop();

                $serverExit->wait();

                $requestExit->wakeup();
            },
            true,
        );

        $process->start();

        $server->start(
            static function (string $s) use ($serverReady) {
                $serverReady->wakeup();
            },
            static function () use ($serverExit) {
                $serverExit->wakeup();
            },
        );

        $requestExit->wait();

        $throwables = json_decode($table->get('throwables', 'output'), true);
        self::assertEmpty($throwables);

        $data = json_decode($table->get('output')['output'], true);

        self::assertEquals([
            ['callable #1 of #1 request', 'callable #2 of #1 request'],
            ['callable #1 of #2 request', 'callable #2 of #2 request'],
            ['callable #1 of #3 request', 'callable #2 of #3 request'],
        ], $data);

        $actualLogContent = file_get_contents('test.log');

        if (is_callable($expectedLogContent)) {
            $expectedLogContent($actualLogContent);
        } else {
            self::assertEquals($expectedLogContent, $actualLogContent);
        }
    }

    public static function dataProviderEnableCoroutine(): iterable
    {
        yield 'sync worker' => [
            true,
            static function (string $logContent): void {
                /**
                 * Could be something like this.
                 *
                 * callable #2 of #1 request
                 * callable #1 of #1 request
                 * callable #2 of #3 request
                 * callable #1 of #3 request
                 * callable #2 of #2 request
                 * callable #1 of #2 request
                 */
                $lines = array_flip(array_filter(array_map(trim(...), explode("\n", $logContent))));

                for ($i = 0; $i < 3; ++$i) {
                    $string = sprintf('callable #%d of #%d request', 1, $i + 1);
                    self::assertArrayHasKey($string, $lines, var_export($lines, true));
                    $idx = $lines[$string];
                    // (idx - 1) because the first callable has been ended secondly
                    self::assertSame($idx - 1, $lines[sprintf('callable #%d of #%d request', 2, $i + 1)], var_export($lines, true));
                }
            },
        ];

        yield 'async worker' => [
            false,
            <<<TEXT
        callable #2 of #3 request
        callable #2 of #2 request
        callable #2 of #1 request
        callable #1 of #3 request
        callable #1 of #2 request
        callable #1 of #1 request

        TEXT,
        ];
    }

    /**
     * We need #[WithoutErrorHandler] because when using separate processes, if any deprecation occurs
     * during execution of the other process, PHPUnit will fail with an error
     * (Uncaught PHPUnit\Event\Code\NoTestCaseObjectOnCallStackException: Cannot find TestCase object on call stack).
     * This attribute disables PHPUnit's error handler to prevent this cross-process error handling issue.
     */
    #[WithoutErrorHandler]
    public function testTaskWorker(): void
    {
        $serverReady = new Atomic(0);
        $serverExit = new Atomic(0);
        $requestExit = new Atomic(0);

        $table = new Table(1024);
        $table->column('output', Table::TYPE_STRING, 1024);
        $table->create();

        $container = new ContainerStub();

        $logger = self::createLogger();
        $bus = self::createMessageBus(
            $container,
            [
                '*' => [
                    new TestMessageHandler($logger, 10000),
                ],
            ],
        );

        $kernel = new Kernel(
            static function (
                Request $request,
                int $type = HttpKernelInterface::MAIN_REQUEST,
                bool $catch = true,
            ) use ($bus, $logger): Response {
                $bus->dispatch(new TestMessage('hello world'));

                $logger->info('Dispatched message');

                return new Response('hello world');
            },
        );

        $server = new Server(
            '0.0.0.0',
            8889,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => '/tmp/openswoole_server.pid',
                'dispatch_mode' => 3,
                'task_worker_num' => 1,
                'task_use_object' => true,
                'log_level' => 5,
            ],
            0,
            $kernel,
            $logger,
            true,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
            new MessengerSendTaskHandler($bus),
            new NoopTaskFinishHandler(),
        );

        $container->set(OpenSwooleTaskTransport::class, new OpenSwooleTaskTransport($server, $bus));

        $process = new Process(
            function () use ($serverReady, $table, $server, $serverExit, $requestExit) {
                $serverReady->wait();

                $output = $this->makeRequest();

                $table->set('output', ['output' => $output]);

                $server->stop();

                $serverExit->wait();

                $requestExit->wakeup();
            },
        );

        $process->start();

        $server->start(
            static function (string $s) use ($serverReady) {
                $serverReady->wakeup();
            },
            static function () use ($serverExit) {
                $serverExit->wakeup();
            },
        );

        $requestExit->wait();

        $output = $table->get('output')['output'];

        $actualLogContent = file_get_contents('test.log');

        self::assertEquals(
            <<<TEXT
        Dispatched message
        Handled message: hello world

        TEXT,
            $actualLogContent,
        );

        self::assertEquals('hello world', $output);
    }

    public function testTaskWorkerFallback(): void
    {
        $container = new ContainerStub();

        $logger = self::createLogger();
        $bus = self::createMessageBus(
            $container,
            [
                '*' => [
                    new TestMessageHandler($logger, 100),
                ],
            ],
        );
        $kernel = new Kernel();

        $server = new Server(
            '0.0.0.0',
            8889,
            [
                'enable_coroutine' => false,
                'worker_num' => 1,
                'pid_file' => '/tmp/openswoole_server.pid',
                'dispatch_mode' => 3,
                'task_worker_num' => 1,
                'task_use_object' => true,
                'log_level' => 5,
            ],
            0,
            $kernel,
            $logger,
            true,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
            new MessengerSendTaskHandler($bus),
            new NoopTaskFinishHandler(),
        );

        $container->set(OpenSwooleTaskTransport::class, new OpenSwooleTaskTransport($server, $bus));

        $bus->dispatch(new TestMessage('hello world'));

        $actualLogContent = file_get_contents('test.log');

        self::assertEquals(
            <<<TEXT
        Handled message: hello world

        TEXT,
            $actualLogContent,
        );
    }

    #[DataProvider('providerStopReloadOk')]
    public function testStopReloadOk(array $data = [], array $conditions = [], array $expected = []): void
    {
        $pid = $this->startSignalTargetProcess();
        file_put_contents(self::SIGNAL_PID_FILE, (string) $pid);

        $result = self::createSignalServer()->{$data['method']}();

        self::assertTrue($result);
        self::assertSame($expected['signal'], $this->waitForTerminationSignal());
    }

    public static function providerStopReloadOk(): iterable
    {
        yield 'stop: sends SIGTERM to the pid from the pid file' => [
            'data' => ['method' => 'stop'],
            'conditions' => [],
            'expected' => ['signal' => self::SIGTERM],
        ];

        yield 'reload: sends SIGUSR1 to the pid from the pid file' => [
            'data' => ['method' => 'reload'],
            'conditions' => [],
            'expected' => ['signal' => self::SIGUSR1],
        ];
    }

    /**
     * Without a pid, kill(0, ...) would signal this PHPUnit process group, so reaching the assertions proves no signal was sent.
     */
    #[DataProvider('providerStopReloadErrors')]
    public function testStopReloadErrors(array $data = [], array $conditions = [], array $expected = []): void
    {
        if (null !== $conditions['pidFileContent']) {
            file_put_contents(self::SIGNAL_PID_FILE, $conditions['pidFileContent']);
        }

        $this->expectException(OpenSwooleException::class);
        $this->expectExceptionMessage($expected['message']);

        self::createSignalServer()->{$data['method']}();
    }

    public static function providerStopReloadErrors(): iterable
    {
        $cases = [
            'stop' => 'Swoole server not stopped!',
            'reload' => 'Swoole server not reloaded!',
        ];

        foreach ($cases as $method => $message) {
            yield "{$method}: throws when pid file is missing" => [
                'data' => ['method' => $method],
                'conditions' => ['pidFileContent' => null],
                'expected' => ['message' => $message],
            ];

            yield "{$method}: throws when pid file is empty" => [
                'data' => ['method' => $method],
                'conditions' => ['pidFileContent' => ''],
                'expected' => ['message' => $message],
            ];

            yield "{$method}: throws when pid file contains 0" => [
                'data' => ['method' => $method],
                'conditions' => ['pidFileContent' => '0'],
                'expected' => ['message' => $message],
            ];
        }
    }

    private static function createSignalServer(): Server
    {
        return new Server(
            '127.0.0.1',
            0,
            ['pid_file' => self::SIGNAL_PID_FILE],
            0,
            new Kernel(),
            new NullLogger(),
            false,
            new HttpFoundationFactory(),
            new PsrHttpFactory(),
        );
    }

    private function startSignalTargetProcess(): int
    {
        $this->signalTargetProcess = proc_open(['sleep', '30'], [], $pipes);
        self::assertIsResource($this->signalTargetProcess);

        return proc_get_status($this->signalTargetProcess)['pid'];
    }

    private function waitForTerminationSignal(): int|null
    {
        $deadline = microtime(true) + self::SIGNAL_EXIT_TIMEOUT_SECONDS;
        do {
            $status = proc_get_status($this->signalTargetProcess);
            if (!$status['running']) {
                return $status['signaled'] ? $status['termsig'] : null;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function makeRequest(int $numReq = 1): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'http://localhost:8889?req=' . $numReq . '&time=' . microtime(true));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HEADER, false);

        $output = curl_exec($ch);
        if (false === $output) {
            throw new \RuntimeException('CURL Error:' . curl_error($ch));
        }

        return $output;
    }

    private static function createLogger(): LoggerInterface
    {
        $handler = new StreamHandler(self::TEST_LOG_FILE, LogLevel::DEBUG);
        $handler->setFormatter(new LineFormatter('%message%' . PHP_EOL));
        $logger = new Logger(
            'test',
            [
                $handler,
            ],
        );

        return $logger;
    }

    private static function createMessageBus(
        ContainerContainerInterface $container,
        array $handlers,
    ): MessageBusInterface {
        $sendMiddleware = new SendMessageMiddleware(
            new SendersLocator(
                [
                    '*' => [
                        OpenSwooleTaskTransport::class,
                    ],
                ],
                $container,
            ),
        );

        $bus = new TraceableMessageBus(
            new MessageBus([
                $sendMiddleware,
                new HandleMessageMiddleware(
                    new HandlersLocator($handlers),
                ),
            ]),
        );

        return $bus;
    }
}
