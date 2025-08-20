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
use OpenSwooleBundle\Swoole\Handler\MessengerSendTaskHandler;
use OpenSwooleBundle\Swoole\Handler\NoopTaskFinishHandler;
use OpenSwooleBundle\Swoole\Server;
use OpenSwooleBundle\Tests\Kernel;
use OpenSwooleBundle\Tests\Messenger\TestMessage;
use OpenSwooleBundle\Tests\Messenger\TestMessageHandler;
use OpenSwooleBundle\Tests\Stub\ContainerStub;
use PHPUnit\Framework\Attributes\DataProvider;
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

    protected function tearDown(): void
    {
        @unlink(self::TEST_LOG_FILE);
    }

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
                        usleep(100000 - $currentReqNum * 10000);

                        $logger->debug(sprintf('callable #1 of #%d request', $currentReqNum));

                        return sprintf('callable #1 of #%d request', $currentReqNum);
                    },
                    static function () use ($currentReqNum, $logger) {
                        usleep(50000 - $currentReqNum * 10000);

                        $logger->debug(sprintf('callable #2 of #%d request', $currentReqNum));

                        return sprintf('callable #2 of #%d request', $currentReqNum);
                    },
                ])
                    ->withHookFlags(Runtime::HOOK_SLEEP)
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

                $data = BatchRunner::fromCallables([
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
                ])->runAll();

                $table->set('output', ['output' => json_encode($data)]);

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

        file_put_contents('/tmp/openswoole_server.pid', 12345);

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

    private function makeRequest(int $numReq = 1): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'http://localhost:8889?req='.$numReq);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HEADER, 0);

        $output = curl_exec($ch);
        if (false === $output) {
            throw new \RuntimeException('CURL Error:'.curl_error($ch));
        }

        curl_close($ch);

        return $output;
    }

    private static function createLogger(): LoggerInterface
    {
        $handler = new StreamHandler(self::TEST_LOG_FILE, LogLevel::DEBUG);
        $handler->setFormatter(new LineFormatter('%message%'.PHP_EOL));
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
