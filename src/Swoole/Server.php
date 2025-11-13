<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole;

use OpenSwoole\Core\Psr\Response as OpenSwooleResponse;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwoole\Process;
use OpenSwoole\Runtime;
use OpenSwoole\Server\Task;
use OpenSwooleBundle\Event\Server\ServerTaskEnded;
use OpenSwooleBundle\Event\Server\ServerTaskStarted;
use OpenSwooleBundle\Exception\OpenSwooleException;
use OpenSwooleBundle\Swoole\Handler\TaskFinishHandlerInterface;
use OpenSwooleBundle\Swoole\Handler\TaskHandlerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Upscale\Swoole\Blackfire\Profiler;

/**
 * Class Server.
 */
class Server
{
    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var array
     */
    private $options;

    /**
     * @var int
     */
    private $hookFlags;

    /**
     * @var bool
     */
    private $useSyncWorker;

    /**
     * @var \OpenSwoole\Http\Server|null
     */
    private $server;

    /**
     * @var HttpKernelInterface
     */
    private $kernel;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var WorkerMutexPool|null
     */
    private $workerMutexPool;

    /**
     * @var TaskHandlerInterface|null
     */
    private $taskHandler;

    /**
     * @var TaskFinishHandlerInterface|null
     */
    private $taskFinishHandler;

    private \Closure|null $onShutdown = null;

    /**
     * SIGUSR1 signal number; defined here to avoid a hard dependency on ext-pcntl.
     */
    private const SIGUSR1 = 10;

    public function __construct(
        string $host,
        int $port,
        array $options,
        int $hookFlags,
        HttpKernelInterface $kernel,
        LoggerInterface $logger,
        bool $useSyncWorker,
        private HttpFoundationFactoryInterface $symfonyRequestFactory,
        private HttpMessageFactoryInterface $psrFactory,
        TaskHandlerInterface|null $taskHandler = null,
        TaskFinishHandlerInterface|null $taskFinishHandler = null,
        private EventDispatcherInterface|null $eventDispatcher = null,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->options = $options;
        $this->hookFlags = $hookFlags;
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->workerMutexPool = CoroutineHelper::openswooleEnabled()
            ? new WorkerMutexPool()
            : null;
        $this->useSyncWorker = $useSyncWorker;
        $this->taskHandler = $taskHandler;
        $this->taskFinishHandler = $taskFinishHandler;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return $this
     */
    public function setHost(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return $this
     */
    public function setPort(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    /**
     * Get swoole configuration option value.
     */
    public function getOption(string $key)
    {
        if (!array_key_exists($key, $this->options)) {
            throw new \InvalidArgumentException(sprintf('Parameter not found: %s', $key));
        }

        return $this->options[$key];
    }

    /**
     * Start and configure swoole server.
     */
    public function start(
        callable $onStart,
        callable|null $onShutdown = null,
    ): void {
        $this->createServer();
        $this->configureSwooleServer();
        $this->symfonyBridge($onStart, $onShutdown);
    }

    /**
     * Stop the swoole server.
     *
     * @throws OpenSwooleException
     */
    public function stop(): bool
    {
        $kill = Process::kill($this->getPid());

        if (!$kill) {
            throw new OpenSwooleException('Swoole server not stopped!');
        }

        return $kill;
    }

    /**
     * Reload swoole server.
     *
     * @throws OpenSwooleException
     */
    public function reload(): bool
    {
        $reload = Process::kill($this->getPid(), self::SIGUSR1);

        if (!$reload) {
            throw new OpenSwooleException('Swoole server not reloaded!');
        }

        return $reload;
    }

    public function isRunning(): bool
    {
        $pid = $this->getPid();

        if (!$pid) {
            return false;
        }

        return Process::kill($pid, 0);
    }

    /**
     * @return bool
     */
    public function stopWorker(int $workerId = -1)
    {
        return $this->server->stop($workerId);
    }

    private function getPid(): int
    {
        $file = $this->getPidFile();

        if (!file_exists($file)) {
            return 0;
        }

        $pid = (int) file_get_contents($file);

        if (!$pid) {
            $this->removePidFile();

            return 0;
        }

        return $pid;
    }

    /**
     * Get pid file.
     */
    private function getPidFile(): string
    {
        return $this->getOption('pid_file');
    }

    /**
     * Remove the pid file.
     */
    private function removePidFile(): void
    {
        $file = $this->getPidFile();

        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Create the swoole http server.
     */
    private function createServer(): void
    {
        $this->server = new \OpenSwoole\Http\Server($this->host, $this->port);
    }

    /**
     * Configure the created server.
     */
    private function configureSwooleServer(): void
    {
        $this->server->set($this->options);
        Runtime::enableCoroutine($this->getOption('enable_coroutine'), $this->hookFlags);
    }

    private function symfonyBridge(callable $onStart, callable|null $onShutdown = null): void
    {
        $onShutdown ??= $this->onShutdown;

        $this->server->on('start', static function () use ($onStart) {
            $onStart('Server started!');
        });

        if (null !== $this->taskHandler) {
            $this->server->on('task', function (\OpenSwoole\Http\Server $server, Task $task) {
                $this->eventDispatcher?->dispatch(new ServerTaskStarted($task));
                $this->taskHandler->handle($this->server, $task);
                $this->eventDispatcher?->dispatch(new ServerTaskEnded($task));
            });
        }

        if (null !== $this->taskFinishHandler) {
            $this->server->on(
                'finish',
                fn (\OpenSwoole\Http\Server $server, int $taskId, mixed $data) => $this->taskFinishHandler->handle($this->server, $taskId, $data),
            );
        }

        if (null !== $onShutdown) {
            $this->server->on('shutdown', $onShutdown);
        }

        if ($this->useSyncWorker && CoroutineHelper::inCoroutine()) {
            $this->server->on('WorkerStart', function (\OpenSwoole\Http\Server $server, int $workerId) {
                $this->needSyncWorker() && $this->workerMutexPool?->create($workerId);
            });
        }

        $this->server->on('request', function (\OpenSwoole\Http\Request $request, \OpenSwoole\Http\Response $response) {
            $info = $this->server->getClientInfo($request->fd);
            $receiveTime = $info['last_recv_time'];
            $reqTime = microtime(true);

            $mutex = $this->needSyncWorker()
                ? $this->workerMutexPool?->getOrCreate($this->server->getWorkerId())
                : null;
            $mutex?->lock();

            $serverRequest = ServerRequest::from($request);
            $sfRequest = $this->symfonyRequestFactory->createRequest($serverRequest);
            if (!$sfRequest->server->has('REQUEST_TIME_FLOAT')) {
                $sfRequest->server->set(
                    'REQUEST_TIME_FLOAT',
                    $sfRequest->server->get(
                        'request_time_float',
                        $info['last_recv_time'],
                    ),
                );
            }

            $handleStartTime = microtime(true);

            $sfRequest->attributes->set('_req_openswoole_receive_time', $receiveTime);
            $sfRequest->attributes->set('_req_openswoole_request_time', $reqTime);
            $sfRequest->attributes->set('_req_openswoole_handle_start_time', $handleStartTime);

            $reqLifecycleMetrics = [
                'last_recv_time' => $receiveTime,
                'req_time' => $reqTime,
                'handle_start_time' => $handleStartTime,
                'worker_id' => $this->server->getWorkerId(),
                'info' => $info,
            ];
            $sfRequest->attributes->set('_req_lifecycle_metrics', $reqLifecycleMetrics);

            $content = '';

            try {
                $sfResponse = $this->kernel->handle($sfRequest);

                $sfRequest->attributes->set('_req_openswoole_handle_stop_time', microtime(true));

                $psrResponse = $this->psrFactory->createResponse($sfResponse);

                OpenSwooleResponse::emit($response, $psrResponse);

                $sfRequest->attributes->set('_req_openswoole_emit_time', microtime(true));
            } catch (\Throwable $throwable) {
                $content = json_encode([
                    'code' => SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR,
                    'message' => $throwable->getMessage(),
                ]);

                $this->logger->error($throwable->getMessage(), [
                    'class' => $throwable::class,
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                    'trace' => $throwable->getTrace(),
                ]);

                if ($response->isWritable()) {
                    $response->status(SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
                    $response->end($content);
                }
            } finally {
                if (!isset($sfResponse)) {
                    $sfResponse = new SymfonyResponse($content, SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
                }

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($sfRequest, $sfResponse);
                }

                $mutex?->unlock();
            }
        });

        // Instrument Blackfire profiler only when the extension is available to avoid overhead and type mismatches
        if (\extension_loaded('blackfire') && \class_exists(Profiler::class)) {
            $profiler = new Profiler();
            $swooleServer = $this->server;
            $profiler->instrument($swooleServer);
        }

        $this->server->start();
    }

    public function stats(int $mode = 0): array|false|string
    {
        return $this->server->stats($mode);
    }

    public function needSyncWorker(): bool
    {
        return $this->useSyncWorker && CoroutineHelper::inCoroutine();
    }

    public function getActiveClientsCount(): int
    {
        $stats = $this->server->stats();

        return isset($stats['connection_num']) ? (int) $stats['connection_num'] : 0;
    }

    public function task(mixed $data, int $dstWorkerId = -1, callable|null $finishCallback = null): int|null
    {
        if (!isset($this->server) || $this->server->taskworker || !$this->isRunning()) {
            return null;
        }

        $taskId = $this->server->task($data, $dstWorkerId, $finishCallback);

        if (false === $taskId) {
            return null;
        }

        return $taskId;
    }

    public function setOnShutdown(\Closure $onShutdown): void
    {
        $this->onShutdown = $onShutdown;
    }
}
