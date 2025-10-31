<?php

declare(strict_types=1);

namespace ExceptionHandlerBundle\Tests\TestCase\DependencyInjection;

use OpenSwooleBundle\DependencyInjection\OpenSwooleExtension;
use OpenSwooleBundle\EventSubscriber\AMQPReconnectSubscriber;
use OpenSwooleBundle\EventSubscriber\ContainerSubscriber;
use OpenSwooleBundle\Swoole\Handler\TaskFinishHandlerInterface;
use OpenSwooleBundle\Swoole\Handler\TaskHandlerInterface;
use OpenSwooleBundle\Swoole\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class OpenSwooleExtensionTest extends TestCase
{
    public static function providerLoad(): iterable
    {
        yield 'default' => [
            'config' => [
                'open_swoole_server' => [
                    'options' => [
                        'pid_file' => 'open_swoole_server.pid',
                    ],
                ],
            ],
            'expected' => [
                'services' => [
                    Server::class,
                    ContainerSubscriber::class,
                    AMQPReconnectSubscriber::class,
                    'openswoole_bundle.server_task_messenger_handler',
                    'openswoole_bundle.server_task_noop_finish_handler',
                    'openswoole_bundle.server.symfony_request_factory',
                    'openswoole_bundle.server.psr_factory',
                ],
            ],
            'assertServices' => [
                Server::class => static function (Definition $definition) {
                    self::assertSame('0.0.0.0', $definition->getArgument(0));
                    self::assertSame(80, $definition->getArgument(1));
                    self::assertEquals([
                        'enable_coroutine' => true,
                        'pid_file' => 'open_swoole_server.pid',
                        'log_file' => '/proc/self/fd/1',
                        'log_level' => 5,
                        'daemonize' => false,
                        'document_root' => '%kernel.project_dir%/public',
                        'enable_static_handler' => true,
                        'max_request' => 0,
                        'max_request_grace' => 0,
                        'max_request_execution_time' => 0,
                        'max_wait_time' => 3,
                        'open_http2_protocol' => false,
                        'dispatch_mode' => 2,
                        'worker_num' => 4,
                        'task_worker_num' => 1,
                        'task_use_object' => true,
                        'task_enable_coroutine' => false,
                        'reactor_num' => 8,
                        'buffer_output_size' => 8388608,
                        'package_max_length' => 2097152,
                    ], $definition->getArgument(2));
                    self::assertSame(0, $definition->getArgument(3));
                    self::assertReferenceIs('kernel', $definition->getArgument(4));
                    self::assertReferenceIs('logger', $definition->getArgument(5));
                    self::assertTrue($definition->getArgument(6));
                    self::assertReferenceIs('openswoole_bundle.server.symfony_request_factory', $definition->getArgument(7));
                    self::assertReferenceIs('openswoole_bundle.server.psr_factory', $definition->getArgument(8));
                    self::assertReferenceIs(TaskHandlerInterface::class, $definition->getArgument(9));
                    self::assertReferenceIs(TaskFinishHandlerInterface::class, $definition->getArgument(10));
                },
            ],
        ];

        yield 'with open_swoole_server.use_server_task_messenger' => [
            'config' => [
                'open_swoole_server' => [
                    'use_server_task_messenger' => false,
                    'options' => [
                        'pid_file' => 'open_swoole_server.pid',
                    ],
                ],
            ],
            'expected' => [
                'services' => [
                    Server::class,
                    ContainerSubscriber::class,
                    AMQPReconnectSubscriber::class,
                    'openswoole_bundle.server_task_messenger_handler',
                    'openswoole_bundle.server_task_noop_finish_handler',
                    'openswoole_bundle.server.symfony_request_factory',
                    'openswoole_bundle.server.psr_factory',
                ],
            ],
            'assertServices' => [
                Server::class => static function (Definition $definition) {
                    self::assertSame('0.0.0.0', $definition->getArgument(0));
                    self::assertSame(80, $definition->getArgument(1));
                    self::assertEquals([
                        'enable_coroutine' => true,
                        'pid_file' => 'open_swoole_server.pid',
                        'log_file' => '/proc/self/fd/1',
                        'log_level' => 5,
                        'daemonize' => false,
                        'document_root' => '%kernel.project_dir%/public',
                        'enable_static_handler' => true,
                        'max_request' => 0,
                        'max_request_grace' => 0,
                        'max_request_execution_time' => 0,
                        'max_wait_time' => 3,
                        'open_http2_protocol' => false,
                        'dispatch_mode' => 2,
                        'worker_num' => 4,
                        'task_worker_num' => 1,
                        'task_use_object' => true,
                        'task_enable_coroutine' => false,
                        'reactor_num' => 8,
                        'buffer_output_size' => 8388608,
                        'package_max_length' => 2097152,
                    ], $definition->getArgument(2));
                    self::assertSame(0, $definition->getArgument(3));
                    self::assertReferenceIs('kernel', $definition->getArgument(4));
                    self::assertReferenceIs('logger', $definition->getArgument(5));
                    self::assertTrue($definition->getArgument(6));
                    self::assertReferenceIs('openswoole_bundle.server.symfony_request_factory', $definition->getArgument(7));
                    self::assertReferenceIs('openswoole_bundle.server.psr_factory', $definition->getArgument(8));
                    self::assertNull($definition->getArgument(9));
                    self::assertNull($definition->getArgument(10));
                },
            ],
        ];
    }

    #[DataProvider('providerLoad')]
    public function testLoad(array $config, array $assertServices, array $expected)
    {
        $extension = new OpenSwooleExtension();
        $containerBuilder = new ContainerBuilder();
        $extension->load($config, $containerBuilder);

        foreach ($expected['services'] as $service) {
            $this->assertTrue($containerBuilder->hasDefinition($service), 'Service "' . $service . '" not found');

            if (isset($assertServices[$service])) {
                $assertServices[$service]($containerBuilder->getDefinition($service));
            }
        }
    }

    private static function assertReferenceIs(string $expected, Reference $reference): void
    {
        self::assertEquals($expected, (string) $reference);
    }
}
