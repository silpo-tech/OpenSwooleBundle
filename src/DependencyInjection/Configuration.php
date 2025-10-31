<?php

declare(strict_types=1);

namespace OpenSwooleBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    private const DEFAULT_BUFFER_OUTPUT_SIZE = 8388608;

    private const PACKAGE_MAX_LENGTH = 2 * 1024 * 1024;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('open_swoole');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('host')
                    ->defaultValue('0.0.0.0')
                ->end()
                ->integerNode('port')
                    ->defaultValue(80)
                ->end()
                ->scalarNode('hook_flags')
                    ->defaultValue(0)
                ->end()
                ->booleanNode('use_sync_worker')
                    ->defaultValue(true)
                ->end()
                ->booleanNode('use_server_task_messenger')
                    ->defaultValue(true)
                ->end()
                ->arrayNode('options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('enable_coroutine')
                            ->cannotBeEmpty()
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('pid_file')
                            ->cannotBeEmpty()
                            ->defaultValue(getenv('HOME') . '/open_swoole_server.pid')
                        ->end()
                        ->scalarNode('log_file')
                            ->cannotBeEmpty()
                            ->defaultValue('/proc/self/fd/1')
                        ->end()
                        ->scalarNode('log_level')
                            ->cannotBeEmpty()
                            ->defaultValue(5)
                        ->end()
                        ->booleanNode('daemonize')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('document_root')
                            ->cannotBeEmpty()
                            ->defaultValue('%kernel.project_dir%/public')
                        ->end()
                        ->booleanNode('enable_static_handler')
                            ->defaultTrue()
                        ->end()
                        ->variableNode('max_request')
                            ->defaultValue(0)
                        ->end()
                        ->variableNode('max_request_grace')
                            ->defaultValue(0)
                        ->end()
                        ->variableNode('max_request_execution_time')
                            ->defaultValue(0)
                        ->end()
                        ->variableNode('max_wait_time')
                            ->defaultValue(3)
                        ->end()
                        ->variableNode('open_cpu_affinity')->end()
                        ->variableNode('enable_reuse_port')->end()
                        ->variableNode('open_http2_protocol')
                            ->defaultFalse()
                        ->end()
                        ->variableNode('dispatch_mode')
                            ->defaultValue(2)
                        ->end()
                        ->variableNode('worker_num')
                            ->defaultValue(4)
                        ->end()
                        ->variableNode('task_worker_num')
                            ->defaultValue(1)
                        ->end()
                        ->variableNode('task_use_object')
                            ->defaultTrue()
                        ->end()
                        ->variableNode('task_enable_coroutine')
                            ->defaultFalse()
                        ->end()
                        ->variableNode('reactor_num')
                            ->defaultValue(8)
                        ->end()
                        ->variableNode('buffer_output_size')
                            ->defaultValue(self::DEFAULT_BUFFER_OUTPUT_SIZE)
                        ->end()
                        ->variableNode('package_max_length')
                            ->defaultValue(self::PACKAGE_MAX_LENGTH)
                        ->end()
                        ->variableNode('user')->end()
                        ->variableNode('group')->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
