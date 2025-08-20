<?php

declare(strict_types=1);

namespace OpenSwooleBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class LoggerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('monolog.logger_prototype')) {
            $container->getDefinition('monolog.logger_prototype')->addMethodCall('useLoggingLoopDetection', [false]);
        }
    }
}
